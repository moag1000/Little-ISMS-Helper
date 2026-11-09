<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Entity\BCExercise;
use App\Entity\ChangeRequest;
use App\Entity\Incident;
use App\Entity\User;
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
 *   eyes, the second person is the approver / implementer.
 * - incident: close / false_positive → closing time.
 * - BC exercise (ISO 22301 8.5): complete → each tested plan counts as tested
 *   on the exercise date ("report completed" is derived by the exercise itself,
 *   BCExercise::deriveReportCompleted()).
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
     * approver), otherwise the one who applied it.
     *
     * @param array<string, mixed> $context
     */
    private static function actorName(array $context): ?string
    {
        $approver = $context['four_eyes_approver'] ?? null;
        $user = $approver instanceof User ? $approver : ($context['user'] ?? null);
        if (!$user instanceof User) {
            return null;
        }
        $name = trim($user->getFullName());

        return mb_substr($name !== '' ? $name : (string) $user->getEmail(), 0, 100);
    }
}
