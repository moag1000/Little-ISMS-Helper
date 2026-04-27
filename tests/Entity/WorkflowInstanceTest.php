<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\WorkflowInstance;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WorkflowInstanceTest extends TestCase
{
    #[Test]
    public function testNewWorkflowInstanceHasDefaultValues(): void
    {
        $instance = new WorkflowInstance();

        $this->assertNull($instance->getId());
        $this->assertNull($instance->getWorkflow());
        $this->assertNull($instance->getEntityType());
        $this->assertNull($instance->getEntityId());
        $this->assertEquals('pending', $instance->getStatus());
        $this->assertNull($instance->getInitiatedBy());
        $this->assertNull($instance->getCurrentStep());
        $this->assertEquals([], $instance->getCompletedSteps());
        $this->assertEquals([], $instance->getApprovalHistory());
        $this->assertNull($instance->getComments());
        $this->assertInstanceOf(\DateTimeImmutable::class, $instance->getStartedAt());
        $this->assertNull($instance->getCompletedAt());
        $this->assertNull($instance->getDueDate());
    }

    #[Test]
    public function testSetAndGetWorkflow(): void
    {
        $instance = new WorkflowInstance();
        $workflow = new Workflow();
        $workflow->setName('Approval Workflow');

        $instance->setWorkflow($workflow);

        $this->assertSame($workflow, $instance->getWorkflow());
    }

    #[Test]
    public function testSetAndGetEntityType(): void
    {
        $instance = new WorkflowInstance();
        $instance->setEntityType('App\Entity\Risk');

        $this->assertEquals('App\Entity\Risk', $instance->getEntityType());
    }

    #[Test]
    public function testSetAndGetEntityId(): void
    {
        $instance = new WorkflowInstance();
        $instance->setEntityId(123);

        $this->assertEquals(123, $instance->getEntityId());
    }

    #[Test]
    public function testSetAndGetStatus(): void
    {
        $instance = new WorkflowInstance();
        $instance->setStatus('in_progress');

        $this->assertEquals('in_progress', $instance->getStatus());
    }

    #[Test]
    public function testSetAndGetInitiatedBy(): void
    {
        $instance = new WorkflowInstance();
        $user = new User();
        $user->setEmail('initiator@example.com');

        $instance->setInitiatedBy($user);

        $this->assertSame($user, $instance->getInitiatedBy());
    }

    #[Test]
    public function testSetAndGetCurrentStep(): void
    {
        $instance = new WorkflowInstance();
        $step = new WorkflowStep();
        $step->setName('Manager Approval');

        $instance->setCurrentStep($step);

        $this->assertSame($step, $instance->getCurrentStep());
    }

    #[Test]
    public function testSetAndGetCompletedSteps(): void
    {
        $instance = new WorkflowInstance();
        $steps = [1, 2, 3];

        $instance->setCompletedSteps($steps);

        $this->assertEquals($steps, $instance->getCompletedSteps());
    }

    #[Test]
    public function testAddCompletedStep(): void
    {
        $instance = new WorkflowInstance();

        $this->assertCount(0, $instance->getCompletedSteps());

        $instance->addCompletedStep(1);
        $this->assertCount(1, $instance->getCompletedSteps());
        $this->assertContains(1, $instance->getCompletedSteps());

        $instance->addCompletedStep(2);
        $this->assertCount(2, $instance->getCompletedSteps());
        $this->assertContains(2, $instance->getCompletedSteps());
    }

    #[Test]
    public function testAddCompletedStepDoesNotDuplicate(): void
    {
        $instance = new WorkflowInstance();

        $instance->addCompletedStep(1);
        $instance->addCompletedStep(1); // Add same step again

        $this->assertCount(1, $instance->getCompletedSteps());
    }

    #[Test]
    public function testSetAndGetApprovalHistory(): void
    {
        $instance = new WorkflowInstance();
        $history = [
            ['step' => 1, 'approved' => true, 'timestamp' => '2024-01-01'],
            ['step' => 2, 'approved' => false, 'timestamp' => '2024-01-02']
        ];

        $instance->setApprovalHistory($history);

        $this->assertEquals($history, $instance->getApprovalHistory());
    }

    #[Test]
    public function testAddApprovalHistoryEntry(): void
    {
        $instance = new WorkflowInstance();

        $entry1 = ['step' => 1, 'approved' => true, 'timestamp' => '2024-01-01'];
        $entry2 = ['step' => 2, 'approved' => false, 'timestamp' => '2024-01-02'];

        $instance->addApprovalHistoryEntry($entry1);
        $this->assertCount(1, $instance->getApprovalHistory());

        $instance->addApprovalHistoryEntry($entry2);
        $this->assertCount(2, $instance->getApprovalHistory());
        $this->assertEquals($entry2, $instance->getApprovalHistory()[1]);
    }

    #[Test]
    public function testSetAndGetComments(): void
    {
        $instance = new WorkflowInstance();
        $instance->setComments('Needs additional review');

        $this->assertEquals('Needs additional review', $instance->getComments());
    }

    #[Test]
    public function testSetAndGetStartedAt(): void
    {
        $instance = new WorkflowInstance();
        $date = new \DateTimeImmutable('2024-01-01 10:00:00');

        $instance->setStartedAt($date);

        $this->assertEquals($date, $instance->getStartedAt());
    }

    #[Test]
    public function testSetAndGetCompletedAt(): void
    {
        $instance = new WorkflowInstance();
        $date = new \DateTimeImmutable('2024-01-02 15:00:00');

        $instance->setCompletedAt($date);

        $this->assertEquals($date, $instance->getCompletedAt());
    }

    #[Test]
    public function testSetAndGetDueDate(): void
    {
        $instance = new WorkflowInstance();
        $date = new \DateTimeImmutable('2024-01-10 23:59:59');

        $instance->setDueDate($date);

        $this->assertEquals($date, $instance->getDueDate());
    }

    #[Test]
    public function testIsOverdueReturnsFalseWhenNoDueDate(): void
    {
        $instance = new WorkflowInstance();

        $this->assertFalse($instance->isOverdue());
    }

    #[Test]
    public function testIsOverdueReturnsTrueWhenPastDueAndNotCompleted(): void
    {
        $instance = new WorkflowInstance();
        $pastDate = new \DateTimeImmutable('-1 day');
        $instance->setDueDate($pastDate);

        $this->assertTrue($instance->isOverdue());
    }

    #[Test]
    public function testIsOverdueReturnsFalseWhenPastDueButCompleted(): void
    {
        $instance = new WorkflowInstance();
        $pastDate = new \DateTimeImmutable('-1 day');
        $instance->setDueDate($pastDate);
        $instance->setCompletedAt(new \DateTimeImmutable());

        $this->assertFalse($instance->isOverdue());
    }

    #[Test]
    public function testIsOverdueReturnsFalseWhenDueDateInFuture(): void
    {
        $instance = new WorkflowInstance();
        $futureDate = new \DateTimeImmutable('+1 day');
        $instance->setDueDate($futureDate);

        $this->assertFalse($instance->isOverdue());
    }

    #[Test]
    public function testGetProgressPercentageReturnsZeroWhenNoSteps(): void
    {
        $instance = new WorkflowInstance();
        $workflow = new Workflow();
        $instance->setWorkflow($workflow);

        $this->assertEquals(0, $instance->getProgressPercentage());
    }

    #[Test]
    public function testGetProgressPercentageCalculatesCorrectly(): void
    {
        $instance = new WorkflowInstance();
        $workflow = new Workflow();

        // Add 4 steps to workflow
        $step1 = new WorkflowStep();
        $step1->setName('Step 1')->setStepOrder(1);
        $step2 = new WorkflowStep();
        $step2->setName('Step 2')->setStepOrder(2);
        $step3 = new WorkflowStep();
        $step3->setName('Step 3')->setStepOrder(3);
        $step4 = new WorkflowStep();
        $step4->setName('Step 4')->setStepOrder(4);

        $workflow->addStep($step1);
        $workflow->addStep($step2);
        $workflow->addStep($step3);
        $workflow->addStep($step4);

        $instance->setWorkflow($workflow);

        // Complete 2 out of 4 steps (50%)
        $instance->setCompletedSteps([1, 2]);

        $this->assertEquals(50, $instance->getProgressPercentage());
    }

    #[Test]
    public function testGetProgressPercentageReturns100WhenAllStepsCompleted(): void
    {
        $instance = new WorkflowInstance();
        $workflow = new Workflow();

        // Add 3 steps to workflow
        $step1 = new WorkflowStep();
        $step1->setName('Step 1')->setStepOrder(1);
        $step2 = new WorkflowStep();
        $step2->setName('Step 2')->setStepOrder(2);
        $step3 = new WorkflowStep();
        $step3->setName('Step 3')->setStepOrder(3);

        $workflow->addStep($step1);
        $workflow->addStep($step2);
        $workflow->addStep($step3);

        $instance->setWorkflow($workflow);

        // Complete all 3 steps
        $instance->setCompletedSteps([1, 2, 3]);

        $this->assertEquals(100, $instance->getProgressPercentage());
    }
}
