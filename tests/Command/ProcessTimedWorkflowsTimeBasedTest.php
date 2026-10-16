<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessTimedWorkflowsCommand;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Enum\WorkflowInstanceStatus;
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

/**
 * Time-based steps (`autoProgressConditions.type = time_based`) used to be
 * handed to the deprecated WorkflowAutoProgressionService, which has no
 * time_based branch — the command reported them as progressed while nothing
 * moved. They are now approved through WorkflowService; the last step is
 * never approved automatically (final approval is four-eyes).
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
            ->with($instance, $this->initiator, 'Step automatically approved after time delay of 1 hours')
            ->willReturn($second);
        $this->workflowService->expects(self::once())->method('handleStepAssignment')->with($instance, $second);

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

    #[Test]
    public function lastStepIsNeverApprovedAutomatically(): void
    {
        [$instance, , $second] = $this->instance(null, new DateTimeImmutable('-5 days'));
        $second->setMetadata(['autoProgressConditions' => ['type' => 'time_based', 'delay' => '1 hours']]);
        $instance->setCurrentStep($second);

        $this->workflowService->expects(self::never())->method('moveToNextStep');

        $tester = $this->runCommand($instance);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $instance->getApprovalHistory());
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

    private function runCommand(WorkflowInstance $instance): CommandTester
    {
        $repository = $this->createStub(WorkflowInstanceRepository::class);
        $repository->method('findBy')->willReturn([$instance]);

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

        $tester = new CommandTester(new ProcessTimedWorkflowsCommand($repository, $this->workflowService, $em, $watcher));
        $tester->execute([]);

        return $tester;
    }
}
