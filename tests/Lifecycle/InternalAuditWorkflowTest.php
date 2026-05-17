<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\InternalAudit;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for InternalAudit custom lifecycle.
 *
 * Places: planned → in_progress → reported → approved | rejected → closed
 * Rework: rejected → reported (ROLE_AUDITOR)
 *
 * ISO 27001 Cl. 9.2 — Internal audit programme.
 */
final class InternalAuditWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new InternalAudit();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsPlanned(): void
    {
        $entity = new InternalAudit();
        $this->assertSame('planned', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new InternalAudit();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllSixWorkflowPlaces(): void
    {
        $entity = new InternalAudit();
        $places = ['planned', 'in_progress', 'reported', 'approved', 'rejected', 'closed'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('internal-audit');
        $this->assertNotNull($entry, "'internal-audit' slug must be registered in EntityTypeRegistry");
        $this->assertSame(InternalAudit::class, $entry['class']);
        $this->assertSame('internal_audit_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesInternalAudit(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('internal-audit', $slugs);
    }
}
