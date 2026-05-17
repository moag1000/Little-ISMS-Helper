<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Risk;
use App\Enum\RiskStatus;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for Risk custom lifecycle.
 *
 * Risk uses typed RiskStatus enum. The workflow marking_store uses the
 * string-bridge property 'statusValue' (getStatusValue/setStatusValue) for
 * Symfony Workflow compatibility, while the entity API (getStatus/setStatus)
 * remains typed.
 *
 * Places: identified → assessed → in_treatment → treated → monitored → closed
 *         assessed → accepted (four_eyes, ROLE_CISO+ROLE_RISK_MANAGER)
 *         closed → in_treatment (reopen, reason_required)
 *
 * ISO 27001 Cl. 6.1 / ISO 27005 — Risk treatment lifecycle.
 */
final class RiskWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new Risk();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsIdentified(): void
    {
        $entity = new Risk();
        $this->assertSame(RiskStatus::Identified, $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new Risk();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityHasStringBridgeForWorkflowCompatibility(): void
    {
        $entity = new Risk();
        $this->assertTrue(method_exists($entity, 'getStatusValue'));
        $this->assertTrue(method_exists($entity, 'setStatusValue'));
    }

    #[Test]
    public function statusValueBridgeRoundTrips(): void
    {
        $entity = new Risk();
        $statuses = ['identified', 'assessed', 'in_treatment', 'treated', 'monitored', 'closed', 'accepted'];
        foreach ($statuses as $status) {
            $entity->setStatusValue($status);
            $this->assertSame($status, $entity->getStatusValue(), "statusValue bridge should round-trip '$status'");
            $this->assertInstanceOf(RiskStatus::class, $entity->getStatus());
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('risk');
        $this->assertNotNull($entry, "'risk' slug must be registered in EntityTypeRegistry");
        $this->assertSame(Risk::class, $entry['class']);
        $this->assertSame('risk_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesRisk(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('risk', $slugs);
    }
}
