<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Entity\AuditFinding;
use App\Entity\BCExercise;
use App\Entity\ChangeRequest;
use App\Entity\CorrectiveAction;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\ManagementReview;
use App\Entity\Patch;
use App\Entity\Training;
use App\Entity\User;
use App\Entity\Vulnerability;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * S12: approval and closing evidence is written by the lifecycle step that
 * produces it, never typed into a form. Whatever path applies the transition
 * (lifecycle button, decision dialog, workflow step, four-eyes request), the
 * record gets who and when from the transition itself:
 *
 * - change request (ISO 27001 A.8.32): approve → approval date, approver,
 *   comment (the decision reason); implement → implementation date and
 *   implementer; verify → verifier and date; close → closing date. With four
 *   eyes, the second person is the approver / implementer — once they
 *   confirmed in their own session (S27, counter-sign request).
 * - incident: close / false_positive → closing time.
 * - BC exercise (ISO 22301 8.5): complete → each tested plan counts as tested
 *   on the exercise date ("report completed" is derived by the exercise itself,
 *   BCExercise::deriveReportCompleted()).
 * - internal audit (ISO 27001 9.2.2, S21): report / rework → reporter and time;
 *   approve → approver (the second person) and time, rejection reason cleared;
 *   reject → the reason; close → who closed and when. Stamped only after the
 *   step succeeded, so a refused step leaves no evidence behind.
 * - S22 (wave 9, before typed in a form or an import row):
 *   corrective action (ISO 27001 10.2) complete → actual completion date;
 *   confirm_effective / confirm_ineffective → effectiveness review date;
 *   audit finding verify → nonconformity verified at / by (the auditor);
 *   vulnerability patch / mitigate → remediated date (A.8.8);
 *   patch deploy → deployed date; training complete → completion date (A.6.3).
 * - S28: management review complete (ROLE_TOP_MGMT) → top management attended (ISO 9.3).
 *
 * Tests: tests/Lifecycle/LifecycleEvidenceStamperTest.php; the forms show the
 * fields read-only (tests/Api/ApiEvidenceFieldNamesTest.php).
 */
#[AsEventListener(event: 'workflow.completed', method: 'onCompleted', priority: 60)]
final class LifecycleEvidenceStamper
{
    public function onCompleted(CompletedEvent $event): void
    {
        $subject = $event->getSubject();
        $transition = $event->getTransition()?->getName();
        if ($transition === null) {
            return;
        }
        $context = $event->getContext();
        $now = new DateTimeImmutable();

        match (true) {
            $subject instanceof ChangeRequest => $this->changeRequest($subject, $transition, $context, $now),
            $subject instanceof Incident => $this->incident($subject, $transition, $now),
            $subject instanceof BCExercise => $this->exercise($subject, $transition, $now),
            $subject instanceof InternalAudit => $this->internalAudit($subject, $transition, $context, $now),
            $subject instanceof CorrectiveAction => match ($transition) {
                'complete' => $subject->setActualCompletionDate($now),
                'confirm_effective', 'confirm_ineffective' => $subject->setEffectivenessReviewDate($now),
                default => null,
            },
            $subject instanceof AuditFinding => $transition === 'verify'
                ? $subject->setNcVerifiedAt($now)->setNcVerifiedBy(self::actor($context))
                : null,
            $subject instanceof Vulnerability => in_array($transition, ['patch', 'mitigate'], true)
                ? $subject->setRemediatedDate($now)
                : null,
            $subject instanceof Patch => $transition === 'deploy' ? $subject->setDeployedDate($now) : null,
            // S28 (F-12): completing the review is top management's own step (ROLE_TOP_MGMT).
            $subject instanceof ManagementReview => $transition === 'complete'
                ? $subject->setTopManagementAttended(true)
                : null,
            $subject instanceof Training => $transition === 'complete'
                ? $subject->setCompletionDate(DateTime::createFromImmutable($now))
                : null,
            default => null,
        };
    }

    /** @param array<string, mixed> $context */
    private function changeRequest(ChangeRequest $change, string $transition, array $context, DateTimeImmutable $now): void
    {
        $actor = self::actorName($context);
        $today = DateTime::createFromImmutable($now); // DATE_MUTABLE columns
        switch ($transition) {
            case 'approve':
                $change->setApprovedDate($today)->setApprovedBy($actor);
                $reason = $context['reason'] ?? null;
                if (is_string($reason) && trim($reason) !== '') {
                    $change->setApprovalComments(mb_substr(trim($reason), 0, 65000));
                }
                break;
            case 'implement':
                $change->setActualImplementationDate($today)->setImplementedBy($actor);
                break;
            case 'verify':
                $change->setVerifiedDate($today)->setVerifiedBy($actor);
                break;
            case 'close':
                $change->setClosedDate($today);
                break;
        }
    }

    private function incident(Incident $incident, string $transition, DateTimeImmutable $now): void
    {
        if (in_array($transition, ['close', 'false_positive'], true)) {
            $incident->setClosedAt($now);
        }
    }

    /** @param array<string, mixed> $context */
    private function internalAudit(InternalAudit $audit, string $transition, array $context, DateTimeImmutable $now): void
    {
        $actor = self::actor($context);
        switch ($transition) {
            case 'report':
            case 'rework':
                $audit->setReportedBy($actor)->setReportedAt($now);
                break;
            case 'approve':
                $audit->setApprovedBy($actor)->setApprovedAt($now)->setRejectionReason(null);
                break;
            case 'reject':
                $reason = $context['reason'] ?? null;
                $audit->setRejectionReason(is_string($reason) && trim($reason) !== '' ? trim($reason) : null);
                break;
            case 'close':
                $audit->setClosedBy($actor)->setClosedAt($now);
                break;
            default:
                return;
        }
        $audit->setUpdatedAt($now);
    }

    private function exercise(BCExercise $exercise, string $transition, DateTimeImmutable $now): void
    {
        if ($transition !== 'complete') {
            return;
        }
        $exercise->deriveReportCompleted();
        $tested = $exercise->getExerciseDate() ?? DateTime::createFromImmutable($now);
        foreach ($exercise->getTestedPlans() as $plan) {
            $last = $plan->getLastTested();
            if (!$last instanceof DateTimeInterface || $last < $tested) {
                $plan->setLastTested(DateTime::createFromInterface($tested));
            }
        }
    }

    /**
     * The person who carries the step: with four eyes the second person (the
     * approver) — only when that person confirmed it in their own session
     * (`four_eyes_confirmed`, set by LifecycleService, S27) — otherwise the one
     * who applied it. A second person merely named by somebody else never
     * appears as approver, verifier or closer.
     *
     * @param array<string, mixed> $context
     */
    private static function actor(array $context): ?User
    {
        $approver = $context['four_eyes_approver'] ?? null;
        if ($approver instanceof User && ($context['four_eyes_confirmed'] ?? false) === true) {
            return $approver;
        }
        $user = $context['user'] ?? null;

        return $user instanceof User ? $user : null;
    }

    /**
     * Display name of {@see self::actor()}.
     *
     * @param array<string, mixed> $context
     */
    private static function actorName(array $context): ?string
    {
        $user = self::actor($context);
        if (!$user instanceof User) {
            return null;
        }
        $name = trim($user->getFullName());

        return mb_substr($name !== '' ? $name : (string) $user->getEmail(), 0, 100);
    }
}
