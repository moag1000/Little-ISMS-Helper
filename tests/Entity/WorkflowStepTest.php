<?php

namespace App\Tests\Entity;

use App\Entity\WorkflowStep;
use App\Entity\Workflow;
use PHPUnit\Framework\TestCase;

class WorkflowStepTest extends TestCase
{
    public function testNewWorkflowStepHasDefaultValues(): void
    {
        $step = new WorkflowStep();

        $this->assertNull($step->getId());
        $this->assertNull($step->getWorkflow());
        $this->assertNull($step->getName());
        $this->assertNull($step->getDescription());
        $this->assertEquals(0, $step->getStepOrder());
        $this->assertEquals('approval', $step->getStepType());
        $this->assertNull($step->getApproverRole());
        $this->assertNull($step->getApproverUsers());
        $this->assertTrue($step->isRequired());
        $this->assertNull($step->getDaysToComplete());
    }

    public function testSetAndGetWorkflow(): void
    {
        $step = new WorkflowStep();
        $workflow = new Workflow();
        $workflow->setName('Approval Workflow');

        $step->setWorkflow($workflow);

        $this->assertSame($workflow, $step->getWorkflow());
    }

    public function testSetAndGetName(): void
    {
        $step = new WorkflowStep();
        $step->setName('Manager Approval');

        $this->assertEquals('Manager Approval', $step->getName());
    }

    public function testSetAndGetDescription(): void
    {
        $step = new WorkflowStep();
        $step->setDescription('Requires manager approval for budget over 10k');

        $this->assertEquals('Requires manager approval for budget over 10k', $step->getDescription());
    }

    public function testSetAndGetStepOrder(): void
    {
        $step = new WorkflowStep();
        $step->setStepOrder(5);

        $this->assertEquals(5, $step->getStepOrder());
    }

    public function testSetAndGetStepType(): void
    {
        $step = new WorkflowStep();
        $step->setStepType('notification');

        $this->assertEquals('notification', $step->getStepType());
    }

    public function testSetAndGetApproverRole(): void
    {
        $step = new WorkflowStep();
        $step->setApproverRole('ROLE_MANAGER');

        $this->assertEquals('ROLE_MANAGER', $step->getApproverRole());
    }

    public function testSetAndGetApproverUsers(): void
    {
        $step = new WorkflowStep();
        $users = [1, 2, 3];

        $step->setApproverUsers($users);

        $this->assertEquals($users, $step->getApproverUsers());
    }

    public function testSetAndGetIsRequired(): void
    {
        $step = new WorkflowStep();

        $this->assertTrue($step->isRequired());

        $step->setIsRequired(false);
        $this->assertFalse($step->isRequired());

        $step->setIsRequired(true);
        $this->assertTrue($step->isRequired());
    }

    public function testSetAndGetDaysToComplete(): void
    {
        $step = new WorkflowStep();
        $step->setDaysToComplete(5);

        $this->assertEquals(5, $step->getDaysToComplete());
    }

    public function testWorkflowStepTypesCanBeChanged(): void
    {
        $step = new WorkflowStep();

        $step->setStepType('approval');
        $this->assertEquals('approval', $step->getStepType());

        $step->setStepType('notification');
        $this->assertEquals('notification', $step->getStepType());

        $step->setStepType('auto_action');
        $this->assertEquals('auto_action', $step->getStepType());
    }

    public function testWorkflowStepCanHaveMultipleApproverUsers(): void
    {
        $step = new WorkflowStep();
        $users = [10, 20, 30, 40, 50];

        $step->setApproverUsers($users);

        $this->assertCount(5, $step->getApproverUsers());
        $this->assertEquals($users, $step->getApproverUsers());
    }
}
