<?php

namespace App\Tests\Service;

use App\Entity\CorporateGovernance;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\CorporateGovernanceRepository;
use App\Repository\SupplierRepository;
use App\Service\CorporateStructureService;
use App\Service\SupplierService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SupplierServiceTest extends TestCase
{
    private MockObject $supplierRepository;
    private MockObject $corporateStructureService;
    private MockObject $governanceRepository;
    private SupplierService $service;

    protected function setUp(): void
    {
        $this->supplierRepository = $this->createMock(SupplierRepository::class);
        $this->corporateStructureService = $this->createMock(CorporateStructureService::class);
        $this->governanceRepository = $this->createMock(CorporateGovernanceRepository::class);

        $this->service = new SupplierService(
            $this->supplierRepository,
            $this->corporateStructureService,
            $this->governanceRepository
        );
    }

    public function testGetSuppliersForTenantWithoutParent(): void
    {
        $tenant = $this->createTenant(1, null);
        $suppliers = [$this->createMock(Supplier::class)];

        $this->supplierRepository->method('findByTenant')
            ->with($tenant)
            ->willReturn($suppliers);

        $result = $this->service->getSuppliersForTenant($tenant);

        $this->assertSame($suppliers, $result);
    }

    public function testGetSuppliersForTenantWithHierarchicalGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $inheritedSuppliers = [
            $this->createMock(Supplier::class),
            $this->createMock(Supplier::class),
            $this->createMock(Supplier::class),
        ];

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'supplier')
            ->willReturn($governance);

        $this->supplierRepository->method('findByTenantIncludingParent')
            ->with($child, $parent)
            ->willReturn($inheritedSuppliers);

        $result = $this->service->getSuppliersForTenant($child);

        $this->assertSame($inheritedSuppliers, $result);
        $this->assertCount(3, $result);
    }

    public function testGetSuppliersForTenantWithSharedGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownSuppliers = [$this->createMock(Supplier::class)];

        $governance = $this->createGovernance('shared');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'supplier')
            ->willReturn($governance);

        $this->supplierRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownSuppliers);

        $result = $this->service->getSuppliersForTenant($child);

        $this->assertSame($ownSuppliers, $result);
    }

    public function testGetSuppliersForTenantWithIndependentGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownSuppliers = [$this->createMock(Supplier::class)];

        $governance = $this->createGovernance('independent');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'supplier')
            ->willReturn($governance);

        $this->supplierRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownSuppliers);

        $result = $this->service->getSuppliersForTenant($child);

        $this->assertSame($ownSuppliers, $result);
    }

    public function testGetSuppliersForTenantFallbackToDefaultGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $inheritedSuppliers = [
            $this->createMock(Supplier::class),
            $this->createMock(Supplier::class),
        ];

        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'supplier')
            ->willReturn(null);

        $defaultGovernance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findDefaultGovernance')
            ->with($child)
            ->willReturn($defaultGovernance);

        $this->supplierRepository->method('findByTenantIncludingParent')
            ->with($child, $parent)
            ->willReturn($inheritedSuppliers);

        $result = $this->service->getSuppliersForTenant($child);

        $this->assertSame($inheritedSuppliers, $result);
    }

    public function testGetSupplierInheritanceInfoWithoutParent(): void
    {
        $tenant = $this->createTenant(1, null);

        $info = $this->service->getSupplierInheritanceInfo($tenant);

        $this->assertFalse($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertNull($info['governanceModel']);
    }

    public function testGetSupplierInheritanceInfoWithHierarchicalParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'supplier')
            ->willReturn($governance);

        $info = $this->service->getSupplierInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertTrue($info['canInherit']);
        $this->assertSame('hierarchical', $info['governanceModel']);
    }

    public function testGetSupplierInheritanceInfoWithSharedParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('shared');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'supplier')
            ->willReturn($governance);

        $info = $this->service->getSupplierInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertSame('shared', $info['governanceModel']);
    }

    public function testGetSupplierInheritanceInfoWithIndependentParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('independent');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'supplier')
            ->willReturn($governance);

        $info = $this->service->getSupplierInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertSame('independent', $info['governanceModel']);
    }

    public function testIsInheritedSupplierTrue(): void
    {
        $parentTenant = $this->createTenant(1, null);
        $childTenant = $this->createTenant(2, $parentTenant);

        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getTenant')->willReturn($parentTenant);

        $this->assertTrue($this->service->isInheritedSupplier($supplier, $childTenant));
    }

    public function testIsInheritedSupplierFalse(): void
    {
        $tenant = $this->createTenant(1, null);

        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getTenant')->willReturn($tenant);

        $this->assertFalse($this->service->isInheritedSupplier($supplier, $tenant));
    }

    public function testIsInheritedSupplierWithNullTenant(): void
    {
        $tenant = $this->createTenant(1, null);

        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getTenant')->willReturn(null);

        $this->assertFalse($this->service->isInheritedSupplier($supplier, $tenant));
    }

    public function testIsInheritedSupplierWithNullIds(): void
    {
        $tenant1 = $this->createTenant(null, null);
        $tenant2 = $this->createTenant(null, null);

        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getTenant')->willReturn($tenant1);

        $this->assertFalse($this->service->isInheritedSupplier($supplier, $tenant2));
    }

    public function testCanEditSupplierOwnSupplier(): void
    {
        $tenant = $this->createTenant(1, null);

        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getTenant')->willReturn($tenant);

        $this->assertTrue($this->service->canEditSupplier($supplier, $tenant));
    }

    public function testCanEditSupplierInheritedSupplier(): void
    {
        $parentTenant = $this->createTenant(1, null);
        $childTenant = $this->createTenant(2, $parentTenant);

        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getTenant')->willReturn($parentTenant);

        $this->assertFalse($this->service->canEditSupplier($supplier, $childTenant));
    }

    public function testGetSupplierStatsWithInheritance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        // Child has 2 own suppliers
        $ownSuppliers = [
            $this->createMock(Supplier::class),
            $this->createMock(Supplier::class),
        ];

        // Total includes 3 inherited suppliers from parent
        $allSuppliers = array_merge($ownSuppliers, [
            $this->createMock(Supplier::class),
            $this->createMock(Supplier::class),
            $this->createMock(Supplier::class),
        ]);

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->willReturn($governance);

        $this->supplierRepository->method('findByTenantIncludingParent')
            ->willReturn($allSuppliers);

        $this->supplierRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownSuppliers);

        $this->supplierRepository->method('getStatisticsByTenant')
            ->with($child)
            ->willReturn([
                'total' => 5,
                'active' => 4,
                'inactive' => 1,
                'critical' => 2,
            ]);

        $stats = $this->service->getSupplierStatsWithInheritance($child);

        $this->assertSame(5, $stats['total']);
        $this->assertSame(2, $stats['ownSuppliers']);
        $this->assertSame(3, $stats['inheritedSuppliers']);
        $this->assertSame(4, $stats['active']);
        $this->assertSame(2, $stats['critical']);
    }

    public function testServiceWorksWithoutOptionalDependencies(): void
    {
        $simpleService = new SupplierService($this->supplierRepository, null, null);

        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownSuppliers = [$this->createMock(Supplier::class)];

        $this->supplierRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownSuppliers);

        $result = $simpleService->getSuppliersForTenant($child);

        $this->assertSame($ownSuppliers, $result);
    }

    public function testGetSupplierInheritanceInfoWithNoGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $this->governanceRepository->method('findGovernanceForScope')
            ->willReturn(null);
        $this->governanceRepository->method('findDefaultGovernance')
            ->willReturn(null);

        $info = $this->service->getSupplierInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertNull($info['governanceModel']);
    }

    public function testGetSupplierStatsWithNoInheritance(): void
    {
        $tenant = $this->createTenant(1, null);

        $ownSuppliers = [
            $this->createMock(Supplier::class),
            $this->createMock(Supplier::class),
        ];

        $this->supplierRepository->method('findByTenant')
            ->with($tenant)
            ->willReturn($ownSuppliers);

        $this->supplierRepository->method('getStatisticsByTenant')
            ->with($tenant)
            ->willReturn([
                'total' => 2,
                'active' => 2,
                'inactive' => 0,
                'critical' => 1,
            ]);

        $stats = $this->service->getSupplierStatsWithInheritance($tenant);

        $this->assertSame(2, $stats['total']);
        $this->assertSame(2, $stats['ownSuppliers']);
        $this->assertSame(0, $stats['inheritedSuppliers']);
    }

    private function createTenant(?int $id, ?Tenant $parent): MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getParent')->willReturn($parent);
        return $tenant;
    }

    private function createGovernance(string $modelValue): MockObject
    {
        $model = match ($modelValue) {
            'hierarchical' => GovernanceModel::HIERARCHICAL,
            'shared' => GovernanceModel::SHARED,
            'independent' => GovernanceModel::INDEPENDENT,
            default => GovernanceModel::INDEPENDENT,
        };

        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($model);
        return $governance;
    }
}
