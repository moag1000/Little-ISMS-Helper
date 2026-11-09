<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\ChangeRequest;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\User;
use App\Lifecycle\EventListener\LifecycleEvidenceStamper;
use DateTime;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * S12: approval and closing evidence comes from the lifecycle step, not from
 * the form — the form shows these fields read-only
 * (tests/Api/ApiEvidenceFieldNamesTest.php).
 */
final class LifecycleEvidenceStamperTest extends KernelTestCase
{
    #[Test]
    public function itListensToEveryCompletedTransition(): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $found = false;
        foreach ($dispatcher->getListeners('workflow.completed') as $listener) {
            if (is_array($listener) && $listener[0] instanceof LifecycleEvidenceStamper) {
                $found = true;
            }
        }
        self::assertTrue($found, 'LifecycleEvidenceStamper is not registered for workflow.completed');
    }

    #[Test]
    public function approvalStampsDateApproverAndReason(): void
    {
        $change = new ChangeRequest();
        $this->complete($change, 'approve', ['user' => $this->user('Ina', 'Initiator'), 'four_eyes_approver' => $this->user('Alex', 'Approver'), 'four_eyes_confirmed' => true, 'reason' => ' Passt. ']);

        self::assertNotNull($change->getApprovedDate());
        self::assertSame((new DateTime('today'))->format('Y-m-d'), $change->getApprovedDate()->format('Y-m-d'));
        self::assertSame('Alex Approver', $change->getApprovedBy(), 'with four eyes the second person approves');
        self::assertSame('Passt.', $change->getApprovalComments());
        self::assertSame('Alex Approver', $change->getEffectiveApprovedBy());
    }

    #[Test]
    public function implementVerifyAndCloseStampTheirFields(): void
    {
        $change = new ChangeRequest();
        $user = $this->user('Uwe', 'User');
        $this->complete($change, 'implement', ['user' => $user]);
        self::assertSame('Uwe User', $change->getImplementedBy());
        self::assertNotNull($change->getActualImplementationDate());

        $this->complete($change, 'verify', ['user' => $user]);
        self::assertSame('Uwe User', $change->getVerifiedBy());
        self::assertNotNull($change->getVerifiedDate());
        self::assertNull($change->getClosedDate());

        $this->complete($change, 'close', ['user' => $user]);
        self::assertNotNull($change->getClosedDate());
    }

    #[Test]
    public function otherTransitionsLeaveTheEvidenceAlone(): void
    {
        $change = new ChangeRequest();
        $this->complete($change, 'submit', ['user' => $this->user('A', 'B')]);
        self::assertNull($change->getApprovedDate());
        self::assertNull($change->getApprovedBy());
    }

    #[Test]
    public function closingAnIncidentStampsTheClosingTime(): void
    {
        foreach (['close', 'false_positive'] as $transition) {
            $incident = new Incident();
            $this->complete($incident, $transition, []);
            self::assertNotNull($incident->getClosedAt(), $transition);
        }
        $incident = new Incident();
        $this->complete($incident, 'resolve', []);
        self::assertNull($incident->getClosedAt());
    }

    #[Test]
    public function completingAnExerciseMarksItsPlansTested(): void
    {
        $plan = new BusinessContinuityPlan();
        $plan->setLastTested(new DateTime('2020-01-01'));
        $exercise = new BCExercise();
        $exercise->setExerciseDate(new DateTime('2026-10-01'));
        $exercise->addTestedPlan($plan);

        $this->complete($exercise, 'start', []);
        self::assertSame('2020-01-01', $plan->getLastTested()?->format('Y-m-d'), 'only completion counts');

        $this->complete($exercise, 'complete', []);
        self::assertSame('2026-10-01', $plan->getLastTested()?->format('Y-m-d'));
    }

    #[Test]
    public function aNewerTestIsNotOverwrittenByAnOlderExercise(): void
    {
        $plan = new BusinessContinuityPlan();
        $plan->setLastTested(new DateTime('2026-11-01'));
        $exercise = new BCExercise();
        $exercise->setExerciseDate(new DateTime('2026-03-01'));
        $exercise->addTestedPlan($plan);

        $this->complete($exercise, 'complete', []);
        self::assertSame('2026-11-01', $plan->getLastTested()?->format('Y-m-d'));
    }

    #[Test]
    public function reportCompletedIsDerivedFromCompletionAndReport(): void
    {
        $exercise = new BCExercise();
        $exercise->setReportCompleted(true);
        $exercise->deriveReportCompleted();
        self::assertFalse($exercise->isReportCompleted(), 'a planned exercise has no completed report');

        (new \ReflectionProperty($exercise, 'status'))->setValue($exercise, 'completed');
        $exercise->deriveReportCompleted();
        self::assertFalse($exercise->isReportCompleted(), 'completed but without results and report date');

        $exercise->setResults('Wiederanlauf in 3 h.');
        $exercise->setReportDate(new DateTime('2026-11-02'));
        $exercise->deriveReportCompleted();
        self::assertTrue($exercise->isReportCompleted());
    }

    /** @param array<string, mixed> $context */
    #[Test]
    public function internalAuditStepsStampReporterApproverAndCloser(): void
    {
        // S21: the audit controller no longer writes these before the step runs.
        $audit = new InternalAudit();
        $auditor = $this->user('Ada', 'Auditor');
        $ciso = $this->user('Cleo', 'Ciso');

        $this->complete($audit, 'report', ['user' => $auditor]);
        self::assertSame($auditor, $audit->getReportedBy());
        self::assertNotNull($audit->getReportedAt());

        $this->complete($audit, 'reject', ['user' => $ciso, 'reason' => ' Nachweise fehlen. ']);
        self::assertSame('Nachweise fehlen.', $audit->getRejectionReason());
        self::assertNull($audit->getApprovedBy());

        $this->complete($audit, 'approve', ['user' => $auditor, 'four_eyes_approver' => $ciso, 'four_eyes_confirmed' => true]);
        self::assertSame($ciso, $audit->getApprovedBy(), 'with four eyes the second person approves');
        self::assertNotNull($audit->getApprovedAt());
        self::assertNull($audit->getRejectionReason());

        $this->complete($audit, 'close', ['user' => $ciso]);
        self::assertSame($ciso, $audit->getClosedBy());
        self::assertNotNull($audit->getClosedAt());
    }

    private function complete(object $subject, string $transition, array $context): void
    {
        $workflow = $this->createStub(WorkflowInterface::class);
        (new LifecycleEvidenceStamper())->onCompleted(new CompletedEvent(
            $subject,
            new Marking(['x' => 1]),
            new Transition($transition, ['a'], ['b']),
            $workflow,
            $context,
        ));
    }

    private function user(string $first, string $last): User
    {
        $user = new User();
        $user->setFirstName($first);
        $user->setLastName($last);
        $user->setEmail(strtolower($first) . '@example.test');

        return $user;
    }
}
