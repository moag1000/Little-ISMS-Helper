<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\WorkflowInstance;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Sprint Y.0 — Unit tests for WorkflowInstance approval-chain state-machine.
 *
 * Verifies:
 *  - Entity has getStatus/setStatus (Symfony Workflow marking-store hook)
 *  - Entity has getLockVersion() for optimistic locking
 *  - Entity has getCurrentStepIndex/setCurrentStepIndex
 *  - Initial status is 'pending' (matches initial_marking in workflow_instance.yaml)
 *  - All 5 places are valid status strings
 *  - 'workflow-instance' slug registered in EntityTypeRegistry
 *
 * config/workflows/workflow_instance.yaml defines the state-machine.
 * Transition correctness is covered by WorkflowServiceLifecycleTest.
 */
final class WorkflowInstanceWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new WorkflowInstance();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsPending(): void
    {
        $entity = new WorkflowInstance();
        $this->assertSame('pending', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new WorkflowInstance();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityHasCurrentStepIndex(): void
    {
        $entity = new WorkflowInstance();
        $this->assertTrue(method_exists($entity, 'getCurrentStepIndex'));
        $this->assertTrue(method_exists($entity, 'setCurrentStepIndex'));
        $this->assertSame(0, $entity->getCurrentStepIndex());
    }

    #[Test]
    public function currentStepIndexSetterRoundTrips(): void
    {
        $entity = new WorkflowInstance();
        $entity->setCurrentStepIndex(3);
        $this->assertSame(3, $entity->getCurrentStepIndex());
    }

    #[Test]
    public function allValidStatusValuesAreAccepted(): void
    {
        $places = ['pending', 'in_progress', 'approved', 'rejected', 'cancelled'];
        $entity = new WorkflowInstance();
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') round-trip failed");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('workflow-instance');
        $this->assertNotNull($entry, "'workflow-instance' slug must be registered in EntityTypeRegistry");
        $this->assertSame(WorkflowInstance::class, $entry['class']);
        $this->assertSame('workflow_instance_lifecycle', $entry['workflow']);
    }
}
