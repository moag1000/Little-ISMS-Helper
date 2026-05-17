<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\ISMSObjective;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.1 — Unit tests for ISMSObjective workflow shape.
 *
 * Tests the entity's status field and its compatibility with the
 * isms_objective_lifecycle state-machine defined in
 * config/workflows/isms_objective.yaml.
 *
 * ISMSObjective uses a domain-specific 5-place model:
 *   not_started → in_progress → achieved | delayed | cancelled
 *
 * Behaviour-level transition tests (which require booting the kernel
 * and hitting guards) are covered by WorkflowDumpTest (smoke) and
 * integration tests in LifecycleControllerTest (HTTP layer).
 *
 * This suite pins the entity shape assumptions the workflow depends on.
 */
final class ISMSObjectiveWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new ISMSObjective();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsInProgress(): void
    {
        // ISMSObjective defaults to 'in_progress' (entity-level default).
        // The workflow YAML initial_marking is 'not_started'.
        // Both are valid workflow places; the entity business default is in_progress.
        $entity = new ISMSObjective();
        $this->assertSame('in_progress', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new ISMSObjective();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllWorkflowPlaces(): void
    {
        $entity = new ISMSObjective();
        $places = ['not_started', 'in_progress', 'achieved', 'delayed', 'cancelled'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entityHasTenantMethodRequiredByTenantGuard(): void
    {
        $entity = new ISMSObjective();
        // TenantGuard checks getTenant() — entity must expose this method
        $this->assertTrue(method_exists($entity, 'getTenant'));
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('isms-objective');
        $this->assertNotNull($entry, "'isms-objective' slug must be registered");
        $this->assertSame(ISMSObjective::class, $entry['class']);
        $this->assertSame('isms_objective_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludeBothNewEntities(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('processing-activity', $slugs);
        $this->assertContains('isms-objective', $slugs);
    }
}
