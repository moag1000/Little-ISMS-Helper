<?php

namespace App\Tests\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Service\ComplianceAnalyticsService;
use App\Service\ComplianceAssessmentService;
use App\Service\TenantContext;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ComplianceAnalyticsService
 *
 * Phase 7B: Tests for multi-framework compliance analytics
 */
class ComplianceAnalyticsServiceTest extends TestCase
{
    private MockObject $frameworkRepository;
    private MockObject $requirementRepository;
    private MockObject $mappingRepository;
    private MockObject $controlRepository;
    private MockObject $tenantContext;
    private MockObject $assessmentService;
    private ComplianceAnalyticsService $service;

    protected function setUp(): void
    {
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->requirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->mappingRepository = $this->createMock(ComplianceMappingRepository::class);
        $this->controlRepository = $this->createMock(ControlRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->assessmentService = $this->createMock(ComplianceAssessmentService::class);

        $this->service = new ComplianceAnalyticsService(
            $this->frameworkRepository,
            $this->requirementRepository,
            $this->mappingRepository,
            $this->controlRepository,
            $this->tenantContext,
            $this->assessmentService,
        );
    }

    // ==================== getFrameworkComparison() Tests ====================

    public function testGetFrameworkComparisonWithData(): void
    {
        $tenant = $this->createTenant(1, 'Test Tenant');
        $framework = $this->createFramework(1, 'ISO 27001', 'ISO27001', true);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);
        $this->requirementRepository->method('getFrameworkStatisticsForTenant')
            ->willReturn([
                'total' => 100,
                'applicable' => 80,
                'fulfilled' => 60,
                'in_progress' => 10,
            ]);

        $result = $this->service->getFrameworkComparison();

        $this->assertArrayHasKey('frameworks', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertCount(1, $result['frameworks']);

        $framework = $result['frameworks'][0];
        $this->assertEquals('ISO 27001', $framework['name']);
        $this->assertEquals(75.0, $framework['compliance_percentage']); // 60/80 * 100
        $this->assertEquals(60, $framework['fulfilled']);
        $this->assertEquals(10, $framework['not_started']); // 80 - 60 - 10
    }

    public function testGetFrameworkComparisonWithNoTenant(): void
    {
        $framework = $this->createFramework(1, 'ISO 27001', 'ISO27001', false);

        $this->tenantContext->method('getCurrentTenant')->willReturn(null);
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);

        $result = $this->service->getFrameworkComparison();

        $this->assertArrayHasKey('frameworks', $result);
        $this->assertCount(1, $result['frameworks']);
        $this->assertEquals(0, $result['frameworks'][0]['compliance_percentage']);
    }

    public function testGetFrameworkComparisonWithEmptyFrameworks(): void
    {
        $tenant = $this->createTenant(1, 'Test');

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([]);

        $result = $this->service->getFrameworkComparison();

        $this->assertArrayHasKey('frameworks', $result);
        $this->assertCount(0, $result['frameworks']);
        $this->assertEquals(0, $result['summary']['average_compliance']);
    }

    public function testGetFrameworkComparisonSortsDescending(): void
    {
        $tenant = $this->createTenant(1, 'Test');
        $fw1 = $this->createFramework(1, 'Low', 'LOW', false);
        $fw2 = $this->createFramework(2, 'High', 'HIGH', false);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$fw1, $fw2]);

        $this->requirementRepository->method('getFrameworkStatisticsForTenant')
            ->willReturnCallback(function ($fw) {
                return $fw->getCode() === 'HIGH'
                    ? ['total' => 10, 'applicable' => 10, 'fulfilled' => 9, 'in_progress' => 0]
                    : ['total' => 10, 'applicable' => 10, 'fulfilled' => 3, 'in_progress' => 0];
            });

        $result = $this->service->getFrameworkComparison();

        $this->assertEquals('High', $result['frameworks'][0]['name']);
        $this->assertEquals('Low', $result['frameworks'][1]['name']);
    }

    // ==================== getControlCoverageMatrix() Tests ====================

    public function testGetControlCoverageMatrixWithControls(): void
    {
        $framework = $this->createFramework(1, 'ISO 27001', 'ISO27001', false);
        $control = $this->createControl('A.5.1', 'Test Control', 'implemented');
        $requirement = $this->createRequirement('5.1', 'Test Req', $framework);

        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);
        $this->controlRepository->method('findAll')->willReturn([$control]);
        $this->requirementRepository->method('findByControl')->willReturn([$requirement]);

        $result = $this->service->getControlCoverageMatrix();

        $this->assertArrayHasKey('matrix', $result);
        $this->assertArrayHasKey('controls', $result);
        $this->assertArrayHasKey('total_controls', $result);
        $this->assertEquals(1, $result['total_controls']);
    }

    public function testGetControlCoverageMatrixWithEmptyControls(): void
    {
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([]);
        $this->controlRepository->method('findAll')->willReturn([]);

        $result = $this->service->getControlCoverageMatrix();

        $this->assertEquals(0, $result['total_controls']);
        $this->assertCount(0, $result['controls']);
    }

    // ==================== getFrameworkOverlap() Tests ====================

    public function testGetFrameworkOverlapWithTwoFrameworks(): void
    {
        $fw1 = $this->createFramework(1, 'ISO 27001', 'ISO', false);
        $fw2 = $this->createFramework(2, 'TISAX', 'TISAX', false);

        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$fw1, $fw2]);
        $this->requirementRepository->method('findByFramework')
            ->willReturn([]);

        $result = $this->service->getFrameworkOverlap();

        $this->assertArrayHasKey('overlaps', $result);
        $this->assertArrayHasKey('frameworks', $result);
    }

    public function testGetFrameworkOverlapWithSingleFramework(): void
    {
        $fw = $this->createFramework(1, 'ISO 27001', 'ISO', false);

        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$fw]);
        $this->requirementRepository->method('findByFramework')->willReturn([]);

        $result = $this->service->getFrameworkOverlap();

        $this->assertCount(0, $result['overlaps']); // No overlap possible with single framework
    }

    // ==================== getGapAnalysis() Tests ====================

    public function testGetGapAnalysisGroupsByPriority(): void
    {
        $framework = $this->createFramework(1, 'ISO', 'ISO', false);
        $gap1 = $this->createRequirementWithPriority('1', 'Critical Gap', 'critical', 20.0);
        $gap2 = $this->createRequirementWithPriority('2', 'High Gap', 'high', 50.0);

        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);
        $this->requirementRepository->method('findGapsByFramework')->willReturn([$gap1, $gap2]);

        $result = $this->service->getGapAnalysis();

        $this->assertArrayHasKey('by_priority', $result);
        $this->assertArrayHasKey('critical', $result['by_priority']);
        $this->assertArrayHasKey('high', $result['by_priority']);
        $this->assertEquals(2, $result['summary']['total_gaps']);
    }

    public function testGetGapAnalysisWithNoGaps(): void
    {
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([]);

        $result = $this->service->getGapAnalysis();

        $this->assertEquals(0, $result['summary']['total_gaps']);
    }

    // ==================== getTransitiveCompliance() Tests ====================

    public function testGetTransitiveComplianceFindsMultiFrameworkControls(): void
    {
        $control = $this->createControl('A.5.1', 'Test', 'implemented');
        $fw1 = $this->createFramework(1, 'ISO', 'ISO', false);
        $fw2 = $this->createFramework(2, 'TISAX', 'TISAX', false);
        $req1 = $this->createRequirement('1', 'Req 1', $fw1);
        $req2 = $this->createRequirement('2', 'Req 2', $fw2);

        $this->controlRepository->method('findBy')
            ->with(['implementationStatus' => 'implemented'])
            ->willReturn([$control]);
        $this->requirementRepository->method('findByControl')
            ->willReturn([$req1, $req2]);

        $result = $this->service->getTransitiveCompliance();

        $this->assertArrayHasKey('controls', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertGreaterThanOrEqual(1, $result['summary']['multi_framework_controls']);
    }

    public function testGetTransitiveComplianceWithNoImplementedControls(): void
    {
        $this->controlRepository->method('findBy')->willReturn([]);

        $result = $this->service->getTransitiveCompliance();

        $this->assertEquals(0, $result['summary']['multi_framework_controls']);
    }

    // ==================== getComplianceRoadmap() Tests ====================

    public function testGetComplianceRoadmapWithFrameworks(): void
    {
        $tenant = $this->createTenant(1, 'Test');
        $framework = $this->createFramework(1, 'ISO', 'ISO', true);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);
        $this->requirementRepository->method('getFrameworkStatisticsForTenant')
            ->willReturn(['total' => 100, 'applicable' => 50, 'fulfilled' => 25]);

        $result = $this->service->getComplianceRoadmap();

        $this->assertArrayHasKey('roadmap', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals(50.0, $result['roadmap'][0]['current_compliance']);
    }

    public function testGetComplianceRoadmapWithNoTenant(): void
    {
        $framework = $this->createFramework(1, 'ISO', 'ISO', false);

        $this->tenantContext->method('getCurrentTenant')->willReturn(null);
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);

        $result = $this->service->getComplianceRoadmap();

        $this->assertArrayHasKey('roadmap', $result);
        $this->assertEquals(0, $result['roadmap'][0]['current_compliance']);
    }

    // ==================== getExecutiveSummary() Tests ====================

    public function testGetExecutiveSummaryReturnsAllMetrics(): void
    {
        $tenant = $this->createTenant(1, 'Test');
        $framework = $this->createFramework(1, 'ISO', 'ISO', false);

        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([$framework]);
        $this->requirementRepository->method('getFrameworkStatisticsForTenant')
            ->willReturn(['total' => 10, 'applicable' => 10, 'fulfilled' => 8, 'in_progress' => 1]);
        $this->requirementRepository->method('findGapsByFramework')->willReturn([]);
        $this->controlRepository->method('findBy')->willReturn([]);

        $result = $this->service->getExecutiveSummary();

        $this->assertArrayHasKey('overall_compliance', $result);
        $this->assertArrayHasKey('frameworks', $result);
        $this->assertArrayHasKey('gaps', $result);
        $this->assertArrayHasKey('efficiency', $result);
        $this->assertArrayHasKey('trend', $result);
    }

    // ==================== Helper Methods ====================

    private function createTenant(int $id, string $name): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getName')->willReturn($name);
        return $tenant;
    }

    private function createFramework(int $id, string $name, string $code, bool $mandatory): ComplianceFramework
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->id = $id;
        $framework->method('getName')->willReturn($name);
        $framework->method('getCode')->willReturn($code);
        $framework->method('getVersion')->willReturn('1.0');
        $framework->method('isMandatory')->willReturn($mandatory);
        return $framework;
    }

    private function createControl(string $controlId, string $name, string $status): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('getId')->willReturn(1);
        $control->method('getControlId')->willReturn($controlId);
        $control->method('getName')->willReturn($name);
        $control->method('getImplementationStatus')->willReturn($status);
        $control->method('getCategory')->willReturn('A.5');
        return $control;
    }

    private function createRequirement(string $reqId, string $title, ComplianceFramework $framework): ComplianceRequirement
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn($reqId);
        $requirement->method('getTitle')->willReturn($title);
        $requirement->method('getFramework')->willReturn($framework);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());
        return $requirement;
    }

    private function createRequirementWithPriority(string $reqId, string $title, string $priority, float $fulfillment): ComplianceRequirement
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getRequirementId')->willReturn($reqId);
        $requirement->method('getTitle')->willReturn($title);
        $requirement->method('getCategory')->willReturn('Test');
        $requirement->method('getPriority')->willReturn($priority);
        $requirement->method('getFulfillmentPercentage')->willReturn($fulfillment);
        $requirement->method('getMappedControls')->willReturn(new ArrayCollection());
        return $requirement;
    }
}
