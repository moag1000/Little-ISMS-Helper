<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Role;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
 *
 * ISO 27001 A.5.15-A.5.18 — the role catalog is the spine of RBAC.
 * Archiving a role removes it from new user-bindings; per
 * segregation-of-duties this is a dual-controlled (4-eyes) decision.
 *
 * Note: Role is a system-wide entity (no tenant_id) — the TenantGuard
 * tolerates entities without getTenant(), so there is no tenant-accessor
 * assertion here.
 *
 * This suite pins:
 *   1. Entity defaults (status, lock_version) for marking-store bootstrap.
 *   2. Role slug registration in EntityTypeRegistry.
 *   3. YAML contract: 3 places, status as marking-store, draft as initial.
 *   4. YAML contract: archive + reactivate require BOTH 4-eyes AND reason.
 */
final class RoleLifecycleTest extends TestCase
{
    private const string YAML_PATH = __DIR__ . '/../../config/workflows/role.yaml';

    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new Role();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsDraft(): void
    {
        $entity = new Role();
        $this->assertSame('draft', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new Role();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityExposesAllThreeStatusConstants(): void
    {
        $this->assertSame('draft', Role::STATUS_DRAFT);
        $this->assertSame('active', Role::STATUS_ACTIVE);
        $this->assertSame('archived', Role::STATUS_ARCHIVED);
    }

    #[Test]
    public function statusAcceptsAllThreeWorkflowPlaces(): void
    {
        $entity = new Role();
        $places = ['draft', 'active', 'archived'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('role');
        $this->assertNotNull(
            $entry,
            "'role' slug must be registered in EntityTypeRegistry (Phase-2)."
        );
        $this->assertSame(Role::class, $entry['class']);
        $this->assertSame('role_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesRole(): void
    {
        $registry = new EntityTypeRegistry();
        $this->assertContains('role', $registry->knownSlugs());
    }

    #[Test]
    public function yamlDefinesExactlyThreePlaces(): void
    {
        $places = $this->workflowConfig()['places'];
        $this->assertEqualsCanonicalizing(
            ['draft', 'active', 'archived'],
            $places,
            'Role workflow must define exactly the three canonical places (Phase-2).'
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
    public function yamlSupportsRoleEntity(): void
    {
        $supports = $this->workflowConfig()['supports'];
        $this->assertContains(Role::class, $supports);
    }

    #[Test]
    public function activateTransitionLeavesDraft(): void
    {
        $activate = $this->workflowConfig()['transitions']['activate'];
        $this->assertSame('draft', $activate['from']);
        $this->assertSame('active', $activate['to']);
    }

    #[Test]
    public function archiveTransitionRequiresFourEyesAndReason(): void
    {
        // ISO 27001 A.5.18 (Privileged-Access-Rights) + segregation-of-duties —
        // archiving a role is an RBAC change that must be dual-controlled.
        $transition = $this->workflowConfig()['transitions']['archive'];
        $this->assertSame('active', $transition['from']);
        $this->assertSame('archived', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['four_eyes'] ?? false) === true,
            'archive must require four_eyes — RBAC change per A.5.18 / segregation-of-duties.'
        );
        $this->assertTrue(
            ($transition['metadata']['reason_required'] ?? false) === true,
            'archive must require a reason — audit trail per Cl. 7.5.3.'
        );
    }

    #[Test]
    public function reactivateTransitionRequiresFourEyesAndReason(): void
    {
        // Symmetric to archive — re-introducing a role to the catalog is
        // also a privileged RBAC change.
        $transition = $this->workflowConfig()['transitions']['reactivate'];
        $this->assertSame('archived', $transition['from']);
        $this->assertSame('active', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['four_eyes'] ?? false) === true,
            'reactivate must require four_eyes — symmetric to archive.'
        );
        $this->assertTrue(
            ($transition['metadata']['reason_required'] ?? false) === true,
            'reactivate must require a reason — audit symmetry.'
        );
    }

    /** @return array<string, mixed> */
    private function workflowConfig(): array
    {
        $parsed = Yaml::parseFile(self::YAML_PATH);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('framework', $parsed);
        $this->assertArrayHasKey('workflows', $parsed['framework']);
        $this->assertArrayHasKey('role_lifecycle', $parsed['framework']['workflows']);

        return $parsed['framework']['workflows']['role_lifecycle'];
    }
}
