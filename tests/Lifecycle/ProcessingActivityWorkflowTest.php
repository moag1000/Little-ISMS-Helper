<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\ProcessingActivity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.1 — Unit tests for ProcessingActivity workflow shape.
 *
 * Tests the entity's status field and its compatibility with the
 * processing_activity_lifecycle state-machine defined in
 * config/workflows/processing_activity.yaml.
 *
 * Behaviour-level transition tests (which require booting the kernel
 * and hitting guards) are covered by WorkflowDumpTest (smoke) and
 * integration tests in LifecycleControllerTest (HTTP layer).
 *
 * This suite pins the entity shape assumptions the workflow depends on.
 */
final class ProcessingActivityWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new ProcessingActivity();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function initialStatusMatchesWorkflowInitialMarking(): void
    {
        $entity = new ProcessingActivity();
        // YAML initial_marking: draft — must match entity default
        $this->assertSame('draft', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new ProcessingActivity();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllWorkflowPlaces(): void
    {
        $entity = new ProcessingActivity();
        // All five standard lifecycle places + activate shortcut target
        $places = ['draft', 'in_review', 'approved', 'published', 'archived'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entityHasTenantMethodRequiredByTenantGuard(): void
    {
        $entity = new ProcessingActivity();
        // TenantGuard checks getTenant() — entity must expose this method
        $this->assertTrue(method_exists($entity, 'getTenant'));
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new \App\Lifecycle\EntityTypeRegistry();
        $entry = $registry->lookup('processing-activity');
        $this->assertNotNull($entry, "'processing-activity' slug must be registered");
        $this->assertSame(\App\Entity\ProcessingActivity::class, $entry['class']);
        $this->assertSame('processing_activity_lifecycle', $entry['workflow']);
    }
}
