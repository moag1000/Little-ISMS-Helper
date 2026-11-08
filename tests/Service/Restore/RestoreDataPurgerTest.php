<?php

declare(strict_types=1);

namespace App\Tests\Service\Restore;

use App\Entity\Tenant;
use App\Service\Restore\RestoreDataPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
final class RestoreDataPurgerTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $logger;
    private RestoreDataPurger $purger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->purger        = new RestoreDataPurger($this->entityManager, $this->logger);
    }

    // ────────────────────────────────────────────────────────────────────────
    // resolveTenantScopeIds
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function resolve_scope_ids_returns_empty_array_for_null_scope(): void
    {
        $ids = $this->purger->resolveTenantScopeIds(null);
        self::assertSame([], $ids);
    }

    #[Test]
    public function resolve_scope_ids_includes_root_tenant_id(): void
    {
        $tenant = $this->createTenantWithId(42);
        $ids = $this->purger->resolveTenantScopeIds($tenant);
        self::assertContains(42, $ids);
    }

    #[Test]
    public function resolve_scope_ids_includes_subsidiary_ids(): void
    {
        $child1 = $this->createTenantWithId(10);
        $child2 = $this->createTenantWithId(11);
        $parent = $this->createTenantWithSubsidiaries(5, [$child1, $child2]);

        $ids = $this->purger->resolveTenantScopeIds($parent);

        self::assertContains(5, $ids);
        self::assertContains(10, $ids);
        self::assertContains(11, $ids);
    }

    // ────────────────────────────────────────────────────────────────────────
    // filterEntitiesByScope
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function filter_entities_returns_all_when_scope_ids_empty(): void
    {
        $entities = [
            ['id' => 1, 'tenant_id' => ['id' => 99]],
            ['id' => 2, 'tenant_id' => ['id' => 88]],
        ];

        $result = $this->purger->filterEntitiesByScope($entities, []);

        self::assertCount(2, $result);
    }

    #[Test]
    public function filter_entities_keeps_only_matching_tenant_ids(): void
    {
        $entities = [
            ['id' => 1, 'tenant_id' => ['id' => 1]],
            ['id' => 2, 'tenant_id' => ['id' => 2]],
            ['id' => 3, 'tenant_id' => ['id' => 99]],
        ];

        $result = $this->purger->filterEntitiesByScope($entities, [1, 2]);

        self::assertCount(2, $result);
        $ids = array_column($result, 'id');
        self::assertContains(1, $ids);
        self::assertContains(2, $ids);
        self::assertNotContains(3, $ids);
    }

    #[Test]
    public function filter_entities_drops_rows_without_tenant_id_in_scoped_restore(): void
    {
        // The upload is untrusted: a tenant row whose tenant_id was removed by hand
        // must not slip through as "global".
        $entities = [
            ['id' => 1, 'title' => 'no tenant'],
            ['id' => 2, 'tenant_id' => ['id' => 5]],
        ];

        $result = $this->purger->filterEntitiesByScope($entities, [5]);

        self::assertSame([2], array_column($result, 'id'));
    }

    #[Test]
    public function filter_entities_drops_instance_entities_in_scoped_restore(): void
    {
        // Role, Permission, SystemSettings, MfaToken, UserSession … have no tenant
        // association: an organisation restore never writes them.
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasAssociation')->with('tenant')->willReturn(false);
        $this->entityManager->method('getClassMetadata')->willReturn($metadata);

        $rows = [['id' => 1, 'name' => 'ROLE_USER'], ['id' => 2, 'user_id' => ['id' => 1]]];

        self::assertSame([], $this->purger->filterEntitiesByScope($rows, [5], 'Role'));
        self::assertSame([], $this->purger->filterEntitiesByScope($rows, [5], 'MfaToken'));
        self::assertSame($rows, $this->purger->filterEntitiesByScope($rows, [], 'Role'));
    }

    #[Test]
    public function filter_entities_handles_scalar_tenant_id(): void
    {
        $entities = [
            ['id' => 1, 'tenant_id' => 5],
            ['id' => 2, 'tenant_id' => 9],
        ];

        $result = $this->purger->filterEntitiesByScope($entities, [5]);

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['id']);
    }

    #[Test]
    public function filter_entities_keeps_only_organisation_records_in_scope(): void
    {
        // Tenant rows carry no tenant_id — they must be matched on their own id,
        // otherwise a scoped restore would overwrite a foreign organisation.
        $tenants = [
            ['id' => 5, 'code' => 'own'],
            ['id' => 9, 'code' => 'foreign'],
        ];

        $result = $this->purger->filterEntitiesByScope($tenants, [5], 'Tenant');

        self::assertCount(1, $result);
        self::assertSame('own', $result[0]['code']);
        self::assertSame($tenants, $this->purger->filterEntitiesByScope($tenants, [], 'Tenant'));
    }

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

    // ────────────────────────────────────────────────────────────────────────
    // verifiedRecordedScope (S11): the file's tenant_scope is checked, not believed
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function a_recorded_scope_is_cut_to_the_roots_tree(): void
    {
        $child = $this->createTenantWithId(6);
        $root = $this->createTenantWithSubsidiaries(5, [$child]);
        $this->entityManager->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?Tenant => match ((int) $id) { 5 => $root, 6 => $child, 7, 8 => new Tenant(), default => null },
        );

        // A hand-edited file claims two unrelated organisations (7, 8) next to its own tree.
        self::assertSame([5, 6], $this->purger->verifiedRecordedScope([5, 6, 7, 8]));
    }

    #[Test]
    public function without_the_root_here_only_unknown_ids_remain(): void
    {
        $this->entityManager->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?Tenant => (int) $id === 3 ? new Tenant() : null,
        );

        self::assertSame([9, 10], $this->purger->verifiedRecordedScope([9, 3, 10]));
    }

    private function createTenantWithId(int $id): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getAllSubsidiaries')->willReturn([]);
        return $tenant;
    }

    private function createTenantWithSubsidiaries(int $id, array $subsidiaries): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getAllSubsidiaries')->willReturn($subsidiaries);
        return $tenant;
    }
}
