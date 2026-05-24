<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Tenant;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
 *
 * Tenant is the multi-tenancy root — 78 entities scope by tenant_id. The
 * lifecycle is regulator-visible (suspending / terminating a customer is
 * an audit-relevant decision per GDPR Art. 17 + ISO 27001 A.8.10). All
 * transitions are SUPER_ADMIN-gated; suspend / reactivate / terminate
 * additionally require four-eyes approval.
 *
 * This suite pins:
 *   1. Entity defaults (status, lock_version) for marking-store bootstrap.
 *   2. YAML contract: 5 places, status as marking-store, draft as initial.
 *   3. YAML contract: 4-eyes mandatory on suspend / reactivate / terminate.
 *   4. Tenant slug registration in EntityTypeRegistry.
 *   5. Wrapper invariants: setIsActive ↔ status mirror, derived correctness.
 */
final class TenantLifecycleTest extends TestCase
{
    private const string YAML_PATH = __DIR__ . '/../../config/workflows/tenant.yaml';

    #[Test]
    public function entityHasStatusFieldWithDraftDefault(): void
    {
        $entity = new Tenant();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
        $this->assertSame('draft', $entity->getStatus());
    }

    #[Test]
    public function entityExposesAllFiveStatusConstants(): void
    {
        $this->assertSame('draft', Tenant::STATUS_DRAFT);
        $this->assertSame('active', Tenant::STATUS_ACTIVE);
        $this->assertSame('suspended', Tenant::STATUS_SUSPENDED);
        $this->assertSame('terminated', Tenant::STATUS_TERMINATED);
        $this->assertSame('archived', Tenant::STATUS_ARCHIVED);
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new Tenant();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function statusAcceptsAllFiveWorkflowPlaces(): void
    {
        $entity = new Tenant();
        foreach (['draft', 'active', 'suspended', 'terminated', 'archived'] as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('tenant');
        $this->assertNotNull(
            $entry,
            "'tenant' slug must be registered in EntityTypeRegistry (Phase-2)."
        );
        $this->assertSame(Tenant::class, $entry['class']);
        $this->assertSame('tenant_lifecycle', $entry['workflow']);
        $this->assertContains('tenant', $registry->knownSlugs());
    }

    #[Test]
    public function yamlDefinesExactlyFivePlaces(): void
    {
        $places = $this->workflowConfig()['places'];
        $this->assertEqualsCanonicalizing(
            ['draft', 'active', 'suspended', 'terminated', 'archived'],
            $places,
            'Tenant workflow must define exactly the five canonical places (Phase-2).'
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
    public function yamlSupportsTenantEntity(): void
    {
        $supports = $this->workflowConfig()['supports'];
        $this->assertContains(Tenant::class, $supports);
    }

    #[Test]
    public function activateTransitionLeavesDraftWithoutFourEyes(): void
    {
        $transitions = $this->workflowConfig()['transitions'];
        $this->assertArrayHasKey('activate', $transitions);
        $this->assertSame('draft', $transitions['activate']['from']);
        $this->assertSame('active', $transitions['activate']['to']);
        $this->assertContains('ROLE_SUPER_ADMIN', $transitions['activate']['metadata']['roles']);
        $this->assertFalse(
            $transitions['activate']['metadata']['four_eyes'] ?? false,
            'activate is onboarding — single SUPER_ADMIN is OK'
        );
    }

    #[Test]
    public function suspendTransitionRequiresFourEyesAndReason(): void
    {
        $transition = $this->workflowConfig()['transitions']['suspend'];
        $this->assertSame('active', $transition['from']);
        $this->assertSame('suspended', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['four_eyes'] ?? false) === true,
            'suspend must require 4-eyes — customer-visible interruption.'
        );
        $this->assertTrue(
            ($transition['metadata']['reason_required'] ?? false) === true,
            'suspend must require a reason — auditor expects rationale (Cl. 7.5.3).'
        );
        $this->assertContains('ROLE_SUPER_ADMIN', $transition['metadata']['roles']);
    }

    #[Test]
    public function reactivateTransitionRequiresFourEyesAndReason(): void
    {
        $transition = $this->workflowConfig()['transitions']['reactivate'];
        $this->assertSame('suspended', $transition['from']);
        $this->assertSame('active', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['four_eyes'] ?? false) === true,
            'reactivate must require 4-eyes — restoring access after incident must be dual-controlled.'
        );
        $this->assertTrue(
            ($transition['metadata']['reason_required'] ?? false) === true,
            'reactivate must require a reason — symmetric to suspend.'
        );
    }

    #[Test]
    public function terminateTransitionsRequireFourEyesAndReason(): void
    {
        $transitions = $this->workflowConfig()['transitions'];
        foreach (['terminate_from_active', 'terminate_from_suspended'] as $name) {
            $this->assertArrayHasKey($name, $transitions, "Transition '$name' must exist.");
            $this->assertSame('terminated', $transitions[$name]['to']);
            $this->assertTrue(
                ($transitions[$name]['metadata']['four_eyes'] ?? false) === true,
                "Transition '$name' must require 4-eyes — contract termination is irreversible."
            );
            $this->assertTrue(
                ($transitions[$name]['metadata']['reason_required'] ?? false) === true,
                "Transition '$name' must require a reason (GDPR Art. 17 / ISO 27001 A.8.10)."
            );
        }
    }

    #[Test]
    public function archiveTransitionLeavesTerminatedWithReason(): void
    {
        $transition = $this->workflowConfig()['transitions']['archive'];
        $this->assertSame('terminated', $transition['from']);
        $this->assertSame('archived', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['reason_required'] ?? false) === true,
            'archive must require a reason — final retention-window step.'
        );
    }

    #[Test]
    public function setStatusMirrorsIntoIsActiveBoolean(): void
    {
        // Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
        // The wrapper invariant: setStatus() MUST mirror into isActive
        // because Doctrine findBy(['isActive' => true]) hits the column
        // directly, not the method. If status drifts from isActive the
        // 30+ legacy readers go stale.
        $tenant = new Tenant();

        $tenant->setStatus(Tenant::STATUS_ACTIVE);
        $this->assertTrue($tenant->isActive(), 'STATUS_ACTIVE must mirror to isActive=true');

        $tenant->setStatus(Tenant::STATUS_SUSPENDED);
        $this->assertFalse($tenant->isActive(), 'STATUS_SUSPENDED must mirror to isActive=false');

        $tenant->setStatus(Tenant::STATUS_TERMINATED);
        $this->assertFalse($tenant->isActive(), 'STATUS_TERMINATED must mirror to isActive=false');

        $tenant->setStatus(Tenant::STATUS_ARCHIVED);
        $this->assertFalse($tenant->isActive(), 'STATUS_ARCHIVED must mirror to isActive=false');

        $tenant->setStatus(Tenant::STATUS_DRAFT);
        $this->assertFalse($tenant->isActive(), 'STATUS_DRAFT must mirror to isActive=false');
    }

    #[Test]
    public function setIsActiveTrueMirrorsIntoStatusActive(): void
    {
        // Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
        // Backward-compat: existing setIsActive(true|false) callsites must
        // also drive the new lifecycle marking, else the 5-stage state
        // machine and the legacy boolean would drift on legacy code paths.
        $tenant = new Tenant();
        $tenant->setIsActive(true);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->getStatus());
        $this->assertTrue($tenant->isActive());

        $tenant->setIsActive(false);
        $this->assertSame(Tenant::STATUS_SUSPENDED, $tenant->getStatus());
        $this->assertFalse($tenant->isActive());
    }

    #[Test]
    public function setIsActiveDoesNotResurrectTerminalState(): void
    {
        // Junior-ISB-Audit Phase-2 Lifecycle — Tenant (security-critical, 30+ isActive callsites preserved via wrapper).
        // Safety guard: a stale setIsActive(true) on a terminated/archived
        // tenant must NOT silently resurrect it back to active — that would
        // bypass the 4-eyes + reason guard on reactivate.
        $tenant = new Tenant();
        $tenant->setStatus(Tenant::STATUS_TERMINATED);
        $tenant->setIsActive(true);
        $this->assertSame(
            Tenant::STATUS_TERMINATED,
            $tenant->getStatus(),
            'setIsActive(true) must NOT resurrect a terminated tenant — would bypass 4-eyes reactivate.'
        );

        $tenant = new Tenant();
        $tenant->setStatus(Tenant::STATUS_ARCHIVED);
        $tenant->setIsActive(true);
        $this->assertSame(
            Tenant::STATUS_ARCHIVED,
            $tenant->getStatus(),
            'setIsActive(true) must NOT resurrect an archived tenant.'
        );
    }

    /** @return array<string, mixed> */
    private function workflowConfig(): array
    {
        $parsed = Yaml::parseFile(self::YAML_PATH);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('framework', $parsed);
        $this->assertArrayHasKey('workflows', $parsed['framework']);
        $this->assertArrayHasKey('tenant_lifecycle', $parsed['framework']['workflows']);

        return $parsed['framework']['workflows']['tenant_lifecycle'];
    }
}
