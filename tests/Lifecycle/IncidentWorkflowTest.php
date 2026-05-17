<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Incident;
use App\Enum\IncidentStatus;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for Incident custom lifecycle (CSIRT chain).
 *
 * Incident uses typed IncidentStatus enum. The workflow marking_store uses the
 * string-bridge property 'statusValue' (getStatusValue/setStatusValue) for
 * Symfony Workflow compatibility, while the entity API (getStatus/setStatus)
 * remains typed.
 *
 * Places (aligned to existing IncidentStatus enum):
 *   reported → in_investigation → in_resolution → resolved → closed
 *   reported → closed (false_positive, reason_required)
 *
 * ISO 27001 A.16 / GDPR Art. 33 — Incident response lifecycle.
 */
final class IncidentWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new Incident();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsReported(): void
    {
        $entity = new Incident();
        $this->assertSame(IncidentStatus::Reported, $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new Incident();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityHasStringBridgeForWorkflowCompatibility(): void
    {
        $entity = new Incident();
        $this->assertTrue(method_exists($entity, 'getStatusValue'));
        $this->assertTrue(method_exists($entity, 'setStatusValue'));
    }

    #[Test]
    public function statusValueBridgeRoundTrips(): void
    {
        $entity = new Incident();
        $statuses = ['reported', 'in_investigation', 'in_resolution', 'resolved', 'closed'];
        foreach ($statuses as $status) {
            $entity->setStatusValue($status);
            $this->assertSame($status, $entity->getStatusValue(), "statusValue bridge should round-trip '$status'");
            $this->assertInstanceOf(IncidentStatus::class, $entity->getStatus());
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('incident');
        $this->assertNotNull($entry, "'incident' slug must be registered in EntityTypeRegistry");
        $this->assertSame(Incident::class, $entry['class']);
        $this->assertSame('incident_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesIncident(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('incident', $slugs);
    }
}
