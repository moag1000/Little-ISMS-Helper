<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\DataProtectionImpactAssessment;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for DPIA custom lifecycle (privacy-gated).
 *
 * Places: draft → in_review → approved | rejected | requires_revision
 *         requires_revision → draft (resubmit, ROLE_DPO)
 *
 * GDPR Art. 35/36 — Data Protection Impact Assessment.
 * Module gate: privacy.
 */
final class DpiaWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new DataProtectionImpactAssessment();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsDraft(): void
    {
        $entity = new DataProtectionImpactAssessment();
        $this->assertSame('draft', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new DataProtectionImpactAssessment();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllFiveWorkflowPlaces(): void
    {
        $entity = new DataProtectionImpactAssessment();
        $places = ['draft', 'in_review', 'approved', 'rejected', 'requires_revision'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('dpia');
        $this->assertNotNull($entry, "'dpia' slug must be registered in EntityTypeRegistry");
        $this->assertSame(DataProtectionImpactAssessment::class, $entry['class']);
        $this->assertSame('dpia_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesDpia(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('dpia', $slugs);
    }
}
