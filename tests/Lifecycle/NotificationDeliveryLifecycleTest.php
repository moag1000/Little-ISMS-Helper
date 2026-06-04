<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Notification\NotificationDelivery;
use App\Enum\NotificationDeliveryStatus;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Junior-ISB-Audit Phase-2 Lifecycle — NotificationDelivery 6-stage state-machine.
 *
 * ISO 27001 Cl. 7.4 + DORA Art. 19 — incident-notification evidence must be
 * end-to-end traceable, not just dispatch-logged. The lifecycle splits the
 * legacy `sent` state into `sent` (handed off to transport) and `delivered`
 * (positive ACK from receiver), and introduces `archived` for the
 * post-retention terminal stage.
 *
 * This suite pins:
 *   1. Entity defaults (status, lock_version) for marking-store bootstrap.
 *   2. Slug registration in EntityTypeRegistry.
 *   3. Enum coverage of the 6 canonical places.
 *   4. YAML contract: 6 places, status as marking-store, pending as initial.
 *   5. YAML contract: archive_failed + archive_sent require a reason
 *      (audit-trail for SLA misses).
 */
final class NotificationDeliveryLifecycleTest extends TestCase
{
    private const string YAML_PATH = __DIR__ . '/../../config/workflows/notification_delivery.yaml';

    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new NotificationDelivery();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsPending(): void
    {
        $entity = new NotificationDelivery();
        $this->assertSame('pending', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new NotificationDelivery();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityHasTenantMethodRequiredByTenantGuard(): void
    {
        $entity = new NotificationDelivery();
        $this->assertTrue(method_exists($entity, 'getTenant'));
    }

    #[Test]
    public function statusAcceptsAllSixWorkflowPlaces(): void
    {
        $entity = new NotificationDelivery();
        $places = ['pending', 'sent', 'delivered', 'failed', 'retrying', 'archived'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function statusEnumExposesAllPlaces(): void
    {
        // The typed surface (NotificationDeliveryStatus) must enumerate the
        // same set of values as the YAML state-machine plus the F3 digest
        // pseudo-state (`pending_digest`) which is not a Symfony Workflow place
        // but lives in the same enum for consistency.
        $enumValues = array_map(
            static fn (NotificationDeliveryStatus $case): string => $case->value,
            NotificationDeliveryStatus::cases(),
        );
        $this->assertEqualsCanonicalizing(
            ['pending', 'sent', 'delivered', 'failed', 'retrying', 'archived', 'pending_digest'],
            $enumValues,
            'NotificationDeliveryStatus enum must enumerate all canonical places including pending_digest.',
        );
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('notification-delivery');
        $this->assertNotNull(
            $entry,
            "'notification-delivery' slug must be registered in EntityTypeRegistry (Phase-2)."
        );
        $this->assertSame(NotificationDelivery::class, $entry['class']);
        $this->assertSame('notification_delivery_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesNotificationDelivery(): void
    {
        $registry = new EntityTypeRegistry();
        $this->assertContains('notification-delivery', $registry->knownSlugs());
    }

    #[Test]
    public function yamlDefinesExactlySixPlaces(): void
    {
        $places = $this->workflowConfig()['places'];
        $this->assertEqualsCanonicalizing(
            ['pending', 'sent', 'delivered', 'failed', 'retrying', 'archived'],
            $places,
            'NotificationDelivery workflow must define exactly the six canonical places (Phase-2).'
        );
    }

    #[Test]
    public function yamlInitialMarkingIsPending(): void
    {
        $this->assertSame('pending', $this->workflowConfig()['initial_marking']);
    }

    #[Test]
    public function yamlMarkingStoreIsMethodOnStatusProperty(): void
    {
        $store = $this->workflowConfig()['marking_store'];
        $this->assertSame('method', $store['type']);
        $this->assertSame('status', $store['property']);
    }

    #[Test]
    public function yamlSupportsNotificationDeliveryEntity(): void
    {
        $supports = $this->workflowConfig()['supports'];
        $this->assertContains(NotificationDelivery::class, $supports);
    }

    #[Test]
    public function dispatchTransitionLeavesPending(): void
    {
        $dispatch = $this->workflowConfig()['transitions']['dispatch'];
        $this->assertSame('pending', $dispatch['from']);
        $this->assertSame('sent', $dispatch['to']);
    }

    #[Test]
    public function confirmDeliveryTransitionsSentToDelivered(): void
    {
        // ISO 27001 Cl. 7.4 — the dedicated `delivered` stage records the
        // end-to-end ACK that distinguishes "handed off" from "received".
        $transition = $this->workflowConfig()['transitions']['confirm_delivery'];
        $this->assertSame('sent', $transition['from']);
        $this->assertSame('delivered', $transition['to']);
    }

    #[Test]
    public function retryCycleConnectsFailedSentAndRetrying(): void
    {
        $transitions = $this->workflowConfig()['transitions'];

        // Failed → retrying (scheduler kicks in)
        $this->assertSame('failed', $transitions['schedule_retry']['from']);
        $this->assertSame('retrying', $transitions['schedule_retry']['to']);

        // Retrying → sent (retry fires)
        $this->assertSame('retrying', $transitions['resume_after_retry']['from']);
        $this->assertSame('sent', $transitions['resume_after_retry']['to']);
    }

    #[Test]
    public function archiveFailedRequiresReason(): void
    {
        // ISO 27001 Cl. 7.5.3 — irreversible archival of a failed
        // delivery must carry a documented reason (the audit-entry the
        // regulator may inspect for SLA misses).
        $transition = $this->workflowConfig()['transitions']['archive_failed'];
        $this->assertSame('failed', $transition['from']);
        $this->assertSame('archived', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['reason_required'] ?? false) === true,
            'archive_failed must require a reason to preserve audit-trail (Cl. 7.5.3).'
        );
    }

    #[Test]
    public function archiveSentRequiresReason(): void
    {
        // `sent` rows that never received ACK within SLA — manual archival
        // is an audit-relevant decision that needs a reason.
        $transition = $this->workflowConfig()['transitions']['archive_sent'];
        $this->assertSame('sent', $transition['from']);
        $this->assertSame('archived', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['reason_required'] ?? false) === true,
            'archive_sent must require a reason to preserve audit-trail.'
        );
    }

    #[Test]
    public function archiveDeliveredIsHappyPathRetention(): void
    {
        // Happy-path archival (retention cron) — no reason required, the
        // retention-window policy itself is the documented basis.
        $transition = $this->workflowConfig()['transitions']['archive_delivered'];
        $this->assertSame('delivered', $transition['from']);
        $this->assertSame('archived', $transition['to']);
    }

    /** @return array<string, mixed> */
    private function workflowConfig(): array
    {
        $parsed = Yaml::parseFile(self::YAML_PATH);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('framework', $parsed);
        $this->assertArrayHasKey('workflows', $parsed['framework']);
        $this->assertArrayHasKey('notification_delivery_lifecycle', $parsed['framework']['workflows']);

        return $parsed['framework']['workflows']['notification_delivery_lifecycle'];
    }
}
