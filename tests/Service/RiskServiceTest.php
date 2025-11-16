<?php

namespace App\Tests\Service;

use App\Entity\CorporateGovernance;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\CorporateGovernanceRepository;
use App\Repository\RiskRepository;
use App\Service\CorporateStructureService;
use App\Service\RiskService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RiskServiceTest extends TestCase
{
    private MockObject $riskRepository;
    private MockObject $corporateStructureService;
    private MockObject $governanceRepository;
    private RiskService $service;

    protected function setUp(): void
    {
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->corporateStructureService = $this->createMock(CorporateStructureService::class);
        $this->governanceRepository = $this->createMock(CorporateGovernanceRepository::class);

        $this->service = new RiskService(
            $this->riskRepository,
            $this->corporateStructureService,
            $this->governanceRepository
        );
    }

    public function testGetRisksForTenantWithoutParent(): void
    {
        $tenant = $this->createTenant(1, null);
        $risks = [$this->createMock(Risk::class)];

        $this->riskRepository->method('findByTenant')
            ->with($tenant)
            ->willReturn($risks);

        $result = $this->service->getRisksForTenant($tenant);

        $this->assertSame($risks, $result);
    }

    public function testGetRisksForTenantWithHierarchicalGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $inheritedRisks = [
            $this->createMock(Risk::class),
            $this->createMock(Risk::class),
            $this->createMock(Risk::class),
        ];

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'risk')
            ->willReturn($governance);

        $this->riskRepository->method('findByTenantIncludingParent')
            ->with($child, $parent)
            ->willReturn($inheritedRisks);

        $result = $this->service->getRisksForTenant($child);

        $this->assertSame($inheritedRisks, $result);
        $this->assertCount(3, $result);
    }

    public function testGetRisksForTenantWithSharedGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownRisks = [$this->createMock(Risk::class)];

        $governance = $this->createGovernance('shared');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'risk')
            ->willReturn($governance);

        $this->riskRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownRisks);

        $result = $this->service->getRisksForTenant($child);

        $this->assertSame($ownRisks, $result);
    }

    public function testGetRisksForTenantFallbackToDefaultGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $inheritedRisks = [$this->createMock(Risk::class), $this->createMock(Risk::class)];

        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'risk')
            ->willReturn(null);

        $defaultGovernance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findDefaultGovernance')
            ->with($child)
            ->willReturn($defaultGovernance);

        $this->riskRepository->method('findByTenantIncludingParent')
            ->with($child, $parent)
            ->willReturn($inheritedRisks);

        $result = $this->service->getRisksForTenant($child);

        $this->assertSame($inheritedRisks, $result);
    }

    public function testGetRiskInheritanceInfoWithoutParent(): void
    {
        $tenant = $this->createTenant(1, null);

        $info = $this->service->getRiskInheritanceInfo($tenant);

        $this->assertFalse($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertNull($info['governanceModel']);
    }

    public function testGetRiskInheritanceInfoWithHierarchicalParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'risk')
            ->willReturn($governance);

        $info = $this->service->getRiskInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertTrue($info['canInherit']);
        $this->assertSame('hierarchical', $info['governanceModel']);
    }

    public function testGetRiskInheritanceInfoWithIndependentParent(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $governance = $this->createGovernance('independent');
        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'risk')
            ->willReturn($governance);

        $info = $this->service->getRiskInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertFalse($info['canInherit']);
        $this->assertSame('independent', $info['governanceModel']);
    }

    public function testIsInheritedRiskTrue(): void
    {
        $parentTenant = $this->createTenant(1, null);
        $childTenant = $this->createTenant(2, $parentTenant);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($parentTenant);

        $this->assertTrue($this->service->isInheritedRisk($risk, $childTenant));
    }

    public function testIsInheritedRiskFalse(): void
    {
        $tenant = $this->createTenant(1, null);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($tenant);

        $this->assertFalse($this->service->isInheritedRisk($risk, $tenant));
    }

    public function testIsInheritedRiskWithNullTenant(): void
    {
        $tenant = $this->createTenant(1, null);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn(null);

        $this->assertFalse($this->service->isInheritedRisk($risk, $tenant));
    }

    public function testIsInheritedRiskWithNullIds(): void
    {
        $tenant1 = $this->createTenant(null, null);
        $tenant2 = $this->createTenant(null, null);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($tenant1);

        // Both have null IDs (unsaved entities)
        $this->assertFalse($this->service->isInheritedRisk($risk, $tenant2));
    }

    public function testCanEditRiskOwnRisk(): void
    {
        $tenant = $this->createTenant(1, null);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($tenant);

        $this->assertTrue($this->service->canEditRisk($risk, $tenant));
    }

    public function testCanEditRiskInheritedRisk(): void
    {
        $parentTenant = $this->createTenant(1, null);
        $childTenant = $this->createTenant(2, $parentTenant);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTenant')->willReturn($parentTenant);

        $this->assertFalse($this->service->canEditRisk($risk, $childTenant));
    }

    public function testGetRiskStatsWithInheritance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        // Child has 2 own risks
        $ownRisks = [$this->createMock(Risk::class), $this->createMock(Risk::class)];

        // Total includes 3 inherited risks from parent
        $allRisks = array_merge($ownRisks, [
            $this->createMock(Risk::class),
            $this->createMock(Risk::class),
            $this->createMock(Risk::class),
        ]);

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->willReturn($governance);

        $this->riskRepository->method('findByTenantIncludingParent')
            ->willReturn($allRisks);

        $this->riskRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownRisks);

        $this->riskRepository->method('getRiskStatsByTenant')
            ->with($child)
            ->willReturn([
                'total' => 5,
                'high' => 2,
                'medium' => 2,
                'low' => 1,
            ]);

        $stats = $this->service->getRiskStatsWithInheritance($child);

        $this->assertSame(2, $stats['ownRisks']);
        $this->assertSame(3, $stats['inheritedRisks']);
        $this->assertSame(5, $stats['total']);
        $this->assertSame(2, $stats['high']);
    }

    public function testServiceWorksWithoutOptionalDependencies(): void
    {
        $simpleService = new RiskService($this->riskRepository, null, null);

        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);
        $ownRisks = [$this->createMock(Risk::class)];

        $this->riskRepository->method('findByTenant')
            ->with($child)
            ->willReturn($ownRisks);

        $result = $simpleService->getRisksForTenant($child);

        $this->assertSame($ownRisks, $result);
    }

    public function testRiskInheritanceInfoFallsBackToDefaultGovernance(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        $this->governanceRepository->method('findGovernanceForScope')
            ->with($child, 'risk')
            ->willReturn(null);

        $defaultGovernance = $this->createGovernance('shared');
        $this->governanceRepository->method('findDefaultGovernance')
            ->with($child)
            ->willReturn($defaultGovernance);

        $info = $this->service->getRiskInheritanceInfo($child);

        $this->assertTrue($info['hasParent']);
        $this->assertFalse($info['canInherit']); // shared doesn't allow inheritance
        $this->assertSame('shared', $info['governanceModel']);
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
