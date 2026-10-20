<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessTimedWorkflowsCommand;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Enum\WorkflowInstanceStatus;
use App\Lifecycle\InvalidTransitionException;
use App\Repository\Notification\NotificationRuleRepository;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use App\Repository\TenantRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\AuditLogger;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\SlaDeadlineWatcher;
use App\Service\WorkflowService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Workflow\Exception\NotEnabledTransitionException;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\TransitionBlocker;
use Symfony\Component\Workflow\TransitionBlockerList;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Time-based steps (`autoProgressConditions.type = time_based`) used to be
 * handed to the deprecated WorkflowAutoProgressionService, which has no
 * time_based branch — the command reported them as progressed while nothing
 * moved. They are now approved through WorkflowService as an automatic
 * actor; an approval that would close the workflow through the four-eyes
 * `approve` is refused and reported as a notice, not as an error.
 */
final class ProcessTimedWorkflowsTimeBasedTest extends TestCase
{
    private WorkflowService&MockObject $workflowService;
    private User $initiator;

    protected function setUp(): void
    {
        $this->workflowService = $this->createMock(WorkflowService::class);
        $this->initiator = (new User())->setEmail('init@x.test')->setFirstName('I')->setLastName('N');
    }

    #[Test]
    public function elapsedTimeBasedStepIsApprovedAndNextStepActivated(): void
    {
        [$instance, $first, $second] = $this->instance('1 hours', new DateTimeImmutable('-2 hours'));

        $this->workflowService->expects(self::once())->method('moveToNextStep')
            ->with($instance, $this->initiator, 'Step automatically approved after time delay of 1 hours', null, true)
            ->willReturn($second);
        $this->workflowService->expects(self::once())->method('handleStepAssignment')
            ->with($instance, $second, null, null, null, true);

        $tester = $this->runCommand($instance);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $entry = $instance->getApprovalHistory()[0];
        self::assertSame('auto_approved', $entry['action']);
        self::assertSame($first->getId(), $entry['step_id']);
        self::assertSame('time_based', $entry['trigger']);
        self::assertContains($first->getId(), $instance->getCompletedSteps());
    }

    #[Test]
    public function stepIsNotApprovedBeforeTheDelayElapsed(): void
    {
        [$instance] = $this->instance('3 hours', new DateTimeImmutable('-2 hours'));

        $this->workflowService->expects(self::never())->method('moveToNextStep');

        $this->runCommand($instance);
        self::assertSame([], $instance->getApprovalHistory());
    }

    #[Test]
    public function delayCountsFromTheApprovalOfThePreviousStep(): void
    {
        [$instance, $first, $second] = $this->instance(null, new DateTimeImmutable('-5 days'));
        $second->setMetadata(['autoProgressConditions' => ['type' => 'time_based', 'delay' => '2 hours']]);
        $third = $this->step($instance->getWorkflow(), 3, 3);
        $instance->setCurrentStep($second);
        $instance->addApprovalHistoryEntry([
            'step_id' => $first->getId(),
            'action' => 'approved',
            'timestamp' => new DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s'),
        ]);

        // Workflow started 5 days ago, but step 2 is active for 1 hour only.
        $this->workflowService->expects(self::never())->method('moveToNextStep');

        $this->runCommand($instance);
        self::assertNotNull($third);
    }

    /**
     * The four-eyes decision is FourEyesValidator's (via
     * WorkflowService::assertAutomaticApprovalAllowed); a refusal leaves the
     * step pending and is a notice, not an error.
     */
    #[Test]
    public function stepWhoseApprovalWouldCloseTheWorkflowStaysPendingAsNotice(): void
    {
        [$instance, , $second] = $this->instance(null, new DateTimeImmutable('-5 days'));
        $second->setMetadata(['autoProgressConditions' => ['type' => 'time_based', 'delay' => '1 hours']]);
        $instance->setCurrentStep($second);

        $this->workflowService->expects(self::once())->method('assertAutomaticApprovalAllowed')
            ->with($instance, $second)
            ->willThrowException(\App\Lifecycle\Exception\FourEyesRequiredException::automaticActor('workflow_instance_lifecycle', 'approve'));
        $this->workflowService->expects(self::never())->method('moveToNextStep');

        $tester = $this->runCommand($instance);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $instance->getApprovalHistory());
        self::assertSame($second, $instance->getCurrentStep());
        $display = $tester->getDisplay();
        self::assertStringContainsString('needs a human approver', $display);
        self::assertStringNotContainsString('Errors encountered', $display);
    }

    /**
     * In the cron there is no tenant context, so TenantGuard blocks the
     * closing `approve` (e.g. when a tenant override turned four-eyes off).
     * That is the same waiting state as a pending four-eyes approval: a
     * notice, exit 0, nothing written — not an error repeated every run.
     */
    #[Test]
    public function closeBlockedByALifecycleGuardIsANotice(): void
    {
        [$instance, $first] = $this->instance('1 hours', new DateTimeImmutable('-2 hours'));
        $this->workflowService->expects(self::once())->method('moveToNextStep')
            ->willThrowException($this->notEnabled(new TransitionBlocker('Lifecycle transition requires tenant context.', 'tenant')));

        $tester = $this->runCommand($instance);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('needs a human approver', $tester->getDisplay());
        // The guard's own message, not the four-eyes text (review M-2).
        self::assertStringContainsString('blocked: Lifecycle transition requires tenant context.', $tester->getDisplay());
        self::assertSame([], $instance->getApprovalHistory());
        self::assertSame($first, $instance->getCurrentStep());
    }

    /**
     * The cron pins each instance's organisation in TenantContext before
     * processing it (review M-2), so lifecycle guards and tenant-scoped
     * reads see the right organisation; it is cleared afterwards.
     */
    #[Test]
    public function eachInstanceIsProcessedInItsOwnTenantContext(): void
    {
        [$a] = $this->instance('3 hours', new DateTimeImmutable('-1 hours'));
        [$b] = $this->instance('3 hours', new DateTimeImmutable('-1 hours'));
        $tenantA = new \App\Entity\Tenant();
        $tenantB = new \App\Entity\Tenant();
        $a->setTenant($tenantA);
        $b->setTenant($tenantB);

        $seen = [];
        $context = $this->createMock(\App\Service\TenantContext::class);
        $context->expects(self::atLeast(3))->method('setCurrentTenant')->willReturnCallback(
            static function (?\App\Entity\Tenant $t) use (&$seen): void {
                $seen[] = $t;
            },
        );
        $this->workflowService->expects(self::never())->method('moveToNextStep');

        $this->runCommandWith([$a, $b], $context);

        self::assertSame([$tenantA, $tenantB, null], $seen);
    }

    #[Test]
    public function transitionNotEnabledByTheMarkingIsStillAnError(): void
    {
        [$instance] = $this->instance('1 hours', new DateTimeImmutable('-2 hours'));
        $this->workflowService->expects(self::once())->method('moveToNextStep')
            ->willThrowException($this->notEnabled(TransitionBlocker::createBlockedByMarking(new Marking())));

        self::assertSame(Command::FAILURE, $this->runCommand($instance)->getStatusCode());
    }

    private function notEnabled(TransitionBlocker $blocker): InvalidTransitionException
    {
        $workflow = $this->createStub(WorkflowInterface::class);
        $workflow->method('getName')->willReturn('workflow_instance_lifecycle');

        return new InvalidTransitionException(
            message: 'Transition "approve" not enabled.',
            entityClass: WorkflowInstance::class,
            fromStatus: 'in_progress',
            toStatus: '<unknown>',
            previous: new NotEnabledTransitionException(new \stdClass(), 'approve', $workflow, new TransitionBlockerList([$blocker])),
        );
    }

    #[Test]
    public function otherFailuresAreStillReportedAsErrors(): void
    {
        [$instance] = $this->instance('1 hours', new DateTimeImmutable('-2 hours'));
        $this->workflowService->expects(self::once())->method('moveToNextStep')->willThrowException(new \RuntimeException('boom'));

        $tester = $this->runCommand($instance);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    /**
     * A refused closing approval (e.g. the next notification step completes
     * the workflow and its four-eyes `approve` has no approver) must not
     * leave the first instance's history / completed step / step advance in
     * memory — the next instance's flush would persist them.
     */
    #[Test]
    public function refusedInstanceIsRolledBackBeforeTheNextInstanceIsProcessed(): void
    {
        [$refused, $refusedFirst, $refusedSecond] = $this->instance('1 hours', new DateTimeImmutable('-2 hours'));
        [$ok, , $okSecond] = $this->instance('1 hours', new DateTimeImmutable('-2 hours'));

        $this->workflowService->expects(self::exactly(2))->method('moveToNextStep')->willReturnCallback(
            static function (WorkflowInstance $i) use ($refused, $refusedSecond, $okSecond): WorkflowStep {
                $next = $i === $refused ? $refusedSecond : $okSecond;
                $i->setCurrentStep($next);
                $i->setCurrentStepIndex(1);

                return $next;
            },
        );
        $this->workflowService->expects(self::exactly(2))->method('handleStepAssignment')->willReturnCallback(
            static function (WorkflowInstance $i) use ($refused): void {
                if ($i === $refused) {
                    throw \App\Lifecycle\Exception\FourEyesRequiredException::missing('workflow_instance_lifecycle', 'approve');
                }
            },
        );

        $tester = $this->runCommand($refused, $ok);

        // A four-eyes refusal is the expected waiting state: notice, exit 0.
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('needs a human approver', $tester->getDisplay());
        self::assertSame([], $refused->getApprovalHistory(), 'no history for the refused instance');
        self::assertNotContains($refusedFirst->getId(), $refused->getCompletedSteps() ?? []);
        self::assertSame($refusedFirst, $refused->getCurrentStep());
        self::assertSame(0, $refused->getCurrentStepIndex());

        self::assertSame('auto_approved', $ok->getApprovalHistory()[0]['action']);
        self::assertSame($okSecond, $ok->getCurrentStep());
    }

    /** @return array{WorkflowInstance, WorkflowStep, WorkflowStep} */
    private function instance(?string $firstDelay, DateTimeImmutable $startedAt): array
    {
        $workflow = (new Workflow())->setName('Timed')->setEntityType('Risk');
        $first = $this->step($workflow, 1, 1);
        if ($firstDelay !== null) {
            $first->setMetadata(['autoProgressConditions' => ['type' => 'time_based', 'delay' => $firstDelay]]);
        }
        $second = $this->step($workflow, 2, 2);

        $instance = new WorkflowInstance();
        $instance->setWorkflow($workflow);
        $instance->setEntityType('Risk');
        $instance->setEntityId(1);
        $instance->setStatus(WorkflowInstanceStatus::InProgress->value);
        $instance->setInitiatedBy($this->initiator);
        $instance->setCurrentStep($first);
        $instance->setStartedAt($startedAt);
        $instance->setApprovalHistory([]);

        return [$instance, $first, $second];
    }

    private function step(Workflow $workflow, int $id, int $order): WorkflowStep
    {
        $step = (new WorkflowStep())->setName('Step ' . $id)->setStepOrder($order);
        (new \ReflectionProperty(WorkflowStep::class, 'id'))->setValue($step, $id);
        $workflow->addStep($step);

        return $step;
    }

    private function runCommand(WorkflowInstance $first, WorkflowInstance ...$more): CommandTester
    {
        return $this->runCommandWith([$first, ...$more]);
    }

    /**
     * @param list<WorkflowInstance> $instances
     */
    private function runCommandWith(array $instances, ?\App\Service\TenantContext $tenantContext = null): CommandTester
    {
        $repository = $this->createStub(WorkflowInstanceRepository::class);
        $repository->method('findBy')->willReturn($instances);

        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('findActive')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $watcher = new SlaDeadlineWatcher(
            $tenants,
            $this->createStub(SlaDeadlineMonitorRepository::class),
            $this->createStub(NotificationRuleRepository::class),
            $this->createStub(NotificationDispatcher::class),
            $em,
            $this->createStub(AuditLogger::class),
            new NullLogger(),
        );

        $tester = new CommandTester(new ProcessTimedWorkflowsCommand($repository, $this->workflowService, $em, $watcher, $tenantContext));
        $tester->execute([]);

        return $tester;
    }
}
