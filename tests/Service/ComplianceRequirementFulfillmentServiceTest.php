<?php

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\CorporateGovernance;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\CorporateGovernanceRepository;
use App\Service\ComplianceRequirementFulfillmentService;
use App\Service\CorporateStructureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ComplianceRequirementFulfillmentServiceTest extends TestCase
{
    private MockObject $fulfillmentRepository;
    private MockObject $corporateStructureService;
    private MockObject $corporateGovernanceRepository;
    private ComplianceRequirementFulfillmentService $service;
    private MockObject $tenant;
    private MockObject $parentTenant;

    protected function setUp(): void
    {
        $this->fulfillmentRepository = $this->createMock(ComplianceRequirementFulfillmentRepository::class);
        $this->corporateStructureService = $this->createMock(CorporateStructureService::class);
        $this->corporateGovernanceRepository = $this->createMock(CorporateGovernanceRepository::class);

        $this->parentTenant = $this->createMock(Tenant::class);
        $this->parentTenant->method('getId')->willReturn(1);

        $this->tenant = $this->createMock(Tenant::class);
        $this->tenant->method('getId')->willReturn(2);

        $this->service = new ComplianceRequirementFulfillmentService(
            $this->fulfillmentRepository,
            $this->corporateStructureService,
            $this->corporateGovernanceRepository
        );
    }

    // =========================================================================
    // getFulfillmentsForTenant Tests
    // =========================================================================

    public function testGetFulfillmentsForTenantReturnsOwnFulfillmentsWithoutParent(): void
    {
        $this->tenant->method('getParent')->willReturn(null);

        $fulfillments = [
            $this->createMock(ComplianceRequirementFulfillment::class),
            $this->createMock(ComplianceRequirementFulfillment::class),
        ];

        $this->fulfillmentRepository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn($fulfillments);

        $result = $this->service->getFulfillmentsForTenant($this->tenant);

        $this->assertCount(2, $result);
        $this->assertSame($fulfillments, $result);
    }

    public function testGetFulfillmentsForTenantReturnsOwnFulfillmentsWithFrameworkFilter(): void
    {
        $this->tenant->method('getParent')->willReturn(null);

        $framework = $this->createMock(ComplianceFramework::class);
        $fulfillments = [$this->createMock(ComplianceRequirementFulfillment::class)];

        $this->fulfillmentRepository->method('findByFrameworkAndTenant')
            ->with($framework, $this->tenant)
            ->willReturn($fulfillments);

        $result = $this->service->getFulfillmentsForTenant($this->tenant, $framework);

        $this->assertCount(1, $result);
    }

    public function testGetFulfillmentsForTenantIncludesParentFulfillmentsWithHierarchicalGovernance(): void
    {
        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $governanceModel = GovernanceModel::HIERARCHICAL;
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($governanceModel);

        $this->corporateGovernanceRepository->method('findGovernanceForScope')
            ->with($this->tenant, 'compliance')
            ->willReturn($governance);

        $parentFulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $ownFulfillment = $this->createMock(ComplianceRequirementFulfillment::class);

        $this->fulfillmentRepository->method('findByTenantIncludingParent')
            ->with($this->tenant, $this->parentTenant)
            ->willReturn([$parentFulfillment, $ownFulfillment]);

        $result = $this->service->getFulfillmentsForTenant($this->tenant);

        $this->assertCount(2, $result);
    }

    public function testGetFulfillmentsForTenantExcludesParentFulfillmentsWithSharedGovernance(): void
    {
        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $governanceModel = GovernanceModel::SHARED;
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($governanceModel);

        $this->corporateGovernanceRepository->method('findGovernanceForScope')
            ->with($this->tenant, 'compliance')
            ->willReturn($governance);

        $ownFulfillments = [$this->createMock(ComplianceRequirementFulfillment::class)];

        $this->fulfillmentRepository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn($ownFulfillments);

        $result = $this->service->getFulfillmentsForTenant($this->tenant);

        $this->assertCount(1, $result);
    }

    public function testGetFulfillmentsForTenantUsesDefaultGovernanceWhenNoSpecificScope(): void
    {
        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $this->corporateGovernanceRepository->method('findGovernanceForScope')
            ->with($this->tenant, 'compliance')
            ->willReturn(null);

        $governanceModel = GovernanceModel::INDEPENDENT;
        $defaultGovernance = $this->createMock(CorporateGovernance::class);
        $defaultGovernance->method('getGovernanceModel')->willReturn($governanceModel);

        $this->corporateGovernanceRepository->method('findDefaultGovernance')
            ->with($this->tenant)
            ->willReturn($defaultGovernance);

        $ownFulfillments = [$this->createMock(ComplianceRequirementFulfillment::class)];

        $this->fulfillmentRepository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn($ownFulfillments);

        $result = $this->service->getFulfillmentsForTenant($this->tenant);

        $this->assertCount(1, $result);
    }

    public function testGetFulfillmentsForTenantFiltersParentFulfillmentsByFramework(): void
    {
        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $governanceModel = GovernanceModel::HIERARCHICAL;
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($governanceModel);

        $this->corporateGovernanceRepository->method('findGovernanceForScope')
            ->with($this->tenant, 'compliance')
            ->willReturn($governance);

        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('getId')->willReturn(1);

        $matchingRequirement = $this->createMock(ComplianceRequirement::class);
        $matchingFramework = $this->createMock(ComplianceFramework::class);
        $matchingFramework->method('getId')->willReturn(1);
        $matchingRequirement->method('getFramework')->willReturn($matchingFramework);

        $nonMatchingRequirement = $this->createMock(ComplianceRequirement::class);
        $nonMatchingFramework = $this->createMock(ComplianceFramework::class);
        $nonMatchingFramework->method('getId')->willReturn(2);
        $nonMatchingRequirement->method('getFramework')->willReturn($nonMatchingFramework);

        $matchingFulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $matchingFulfillment->method('getRequirement')->willReturn($matchingRequirement);

        $nonMatchingFulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $nonMatchingFulfillment->method('getRequirement')->willReturn($nonMatchingRequirement);

        $this->fulfillmentRepository->method('findByTenantIncludingParent')
            ->with($this->tenant, $this->parentTenant)
            ->willReturn([$matchingFulfillment, $nonMatchingFulfillment]);

        $result = $this->service->getFulfillmentsForTenant($this->tenant, $framework);

        $this->assertCount(1, $result);
        $this->assertSame($matchingFulfillment, $result[0]);
    }

    // =========================================================================
    // getFulfillmentInheritanceInfo Tests
    // =========================================================================

    public function testGetFulfillmentInheritanceInfoReturnsNoInheritanceWithoutParent(): void
    {
        $this->tenant->method('getParent')->willReturn(null);

        $result = $this->service->getFulfillmentInheritanceInfo($this->tenant);

        $this->assertFalse($result['hasParent']);
        $this->assertFalse($result['canInherit']);
        $this->assertNull($result['governanceModel']);
    }

    public function testGetFulfillmentInheritanceInfoWithHierarchicalGovernance(): void
    {
        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $governanceModel = GovernanceModel::HIERARCHICAL;
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($governanceModel);

        $this->corporateGovernanceRepository->method('findGovernanceForScope')
            ->with($this->tenant, 'compliance')
            ->willReturn($governance);

        $result = $this->service->getFulfillmentInheritanceInfo($this->tenant);

        $this->assertTrue($result['hasParent']);
        $this->assertTrue($result['canInherit']);
        $this->assertSame('hierarchical', $result['governanceModel']);
    }

    public function testGetFulfillmentInheritanceInfoWithSharedGovernance(): void
    {
        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $governanceModel = GovernanceModel::SHARED;
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($governanceModel);

        $this->corporateGovernanceRepository->method('findGovernanceForScope')
            ->with($this->tenant, 'compliance')
            ->willReturn($governance);

        $result = $this->service->getFulfillmentInheritanceInfo($this->tenant);

        $this->assertTrue($result['hasParent']);
        $this->assertFalse($result['canInherit']);
        $this->assertSame('shared', $result['governanceModel']);
    }

    public function testGetFulfillmentInheritanceInfoWithIndependentGovernance(): void
    {
        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $governanceModel = GovernanceModel::INDEPENDENT;
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($governanceModel);

        $this->corporateGovernanceRepository->method('findGovernanceForScope')
            ->with($this->tenant, 'compliance')
            ->willReturn($governance);

        $result = $this->service->getFulfillmentInheritanceInfo($this->tenant);

        $this->assertTrue($result['hasParent']);
        $this->assertFalse($result['canInherit']);
        $this->assertSame('independent', $result['governanceModel']);
    }

    // =========================================================================
    // isInheritedFulfillment Tests
    // =========================================================================

    public function testIsInheritedFulfillmentReturnsFalseForOwnFulfillment(): void
    {
        $fulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $fulfillment->method('getTenant')->willReturn($this->tenant);

        $result = $this->service->isInheritedFulfillment($fulfillment, $this->tenant);

        $this->assertFalse($result);
    }

    public function testIsInheritedFulfillmentReturnsTrueForParentFulfillment(): void
    {
        $fulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $fulfillment->method('getTenant')->willReturn($this->parentTenant);

        $result = $this->service->isInheritedFulfillment($fulfillment, $this->tenant);

        $this->assertTrue($result);
    }

    public function testIsInheritedFulfillmentReturnsFalseForNullTenant(): void
    {
        $fulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $fulfillment->method('getTenant')->willReturn(null);

        $result = $this->service->isInheritedFulfillment($fulfillment, $this->tenant);

        $this->assertFalse($result);
    }

    public function testIsInheritedFulfillmentReturnsFalseForNullIds(): void
    {
        $unsavedTenant = $this->createMock(Tenant::class);
        $unsavedTenant->method('getId')->willReturn(null);

        $fulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $fulfillment->method('getTenant')->willReturn($unsavedTenant);

        $result = $this->service->isInheritedFulfillment($fulfillment, $this->tenant);

        $this->assertFalse($result);
    }

    // =========================================================================
    // canEditFulfillment Tests
    // =========================================================================

    public function testCanEditFulfillmentReturnsTrueForOwnFulfillment(): void
    {
        $fulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $fulfillment->method('getTenant')->willReturn($this->tenant);

        $result = $this->service->canEditFulfillment($fulfillment, $this->tenant);

        $this->assertTrue($result);
    }

    public function testCanEditFulfillmentReturnsFalseForInheritedFulfillment(): void
    {
        $fulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $fulfillment->method('getTenant')->willReturn($this->parentTenant);

        $result = $this->service->canEditFulfillment($fulfillment, $this->tenant);

        $this->assertFalse($result);
    }

    // =========================================================================
    // getOrCreateFulfillment Tests
    // =========================================================================

    public function testGetOrCreateFulfillmentReturnsExistingFulfillment(): void
    {
        $this->tenant->method('getParent')->willReturn(null);

        $requirement = $this->createMock(ComplianceRequirement::class);

        $existingFulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $existingFulfillment->method('getId')->willReturn(1);

        $this->fulfillmentRepository->method('findOrCreateForTenantAndRequirement')
            ->with($this->tenant, $requirement)
            ->willReturn($existingFulfillment);

        $result = $this->service->getOrCreateFulfillment($this->tenant, $requirement);

        $this->assertSame($existingFulfillment, $result);
    }

    public function testGetOrCreateFulfillmentInheritsFromParentWithHierarchicalGovernance(): void
    {
        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $requirement = $this->createMock(ComplianceRequirement::class);

        // New fulfillment (no ID yet)
        $newFulfillment = new ComplianceRequirementFulfillment();
        $newFulfillment->setTenant($this->tenant);
        $newFulfillment->setRequirement($requirement);

        $this->fulfillmentRepository->method('findOrCreateForTenantAndRequirement')
            ->with($this->tenant, $requirement)
            ->willReturn($newFulfillment);

        // Setup hierarchical governance
        $governanceModel = GovernanceModel::HIERARCHICAL;
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($governanceModel);

        $this->corporateGovernanceRepository->method('findGovernanceForScope')
            ->with($this->tenant, 'compliance')
            ->willReturn($governance);

        // Parent fulfillment to inherit from
        $parentFulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $parentFulfillment->method('isApplicable')->willReturn(true);
        $parentFulfillment->method('getApplicabilityJustification')->willReturn('Inherited justification');

        $this->fulfillmentRepository->method('findOneBy')
            ->with([
                'tenant' => $this->parentTenant,
                'requirement' => $requirement,
            ])
            ->willReturn($parentFulfillment);

        $result = $this->service->getOrCreateFulfillment($this->tenant, $requirement);

        $this->assertTrue($result->isApplicable());
        $this->assertSame('Inherited justification', $result->getApplicabilityJustification());
    }

    public function testGetOrCreateFulfillmentDoesNotInheritWithIndependentGovernance(): void
    {
        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $requirement = $this->createMock(ComplianceRequirement::class);

        // New fulfillment (no ID yet) - use mock to track if setApplicable is called
        $newFulfillment = $this->createMock(ComplianceRequirementFulfillment::class);
        $newFulfillment->method('getId')->willReturn(null);

        // Should NOT call setApplicable since we're not inheriting
        $newFulfillment->expects($this->never())->method('setApplicable');
        $newFulfillment->expects($this->never())->method('setApplicabilityJustification');

        $this->fulfillmentRepository->method('findOrCreateForTenantAndRequirement')
            ->with($this->tenant, $requirement)
            ->willReturn($newFulfillment);

        // Setup independent governance
        $governanceModel = GovernanceModel::INDEPENDENT;
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($governanceModel);

        $this->corporateGovernanceRepository->method('findGovernanceForScope')
            ->with($this->tenant, 'compliance')
            ->willReturn($governance);

        $result = $this->service->getOrCreateFulfillment($this->tenant, $requirement);

        // Should return fulfillment without having called inheritance methods
        $this->assertSame($newFulfillment, $result);
    }

    // =========================================================================
    // getFulfillmentStatsWithInheritance Tests
    // =========================================================================

    public function testGetFulfillmentStatsWithInheritanceReturnsCorrectCounts(): void
    {
        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $governanceModel = GovernanceModel::HIERARCHICAL;
        $governance = $this->createMock(CorporateGovernance::class);
        $governance->method('getGovernanceModel')->willReturn($governanceModel);

        $this->corporateGovernanceRepository->method('findGovernanceForScope')
            ->with($this->tenant, 'compliance')
            ->willReturn($governance);

        // All fulfillments (including inherited)
        $allFulfillments = [
            $this->createMock(ComplianceRequirementFulfillment::class),
            $this->createMock(ComplianceRequirementFulfillment::class),
            $this->createMock(ComplianceRequirementFulfillment::class),
        ];

        // Own fulfillments only
        $ownFulfillments = [
            $this->createMock(ComplianceRequirementFulfillment::class),
        ];

        $this->fulfillmentRepository->method('findByTenantIncludingParent')
            ->with($this->tenant, $this->parentTenant)
            ->willReturn($allFulfillments);

        $this->fulfillmentRepository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn($ownFulfillments);

        $baseStats = [
            'total' => 10,
            'fulfilled' => 5,
            'avg_fulfillment' => 50.0,
        ];

        $this->fulfillmentRepository->method('getComplianceStats')
            ->with($this->tenant)
            ->willReturn($baseStats);

        $result = $this->service->getFulfillmentStatsWithInheritance($this->tenant);

        $this->assertSame(1, $result['own']);
        $this->assertSame(2, $result['inherited']);
        $this->assertSame(10, $result['total']);
        $this->assertSame(50.0, $result['avg_fulfillment']);
    }

    public function testGetFulfillmentStatsWithInheritanceWithFrameworkFilter(): void
    {
        $this->tenant->method('getParent')->willReturn(null);

        $framework = $this->createMock(ComplianceFramework::class);

        $fulfillments = [
            $this->createMock(ComplianceRequirementFulfillment::class),
        ];

        $this->fulfillmentRepository->method('findByFrameworkAndTenant')
            ->with($framework, $this->tenant)
            ->willReturn($fulfillments);

        $baseStats = [
            'total' => 5,
            'fulfilled' => 3,
            'avg_fulfillment' => 60.0,
        ];

        $this->fulfillmentRepository->method('getComplianceStats')
            ->with($this->tenant)
            ->willReturn($baseStats);

        $result = $this->service->getFulfillmentStatsWithInheritance($this->tenant, $framework);

        $this->assertSame(1, $result['own']);
        $this->assertSame(0, $result['inherited']);
    }

    // =========================================================================
    // Service without optional dependencies
    // =========================================================================

    public function testServiceWorksWithoutCorporateStructureService(): void
    {
        $serviceWithoutOptional = new ComplianceRequirementFulfillmentService(
            $this->fulfillmentRepository,
            null,
            null
        );

        $this->tenant->method('getParent')->willReturn($this->parentTenant);

        $fulfillments = [$this->createMock(ComplianceRequirementFulfillment::class)];

        $this->fulfillmentRepository->method('findByTenant')
            ->with($this->tenant)
            ->willReturn($fulfillments);

        $result = $serviceWithoutOptional->getFulfillmentsForTenant($this->tenant);

        // Should return only own fulfillments when corporate structure service is not available
        $this->assertCount(1, $result);
    }
}
