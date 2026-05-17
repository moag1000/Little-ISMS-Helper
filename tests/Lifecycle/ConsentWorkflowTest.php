<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Consent;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lifecycle X.2 — Unit tests for Consent custom lifecycle state-machine (privacy-gated).
 *
 * Places: pending_verification → active → revoked | expired
 * GDPR Art. 7 — consent must be freely given, specific, informed and unambiguous.
 * Module gate: privacy.
 */
final class ConsentWorkflowTest extends TestCase
{
    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new Consent();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsPendingVerification(): void
    {
        $entity = new Consent();
        $this->assertSame('pending_verification', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new Consent();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllFourWorkflowPlaces(): void
    {
        $entity = new Consent();
        $places = ['pending_verification', 'active', 'revoked', 'expired'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('consent');
        $this->assertNotNull($entry, "'consent' slug must be registered in EntityTypeRegistry");
        $this->assertSame(Consent::class, $entry['class']);
        $this->assertSame('consent_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesConsent(): void
    {
        $registry = new EntityTypeRegistry();
        $slugs = $registry->knownSlugs();
        $this->assertContains('consent', $slugs);
    }
}
