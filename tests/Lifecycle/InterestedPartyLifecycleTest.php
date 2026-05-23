<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\InterestedParty;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Junior-ISB-Audit-2026-05-22 S-01 — Sprint S13 InterestedParty-Lifecycle.
 *
 * ISO 27001 Cl. 4.2 + 9.3.2 c — Mgmt-Review input bundle must reference a
 * versioned stakeholder snapshot. The four-stage state-machine
 * `draft → active ⇄ in_review → archived → active` makes this versioning
 * explicit + audit-traceable.
 *
 * This suite pins:
 *   1. Entity defaults (status, lock_version) for marking-store bootstrap.
 *   2. InterestedParty slug registration in EntityTypeRegistry.
 *   3. YAML contract: 4 places, status as marking-store, draft as initial.
 *   4. YAML contract: archive transitions require a reason (audit trail).
 */
final class InterestedPartyLifecycleTest extends TestCase
{
    private const string YAML_PATH = __DIR__ . '/../../config/workflows/interested_party.yaml';

    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new InterestedParty();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsDraft(): void
    {
        $entity = new InterestedParty();
        $this->assertSame('draft', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new InterestedParty();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityHasTenantMethodRequiredByTenantGuard(): void
    {
        $entity = new InterestedParty();
        $this->assertTrue(method_exists($entity, 'getTenant'));
    }

    #[Test]
    public function statusAcceptsAllFourWorkflowPlaces(): void
    {
        $entity = new InterestedParty();
        $places = ['draft', 'active', 'in_review', 'archived'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('interested-party');
        $this->assertNotNull(
            $entry,
            "'interested-party' slug must be registered in EntityTypeRegistry (S-01)."
        );
        $this->assertSame(InterestedParty::class, $entry['class']);
        $this->assertSame('interested_party_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesInterestedParty(): void
    {
        $registry = new EntityTypeRegistry();
        $this->assertContains('interested-party', $registry->knownSlugs());
    }

    #[Test]
    public function yamlDefinesExactlyFourPlaces(): void
    {
        $places = $this->workflowConfig()['places'];
        $this->assertEqualsCanonicalizing(
            ['draft', 'active', 'in_review', 'archived'],
            $places,
            'InterestedParty workflow must define exactly the four canonical places (S-01).'
        );
    }

    #[Test]
    public function yamlInitialMarkingIsDraft(): void
    {
        $this->assertSame('draft', $this->workflowConfig()['initial_marking']);
    }

    #[Test]
    public function yamlMarkingStoreIsMethodOnStatusProperty(): void
    {
        $store = $this->workflowConfig()['marking_store'];
        $this->assertSame('method', $store['type']);
        $this->assertSame('status', $store['property']);
    }

    #[Test]
    public function yamlSupportsInterestedPartyEntity(): void
    {
        $supports = $this->workflowConfig()['supports'];
        $this->assertContains(InterestedParty::class, $supports);
    }

    #[Test]
    public function activateTransitionLeavesDraft(): void
    {
        $activate = $this->workflowConfig()['transitions']['activate'];
        $this->assertSame('draft', $activate['from']);
        $this->assertSame('active', $activate['to']);
    }

    #[Test]
    public function reviewRoundTripBetweenActiveAndInReview(): void
    {
        $transitions = $this->workflowConfig()['transitions'];
        $this->assertSame('active', $transitions['start_review']['from']);
        $this->assertSame('in_review', $transitions['start_review']['to']);
        $this->assertSame('in_review', $transitions['conclude_review']['from']);
        $this->assertSame('active', $transitions['conclude_review']['to']);
    }

    #[Test]
    public function archiveTransitionsRequireReason(): void
    {
        // ISO 27001 Cl. 7.5.3 — every irreversible state change on a
        // mgmt-review-input record must carry a documented reason.
        $transitions = $this->workflowConfig()['transitions'];

        foreach (['archive_from_active', 'archive_from_review'] as $name) {
            $this->assertArrayHasKey($name, $transitions, "Transition '$name' must exist.");
            $this->assertSame('archived', $transitions[$name]['to']);
            $this->assertTrue(
                ($transitions[$name]['metadata']['reason_required'] ?? false) === true,
                "Transition '$name' must require a reason."
            );
        }
    }

    #[Test]
    public function reactivateFromArchivedRequiresReason(): void
    {
        $transition = $this->workflowConfig()['transitions']['reactivate'];
        $this->assertSame('archived', $transition['from']);
        $this->assertSame('active', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['reason_required'] ?? false) === true,
            'reactivate must require a reason to keep audit-trail (Cl. 7.5.3).'
        );
    }

    /** @return array<string, mixed> */
    private function workflowConfig(): array
    {
        $parsed = Yaml::parseFile(self::YAML_PATH);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('framework', $parsed);
        $this->assertArrayHasKey('workflows', $parsed['framework']);
        $this->assertArrayHasKey('interested_party_lifecycle', $parsed['framework']['workflows']);

        return $parsed['framework']['workflows']['interested_party_lifecycle'];
    }
}
