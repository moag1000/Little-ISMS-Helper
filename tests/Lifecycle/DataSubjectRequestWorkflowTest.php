<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\DataSubjectRequest;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for DataSubjectRequest custom lifecycle (privacy-gated).
 *
 * Places: received → in_progress → completed | rejected
 * GDPR Art. 12-23: 30-day SLA for data subject rights requests.
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
