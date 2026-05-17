<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\AuditFinding;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for AuditFinding custom lifecycle state-machine.
 *
 * Places: open → in_progress → resolved → verified → closed
 * Reopen: verified → in_progress (ROLE_AUDITOR, reason_required)
 *
 * ISO 27001 Clause 10.1 — nonconformity and corrective action.
 */
final class AuditFindingWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new AuditFinding();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsOpen(): void
    {
        $entity = new AuditFinding();
        $this->assertSame('open', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new AuditFinding();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllFiveWorkflowPlaces(): void
    {
        $entity = new AuditFinding();
        $places = ['open', 'in_progress', 'resolved', 'verified', 'closed'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('audit-finding');
        $this->assertNotNull($entry, "'audit-finding' slug must be registered in EntityTypeRegistry");
        $this->assertSame(AuditFinding::class, $entry['class']);
        $this->assertSame('audit_finding_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesAuditFinding(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('audit-finding', $slugs);
    }
}
