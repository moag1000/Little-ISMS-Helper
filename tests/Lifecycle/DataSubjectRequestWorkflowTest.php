<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\DataSubjectRequest;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 + X.6 — Unit tests for DataSubjectRequest custom lifecycle (privacy-gated).
 *
 * Places (X.6 extended): received → identity_verification | in_progress → extended | completed | rejected
 * GDPR Art. 12-23: 30-day SLA for data subject rights requests; Art. 12(3) extension to 90 days.
 * Module gate: privacy.
 */
final class DataSubjectRequestWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new DataSubjectRequest();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsReceived(): void
    {
        $entity = new DataSubjectRequest();
        $this->assertSame('received', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new DataSubjectRequest();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllFourWorkflowPlaces(): void
    {
        $entity = new DataSubjectRequest();
        $places = ['received', 'in_progress', 'completed', 'rejected'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    /**
     * X.6: Verify the two new places added to data_subject_request_lifecycle are round-trippable.
     * identity_verification and extended are now workflow places (GDPR Art. 12(3)/(6)).
     */
    #[Test]
    public function statusAcceptsX6ExtendedPlaces(): void
    {
        $entity = new DataSubjectRequest();
        $x6Places = ['identity_verification', 'extended'];
        foreach ($x6Places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip after X.6 extension");
        }
    }

    /**
     * X.6: Verify all 6 workflow places are round-trippable (combined regression test).
     */
    #[Test]
    public function statusAcceptsAllSixWorkflowPlaces(): void
    {
        $entity = new DataSubjectRequest();
        $allPlaces = ['received', 'identity_verification', 'in_progress', 'extended', 'completed', 'rejected'];
        foreach ($allPlaces as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('data-subject-request');
        $this->assertNotNull($entry, "'data-subject-request' slug must be registered in EntityTypeRegistry");
        $this->assertSame(DataSubjectRequest::class, $entry['class']);
        $this->assertSame('data_subject_request_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesDataSubjectRequest(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('data-subject-request', $slugs);
    }
}
