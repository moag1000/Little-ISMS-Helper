<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\DataBreach;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for DataBreach custom lifecycle (privacy-gated, GDPR Art.33).
 *
 * Places: draft → under_assessment → authority_notified → subjects_notified → closed
 * Reopen: closed → under_assessment (ROLE_DPO, reason_required)
 *
 * GDPR Art. 33: 72h supervisory authority notification SLA.
 * GDPR Art. 34: Data subject notification required for high-risk breaches.
 * Module gate: privacy.
 */
final class DataBreachWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new DataBreach();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsDraft(): void
    {
        $entity = new DataBreach();
        $this->assertSame('draft', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new DataBreach();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllFiveWorkflowPlaces(): void
    {
        $entity = new DataBreach();
        $places = ['draft', 'under_assessment', 'authority_notified', 'subjects_notified', 'closed'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('data-breach');
        $this->assertNotNull($entry, "'data-breach' slug must be registered in EntityTypeRegistry");
        $this->assertSame(DataBreach::class, $entry['class']);
        $this->assertSame('data_breach_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesDataBreach(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('data-breach', $slugs);
    }
}
