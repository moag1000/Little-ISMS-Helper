<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Entity\AuditFinding;
use App\Entity\Control;
use App\Entity\CorrectiveAction;
use App\Entity\User;
use App\Enum\CorrectiveActionStatus;
use App\Lifecycle\LifecycleTransitionInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * A corrective action confirmed effective (ISO 27001 10.2 d–f) finishes what
 * it was for, so nobody has to do it by hand:
 *
 * - its nonconformity is closed once every corrective action on it is
 *   effective or cancelled — the finding walks its own lifecycle up to
 *   `closed`, each step in the audit log;
 * - each linked control counts as tested by this verification
 *   (lastEffectivenessTest) and its next review comes within 30 days, so
 *   the control owner confirms the control with the fix in place
 *   (9.1). The implementation status is never touched — that stays a
 *   decision of the control owner.
 */
#[AsEventListener(event: 'workflow.corrective_action_lifecycle.completed', method: 'onCompleted', priority: 5)]
final class CorrectiveActionEffectiveListener
{
    private const string FINDING_WORKFLOW = 'audit_finding_lifecycle';

    /** Finding status => transition that moves it one step towards `closed`. */
    private const array PATH_TO_CLOSED = [
        'open' => 'start',
        'in_progress' => 'resolve',
        'resolved' => 'verify',
        'verified' => 'close',
    ];

    private const int CONTROL_REVIEW_WITHIN_DAYS = 30;

    public function __construct(
        private readonly LifecycleTransitionInterface $lifecycle,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function onCompleted(CompletedEvent $event): void
    {
        $capa = $event->getSubject();
        if (!$capa instanceof CorrectiveAction || $event->getTransition()?->getName() !== 'confirm_effective') {
            return;
        }
        $user = $event->getContext()['user'] ?? null;
        $user = $user instanceof User ? $user : null;

        $this->reverifyControls($capa);
        $finding = $capa->getFinding();
        if ($finding instanceof AuditFinding && $this->allActionsDone($finding, $capa)) {
            $this->closeFinding($finding, $capa, $user);
        }
    }

    private function reverifyControls(CorrectiveAction $capa): void
    {
        $verifiedAt = $capa->getVerifiedAt() !== null
            ? DateTimeImmutable::createFromInterface($capa->getVerifiedAt())
            : new DateTimeImmutable();
        $reviewBy = (new DateTimeImmutable('today'))->modify(sprintf('+%d days', self::CONTROL_REVIEW_WITHIN_DAYS));

        foreach ($capa->getRelatedControls() as $control) {
            if (!$control instanceof Control) {
                continue;
            }
            $control->setLastEffectivenessTest($verifiedAt);
            $next = $control->getNextReviewDate();
            if ($next === null || $next > $reviewBy) {
                $control->setNextReviewDate($reviewBy);
            }
        }
        if (!$capa->getRelatedControls()->isEmpty()) {
            $this->entityManager->flush();
        }
    }

    private function allActionsDone(AuditFinding $finding, CorrectiveAction $current): bool
    {
        foreach ($finding->getCorrectiveActions() as $action) {
            if ($action === $current) {
                continue;
            }
            if (!in_array($action->getStatus(), [
                CorrectiveAction::STATUS_VERIFIED_EFFECTIVE,
                CorrectiveActionStatus::Cancelled->value,
            ], true)) {
                return false;
            }
        }

        return true;
    }

    private function closeFinding(AuditFinding $finding, CorrectiveAction $capa, ?User $user): void
    {
        $reason = sprintf('Corrective action #%d confirmed effective (ISO 27001 10.2)', (int) $capa->getId());
        try {
            while (isset(self::PATH_TO_CLOSED[$finding->getStatus()])) {
                $this->lifecycle->transition(
                    $finding,
                    self::FINDING_WORKFLOW,
                    self::PATH_TO_CLOSED[$finding->getStatus()],
                    $user,
                    $reason,
                    automatic: true,
                );
            }
        } catch (\Throwable $e) {
            // The verdict on the corrective action stands; the finding stays
            // where it got to and can be closed on its page.
            $this->logger->warning('Closing the finding after an effective corrective action failed', [
                'finding_id' => $finding->getId(),
                'capa_id' => $capa->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
