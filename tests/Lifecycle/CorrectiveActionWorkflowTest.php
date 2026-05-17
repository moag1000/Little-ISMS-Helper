<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\CorrectiveAction;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for CorrectiveAction custom lifecycle (CAPA).
 *
 * Places: planned → in_progress → completed → verified_effective | verified_ineffective
 *         planned → cancelled (ROLE_MANAGER, reason_required)
 *         verified_ineffective → in_progress (retry, ROLE_MANAGER)
 *
 * ISO 27001 Cl. 10.1 — corrective action and continual improvement.
 */
final class CorrectiveActionWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new CorrectiveAction();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsPlanned(): void
    {
        $entity = new CorrectiveAction();
        $this->assertSame('planned', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new CorrectiveAction();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllSixWorkflowPlaces(): void
    {
        $entity = new CorrectiveAction();
        $places = ['planned', 'in_progress', 'completed', 'verified_effective', 'verified_ineffective', 'cancelled'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('corrective-action');
        $this->assertNotNull($entry, "'corrective-action' slug must be registered in EntityTypeRegistry");
        $this->assertSame(CorrectiveAction::class, $entry['class']);
        $this->assertSame('corrective_action_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesCorrectiveAction(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('corrective-action', $slugs);
    }
}
