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

    // ========== HIGH RISK TESTS ==========

    public function testGetHighRisksForTenantFiltersCorrectly(): void
    {
        $tenant = $this->createTenant(1, null);

        // Create risks with different scores
        $highRisk1 = $this->createRiskWithScore(4, 4); // Score: 16 - HIGH
        $highRisk2 = $this->createRiskWithScore(3, 5); // Score: 15 - HIGH
        $mediumRisk = $this->createRiskWithScore(3, 3); // Score: 9 - NOT high
        $lowRisk = $this->createRiskWithScore(2, 2); // Score: 4 - NOT high

        $allRisks = [$highRisk1, $highRisk2, $mediumRisk, $lowRisk];

        $this->riskRepository->method('findByTenant')
            ->with($tenant)
            ->willReturn($allRisks);

        $result = $this->service->getHighRisksForTenant($tenant);

        $this->assertCount(2, $result);
        $this->assertContains($highRisk1, $result);
        $this->assertContains($highRisk2, $result);
        $this->assertNotContains($mediumRisk, $result);
        $this->assertNotContains($lowRisk, $result);
    }

    public function testGetHighRisksForTenantWithCustomThreshold(): void
    {
        $tenant = $this->createTenant(1, null);

        $risk1 = $this->createRiskWithScore(3, 3); // Score: 9
        $risk2 = $this->createRiskWithScore(2, 4); // Score: 8
        $risk3 = $this->createRiskWithScore(2, 2); // Score: 4

        $this->riskRepository->method('findByTenant')
            ->willReturn([$risk1, $risk2, $risk3]);

        // Custom threshold of 8
        $result = $this->service->getHighRisksForTenant($tenant, 8);

        $this->assertCount(2, $result);
        $this->assertContains($risk1, $result);
        $this->assertContains($risk2, $result);
    }

    public function testGetHighRisksForTenantWithNullProbabilityOrImpact(): void
    {
        $tenant = $this->createTenant(1, null);

        $riskWithNullProbability = $this->createRiskWithScore(null, 5); // Score: 0
        $riskWithNullImpact = $this->createRiskWithScore(5, null); // Score: 0
        $riskWithBothNull = $this->createRiskWithScore(null, null); // Score: 0
        $normalHighRisk = $this->createRiskWithScore(4, 4); // Score: 16

        $this->riskRepository->method('findByTenant')
            ->willReturn([$riskWithNullProbability, $riskWithNullImpact, $riskWithBothNull, $normalHighRisk]);

        $result = $this->service->getHighRisksForTenant($tenant);

        $this->assertCount(1, $result);
        $this->assertContains($normalHighRisk, $result);
    }

    public function testGetHighRisksForTenantReturnsEmptyWhenNoHighRisks(): void
    {
        $tenant = $this->createTenant(1, null);

        $lowRisk1 = $this->createRiskWithScore(2, 2); // Score: 4
        $lowRisk2 = $this->createRiskWithScore(1, 3); // Score: 3

        $this->riskRepository->method('findByTenant')
            ->willReturn([$lowRisk1, $lowRisk2]);

        $result = $this->service->getHighRisksForTenant($tenant);

        $this->assertCount(0, $result);
    }

    public function testGetHighRisksForTenantIncludesInheritedRisks(): void
    {
        $parent = $this->createTenant(1, null);
        $child = $this->createTenant(2, $parent);

        // Parent has high risk, child has low risk
        $parentHighRisk = $this->createRiskWithScore(5, 5); // Score: 25
        $childLowRisk = $this->createRiskWithScore(2, 2); // Score: 4

        $governance = $this->createGovernance('hierarchical');
        $this->governanceRepository->method('findGovernanceForScope')
            ->willReturn($governance);

        // With hierarchical governance, both risks are returned
        $this->riskRepository->method('findByTenantIncludingParent')
            ->willReturn([$parentHighRisk, $childLowRisk]);

        $result = $this->service->getHighRisksForTenant($child);

        $this->assertCount(1, $result);
        $this->assertContains($parentHighRisk, $result);
    }

    public function testGetHighRisksForTenantEdgeCaseExactThreshold(): void
    {
        $tenant = $this->createTenant(1, null);

        $exactThresholdRisk = $this->createRiskWithScore(3, 4); // Score: 12 - exactly at default threshold
        $belowThresholdRisk = $this->createRiskWithScore(3, 3); // Score: 9 - below threshold

        $this->riskRepository->method('findByTenant')
            ->willReturn([$exactThresholdRisk, $belowThresholdRisk]);

        $result = $this->service->getHighRisksForTenant($tenant);

        // Threshold is >= 12, so exactly 12 should be included
        $this->assertCount(1, $result);
        $this->assertContains($exactThresholdRisk, $result);
    }

    // ========== HELPER METHODS ==========

    private function createTenant(?int $id, ?Tenant $parent): MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getParent')->willReturn($parent);
        return $tenant;
    }

    private function createRiskWithScore(?int $probability, ?int $impact): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getProbability')->willReturn($probability);
        $risk->method('getImpact')->willReturn($impact);
        return $risk;
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
