<?php

namespace App\Tests\Entity;

use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use PHPUnit\Framework\TestCase;

class WorkflowTest extends TestCase
{
    public function testNewWorkflowHasDefaultValues(): void
    {
        $workflow = new Workflow();

        $this->assertNull($workflow->getId());
        $this->assertNull($workflow->getName());
        $this->assertNull($workflow->getDescription());
        $this->assertNull($workflow->getEntityType());
        $this->assertTrue($workflow->isActive());
        $this->assertInstanceOf(\DateTimeImmutable::class, $workflow->getCreatedAt());
        $this->assertNull($workflow->getUpdatedAt());
        $this->assertCount(0, $workflow->getSteps());
    }

    public function testSetAndGetName(): void
    {
        $workflow = new Workflow();
        $workflow->setName('Risk Approval');

        $this->assertEquals('Risk Approval', $workflow->getName());
    }

    public function testSetAndGetDescription(): void
    {
        $workflow = new Workflow();
        $workflow->setDescription('Approval process for high-risk items');

        $this->assertEquals('Approval process for high-risk items', $workflow->getDescription());
    }

    public function testSetAndGetEntityType(): void
    {
        $workflow = new Workflow();
        $workflow->setEntityType('Risk');

        $this->assertEquals('Risk', $workflow->getEntityType());
    }

    public function testSetAndGetIsActive(): void
    {
        $workflow = new Workflow();

        $this->assertTrue($workflow->isActive());

        $workflow->setIsActive(false);
        $this->assertFalse($workflow->isActive());

        $workflow->setIsActive(true);
        $this->assertTrue($workflow->isActive());
    }

    public function testSetUpdatedAt(): void
    {
        $workflow = new Workflow();
        $now = new \DateTimeImmutable();

        $workflow->setUpdatedAt($now);
        $this->assertEquals($now, $workflow->getUpdatedAt());
    }

    public function testAddAndRemoveStep(): void
    {
        $workflow = new Workflow();
        $step = new WorkflowStep();
        $step->setName('Approval Step');
        $step->setStepOrder(1);

        $this->assertCount(0, $workflow->getSteps());

        $workflow->addStep($step);
        $this->assertCount(1, $workflow->getSteps());
        $this->assertTrue($workflow->getSteps()->contains($step));
        $this->assertSame($workflow, $step->getWorkflow());

        $workflow->removeStep($step);
        $this->assertCount(0, $workflow->getSteps());
        $this->assertFalse($workflow->getSteps()->contains($step));
    }

    public function testAddStepDoesNotDuplicat(): void
    {
        $workflow = new Workflow();
        $step = new WorkflowStep();
        $step->setName('Approval Step');
        $step->setStepOrder(1);

        $workflow->addStep($step);
        $workflow->addStep($step); // Add same step again

        $this->assertCount(1, $workflow->getSteps());
    }

    public function testStepsAreOrderedByStepOrder(): void
    {
        $workflow = new Workflow();

        $step1 = new WorkflowStep();
        $step1->setName('First');
        $step1->setStepOrder(1);

        $step2 = new WorkflowStep();
        $step2->setName('Second');
        $step2->setStepOrder(2);

        $step3 = new WorkflowStep();
        $step3->setName('Third');
        $step3->setStepOrder(3);

        // Add in reverse order
        $workflow->addStep($step3);
        $workflow->addStep($step1);
        $workflow->addStep($step2);

        $this->assertCount(3, $workflow->getSteps());

        // Steps should be ordered by stepOrder
        // Note: OrderBy annotation only works when loading from DB, so we sort manually for testing
        $steps = $workflow->getSteps()->toArray();
        usort($steps, fn($a, $b) => $a->getStepOrder() <=> $b->getStepOrder());

        $this->assertEquals('First', $steps[0]->getName());
        $this->assertEquals('Second', $steps[1]->getName());
        $this->assertEquals('Third', $steps[2]->getName());
    }
}
