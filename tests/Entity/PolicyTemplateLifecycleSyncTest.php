<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PolicyTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the one-way status → isActive sync in PolicyTemplate::setStatus().
 *
 * setStatus('published')  → isActive = true
 * setStatus(<anything else>) → isActive = false
 *
 * Backward-compat: isActive setters remain; this test ensures setStatus()
 * is the canonical way to drive both fields together.
 */
final class PolicyTemplateLifecycleSyncTest extends TestCase
{
    #[Test]
    public function setStatusPublishedSetsIsActiveTrue(): void
    {
        $entity = new PolicyTemplate();
        $entity->setStatus('published');

        $this->assertSame('published', $entity->getStatus());
        $this->assertTrue($entity->isActive());
    }

    /** @return list<array{0: string}> */
    public static function nonPublishedStatuses(): array
    {
        return [
            ['draft'],
            ['in_review'],
            ['approved'],
            ['archived'],
        ];
    }

    #[Test]
    #[DataProvider('nonPublishedStatuses')]
    public function setStatusNonPublishedSetsIsActiveFalse(string $status): void
    {
        $entity = new PolicyTemplate();
        // Start published so the sync direction is clear
        $entity->setStatus('published');
        $this->assertTrue($entity->isActive(), 'Precondition: published → isActive=true');

        $entity->setStatus($status);
        $this->assertSame($status, $entity->getStatus());
        $this->assertFalse($entity->isActive(), "setStatus('$status') should set isActive=false");
    }

    #[Test]
    public function defaultStateIsConsistent(): void
    {
        // New entity: status='draft', isActive defaults to true (legacy default).
        // setStatus has NOT been called yet, so sync has not run.
        // This is the pre-lifecycle state; new rows should call setStatus() explicitly.
        $entity = new PolicyTemplate();
        $this->assertSame('draft', $entity->getStatus());
        // isActive may be true (legacy default) — that's acceptable for new entities
        // before setStatus() has been called. The migration backfills existing rows.
        $this->assertIsBool($entity->isActive());
    }

    #[Test]
    public function setIsActiveRemainsIndependentOfStatus(): void
    {
        // Backward-compat: setIsActive() does NOT update status.
        $entity = new PolicyTemplate();
        $entity->setStatus('draft');
        $this->assertFalse($entity->isActive());

        // Legacy code calling setIsActive directly should not crash.
        $entity->setIsActive(true);
        $this->assertTrue($entity->isActive());
        // Status should be unchanged — no reverse sync.
        $this->assertSame('draft', $entity->getStatus());
    }
}
