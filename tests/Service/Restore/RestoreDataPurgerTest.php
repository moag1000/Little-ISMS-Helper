<?php

declare(strict_types=1);

namespace App\Tests\Service\Restore;

use App\Entity\Tenant;
use App\Service\Restore\RestoreDataPurger;
use Doctrine\ORM\EntityManagerInterface;
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
    public function filter_entities_includes_entities_without_tenant_field(): void
    {
        // Global entities (Role, Permission, etc.) have no tenant_id in backup
        $entities = [
            ['id' => 1, 'name' => 'ROLE_USER'],
            ['id' => 2, 'tenant_id' => ['id' => 5]],
        ];

        $result = $this->purger->filterEntitiesByScope($entities, [5]);

        self::assertCount(2, $result);
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

    // ────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────

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
