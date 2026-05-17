<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Asset;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for Asset custom physical lifecycle state-machine.
 *
 * The asset_lifecycle state-machine is intentionally NOT the standard 5-stage
 * (draft/in_review/approved/published/archived) flow. It models the physical
 * lifecycle of tangible + digital assets (ISO 27001 A.5.9, A.5.10):
 *
 *   draft → active ⇄ inactive → retired → disposed
 *                       ↓
 *                    in_use ⇄ returned
 *
 * 7 places, 9 transitions. `dispose` is terminal + irreversible (four_eyes).
 *
 * Behaviour-level transition tests (guards, role enforcement) live in
 * LifecycleControllerTest (HTTP layer). This suite pins entity-shape
 * assumptions the workflow infrastructure depends on.
 */
final class AssetWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new Asset();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsActive(): void
    {
        // Existing assets default to 'active' (entity default for backward-compat).
        // New assets will be placed at 'draft' via workflow initial_marking when
        // the workflow is applied; the entity-level default stays 'active' so that
        // existing rows without the workflow applied are not broken.
        $entity = new Asset();
        $this->assertSame('active', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new Asset();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityHasTenantMethodRequiredByTenantGuard(): void
    {
        $entity = new Asset();
        $this->assertTrue(method_exists($entity, 'getTenant'));
    }

    #[Test]
    public function statusAcceptsAllSevenWorkflowPlaces(): void
    {
        $entity = new Asset();
        $places = ['draft', 'active', 'inactive', 'in_use', 'returned', 'retired', 'disposed'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('asset');
        $this->assertNotNull($entry, "'asset' slug must be registered in EntityTypeRegistry");
        $this->assertSame(Asset::class, $entry['class']);
        $this->assertSame('asset_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesAsset(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('asset', $slugs);
    }

    #[Test]
    public function isOperationalReturnsFalseForTerminalStatuses(): void
    {
        $entity = new Asset();

        $entity->setStatus('retired');
        $this->assertFalse($entity->isOperational(), "'retired' must not be operational");

        $entity->setStatus('disposed');
        $this->assertFalse($entity->isOperational(), "'disposed' must not be operational");
    }

    #[Test]
    public function isOperationalReturnsTrueForAllNonTerminalPlaces(): void
    {
        $entity = new Asset();
        $operational = ['draft', 'active', 'inactive', 'in_use', 'returned'];

        foreach ($operational as $place) {
            $entity->setStatus($place);
            $this->assertTrue($entity->isOperational(), "'$place' must be operational");
        }
    }
}
