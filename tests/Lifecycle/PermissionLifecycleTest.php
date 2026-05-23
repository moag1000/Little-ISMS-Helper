<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\Permission;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Junior-ISB-Audit Phase-2 Lifecycle — RBAC core entities.
 *
 * ISO 27001 A.5.15-A.5.16 (Access Control + Identity Management). The
 * permission catalog is an audit-relevant artifact: an auditor must be
 * able to tell which permissions are operationally active vs deprecated
 * vs archived.
 *
 * Note: Permission is a system-wide entity (no tenant_id) — the TenantGuard
 * tolerates entities without getTenant(), so there is no tenant-accessor
 * assertion here.
 *
 * This suite pins:
 *   1. Entity defaults (status, lock_version) for marking-store bootstrap.
 *   2. Permission slug registration in EntityTypeRegistry.
 *   3. YAML contract: 4 places, status as marking-store, draft as initial.
 *   4. YAML contract: deprecate / archive transitions require a reason.
 */
final class PermissionLifecycleTest extends TestCase
{
    private const string YAML_PATH = __DIR__ . '/../../config/workflows/permission.yaml';

    #[Test]
    public function entityHasStatusField(): void
    {
        $entity = new Permission();
        $this->assertTrue(method_exists($entity, 'getStatus'));
        $this->assertTrue(method_exists($entity, 'setStatus'));
    }

    #[Test]
    public function entityDefaultStatusIsDraft(): void
    {
        $entity = new Permission();
        $this->assertSame('draft', $entity->getStatus());
    }

    #[Test]
    public function entityHasLockVersionForOptimisticLocking(): void
    {
        $entity = new Permission();
        $this->assertTrue(method_exists($entity, 'getLockVersion'));
        $this->assertSame(0, $entity->getLockVersion());
    }

    #[Test]
    public function entityExposesAllFourStatusConstants(): void
    {
        $this->assertSame('draft', Permission::STATUS_DRAFT);
        $this->assertSame('active', Permission::STATUS_ACTIVE);
        $this->assertSame('deprecated', Permission::STATUS_DEPRECATED);
        $this->assertSame('archived', Permission::STATUS_ARCHIVED);
    }

    #[Test]
    public function statusAcceptsAllFourWorkflowPlaces(): void
    {
        $entity = new Permission();
        $places = ['draft', 'active', 'deprecated', 'archived'];
        foreach ($places as $place) {
            $entity->setStatus($place);
            $this->assertSame($place, $entity->getStatus(), "setStatus('$place') should round-trip");
        }
    }

    #[Test]
    public function entitySlugRegisteredInEntityTypeRegistry(): void
    {
        $registry = new EntityTypeRegistry();
        $entry = $registry->lookup('permission');
        $this->assertNotNull(
            $entry,
            "'permission' slug must be registered in EntityTypeRegistry (Phase-2)."
        );
        $this->assertSame(Permission::class, $entry['class']);
        $this->assertSame('permission_lifecycle', $entry['workflow']);
    }

    #[Test]
    public function knownSlugsIncludesPermission(): void
    {
        $registry = new EntityTypeRegistry();
        $this->assertContains('permission', $registry->knownSlugs());
    }

    #[Test]
    public function yamlDefinesExactlyFourPlaces(): void
    {
        $places = $this->workflowConfig()['places'];
        $this->assertEqualsCanonicalizing(
            ['draft', 'active', 'deprecated', 'archived'],
            $places,
            'Permission workflow must define exactly the four canonical places (Phase-2).'
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
    public function yamlSupportsPermissionEntity(): void
    {
        $supports = $this->workflowConfig()['supports'];
        $this->assertContains(Permission::class, $supports);
    }

    #[Test]
    public function activateTransitionLeavesDraft(): void
    {
        $activate = $this->workflowConfig()['transitions']['activate'];
        $this->assertSame('draft', $activate['from']);
        $this->assertSame('active', $activate['to']);
    }

    #[Test]
    public function deprecateTransitionRequiresReason(): void
    {
        $transition = $this->workflowConfig()['transitions']['deprecate'];
        $this->assertSame('active', $transition['from']);
        $this->assertSame('deprecated', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['reason_required'] ?? false) === true,
            'deprecate must require a reason — auditor expects rationale on the trail (Cl. 7.5.3).'
        );
    }

    #[Test]
    public function reactivateFromDeprecatedRequiresReason(): void
    {
        $transition = $this->workflowConfig()['transitions']['reactivate'];
        $this->assertSame('deprecated', $transition['from']);
        $this->assertSame('active', $transition['to']);
        $this->assertTrue(
            ($transition['metadata']['reason_required'] ?? false) === true,
            'reactivate must require a reason — symmetric to deprecate for audit symmetry.'
        );
    }

    #[Test]
    public function archiveTransitionsRequireReason(): void
    {
        // ISO 27001 Cl. 7.5.3 — every irreversible state change on the
        // permission catalog must carry a documented reason.
        $transitions = $this->workflowConfig()['transitions'];

        foreach (['archive_from_deprecated', 'archive_from_active'] as $name) {
            $this->assertArrayHasKey($name, $transitions, "Transition '$name' must exist.");
            $this->assertSame('archived', $transitions[$name]['to']);
            $this->assertTrue(
                ($transitions[$name]['metadata']['reason_required'] ?? false) === true,
                "Transition '$name' must require a reason."
            );
        }
    }

    /** @return array<string, mixed> */
    private function workflowConfig(): array
    {
        $parsed = Yaml::parseFile(self::YAML_PATH);
        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('framework', $parsed);
        $this->assertArrayHasKey('workflows', $parsed['framework']);
        $this->assertArrayHasKey('permission_lifecycle', $parsed['framework']['workflows']);

        return $parsed['framework']['workflows']['permission_lifecycle'];
    }
}
