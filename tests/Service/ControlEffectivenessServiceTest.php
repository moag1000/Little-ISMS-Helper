<?php

namespace App\Tests\Service;

use App\Entity\Control;
use App\Entity\Risk;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\ControlEffectivenessService;
use App\Service\TenantContext;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ControlEffectivenessService
 *
 * Phase 7B: Tests for control performance analytics
 */
class ControlEffectivenessServiceTest extends TestCase
{
    private MockObject $controlRepository;
    private MockObject $riskRepository;
    private MockObject $incidentRepository;
    private MockObject $requirementRepository;
    private MockObject $tenantContext;
    private ControlEffectivenessService $service;

    protected function setUp(): void
    {
        $this->controlRepository = $this->createMock(ControlRepository::class);
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->requirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        $this->service = new ControlEffectivenessService(
            $this->controlRepository,
            $this->riskRepository,
            $this->incidentRepository,
            $this->requirementRepository,
            $this->tenantContext,
        );
    }

    // ==================== getEffectivenessDashboard() Tests ====================

    public function testGetEffectivenessDashboardReturnsAllSections(): void
    {
        $control = $this->createControl('A.5.1', 'Test', 'implemented', 100);

        $this->controlRepository->method('findAll')->willReturn([$control]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $result = $this->service->getEffectivenessDashboard();

        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('performance', $result);
        $this->assertArrayHasKey('orphaned_controls', $result);
        $this->assertArrayHasKey('aging_analysis', $result);
        $this->assertArrayHasKey('implementation_status', $result);
    }

    public function testGetEffectivenessDashboardWithEmptyControls(): void
    {
        $this->controlRepository->method('findAll')->willReturn([]);

        $result = $this->service->getEffectivenessDashboard();

        $this->assertEquals(0, $result['metrics']['total_controls']);
        $this->assertEquals(0, $result['metrics']['implementation_rate']);
        $this->assertEquals(0, $result['metrics']['average_effectiveness']);
    }

    public function testEffectivenessMetricsCalculation(): void
    {
        $implemented = $this->createControl('A.5.1', 'Impl', 'implemented', 100);
        $partial = $this->createControl('A.5.2', 'Partial', 'partially_implemented', 50);
        $planned = $this->createControl('A.5.3', 'Planned', 'planned', 0);

        $this->controlRepository->method('findAll')->willReturn([$implemented, $partial, $planned]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $result = $this->service->getEffectivenessDashboard();
        $metrics = $result['metrics'];

        $this->assertEquals(3, $metrics['total_controls']);
        $this->assertEquals(1, $metrics['implemented']);
        $this->assertEquals(33.3, $metrics['implementation_rate']); // 1/3 = 33.3%
    }

    // ==================== calculateControlEffectiveness() Tests ====================

    public function testCalculateControlEffectivenessForImplementedControl(): void
    {
        $control = $this->createControl('A.5.1', 'Test', 'implemented', 100);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $score = $this->service->calculateControlEffectiveness($control);

        // Score breakdown:
        // - Implementation status: 30 (implemented)
        // - Implementation percentage: 20 (100%)
        // - No risks: 0
        // - No requirements: 0
        // - No review: 0
        $this->assertGreaterThanOrEqual(50, $score);
    }

    public function testCalculateControlEffectivenessForPartialControl(): void
    {
        $control = $this->createControl('A.5.1', 'Test', 'partially_implemented', 50);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $score = $this->service->calculateControlEffectiveness($control);

        // Should be lower than implemented
        $this->assertLessThan(50, $score);
    }

    public function testCalculateControlEffectivenessWithLinkedRisks(): void
    {
        $risk = $this->createMock(Risk::class);
        $control = $this->createControlWithRisks('A.5.1', 'Test', 'implemented', 100, [$risk]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $score = $this->service->calculateControlEffectiveness($control);

        // Should include risk reduction score
        $this->assertGreaterThanOrEqual(55, $score);
    }

    public function testCalculateControlEffectivenessWithRecentReview(): void
    {
        $control = $this->createControlWithReview('A.5.1', 'Test', 'implemented', 100, new \DateTime('-30 days'));
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $score = $this->service->calculateControlEffectiveness($control);

        // Should include review freshness score
        $this->assertGreaterThanOrEqual(60, $score);
    }

    public function testCalculateControlEffectivenessNeverExceeds100(): void
    {
        // Create optimal control
        $risks = [
            $this->createMock(Risk::class),
            $this->createMock(Risk::class),
            $this->createMock(Risk::class),
            $this->createMock(Risk::class),
            $this->createMock(Risk::class),
        ];
        $control = $this->createControlWithRisksAndReview('A.5.1', 'Test', 'implemented', 100, $risks, new \DateTime('-1 day'));

        // Mock many requirements
        $this->requirementRepository->method('findByControl')
            ->willReturn(array_fill(0, 10, $this->createMock(\App\Entity\ComplianceRequirement::class)));

        $score = $this->service->calculateControlEffectiveness($control);

        $this->assertLessThanOrEqual(100, $score);
    }

    // ==================== getControlPerformance() Tests ====================

    public function testGetControlPerformanceRanking(): void
    {
        $high = $this->createControl('A.5.1', 'High', 'implemented', 100);
        $low = $this->createControl('A.5.2', 'Low', 'not_implemented', 0);

        $this->controlRepository->method('findAll')->willReturn([$low, $high]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $result = $this->service->getEffectivenessDashboard();
        $performance = $result['performance'];

        $this->assertArrayHasKey('top_performing', $performance);
        $this->assertArrayHasKey('bottom_performing', $performance);
        $this->assertEquals('A.5.1', $performance['top_performing'][0]['control_id']);
    }

    // ==================== Orphaned Controls Tests ====================

    public function testOrphanedControlsDetection(): void
    {
        $orphan = $this->createControl('A.5.1', 'Orphan', 'implemented', 100);

        $this->controlRepository->method('findAll')->willReturn([$orphan]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $result = $this->service->getEffectivenessDashboard();
        $orphans = $result['orphaned_controls'];

        $this->assertEquals(1, $orphans['count']);
        $this->assertEquals('A.5.1', $orphans['controls'][0]['control_id']);
    }

    // ==================== Aging Analysis Tests ====================

    public function testAgingAnalysisWithCurrentReview(): void
    {
        $control = $this->createControlWithReview('A.5.1', 'Test', 'implemented', 100, new \DateTime('-30 days'));

        $this->controlRepository->method('findAll')->willReturn([$control]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $result = $this->service->getEffectivenessDashboard();
        $aging = $result['aging_analysis'];

        $this->assertEquals(1, $aging['distribution']['current']);
        $this->assertEquals(0, $aging['distribution']['overdue']);
    }

    public function testAgingAnalysisWithOverdueReview(): void
    {
        $control = $this->createControlWithReview('A.5.1', 'Test', 'implemented', 100, new \DateTime('-200 days'));

        $this->controlRepository->method('findAll')->willReturn([$control]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $result = $this->service->getEffectivenessDashboard();
        $aging = $result['aging_analysis'];

        $this->assertEquals(0, $aging['distribution']['current']);
        $this->assertEquals(1, $aging['distribution']['overdue']);
    }

    public function testAgingAnalysisWithNeverReviewed(): void
    {
        $control = $this->createControl('A.5.1', 'Test', 'implemented', 100);
        // No review date set

        $this->controlRepository->method('findAll')->willReturn([$control]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $result = $this->service->getEffectivenessDashboard();
        $aging = $result['aging_analysis'];

        $this->assertEquals(1, $aging['distribution']['never_reviewed']);
    }

    // ==================== Implementation Status Distribution Tests ====================

    public function testImplementationStatusDistribution(): void
    {
        $impl = $this->createControl('A.5.1', 'Impl', 'implemented', 100);
        $partial = $this->createControl('A.5.2', 'Partial', 'partially_implemented', 50);
        $na = $this->createControl('A.5.3', 'NA', 'not_applicable', 0);

        $this->controlRepository->method('findAll')->willReturn([$impl, $partial, $na]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $result = $this->service->getEffectivenessDashboard();
        $distribution = $result['implementation_status'];

        $this->assertEquals(1, $distribution['implemented']);
        $this->assertEquals(1, $distribution['partially_implemented']);
        $this->assertEquals(1, $distribution['not_applicable']);
    }

    // ==================== getControlRiskMatrix() Tests ====================

    public function testGetControlRiskMatrixWithRisks(): void
    {
        $risk = $this->createRiskWithLevels(15, 8); // 15 inherent, 8 residual
        $control = $this->createControlWithRisks('A.5.1', 'Test', 'implemented', 100, [$risk]);

        $this->controlRepository->method('findBy')
            ->with(['implementationStatus' => 'implemented'])
            ->willReturn([$control]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $result = $this->service->getControlRiskMatrix();

        $this->assertArrayHasKey('matrix', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertEquals(1, $result['summary']['controls_with_risks']);
        $this->assertEquals(7, $result['summary']['total_risk_reduction']); // 15 - 8
    }

    public function testGetControlRiskMatrixWithNoRisks(): void
    {
        $control = $this->createControl('A.5.1', 'Test', 'implemented', 100);

        $this->controlRepository->method('findBy')->willReturn([$control]);

        $result = $this->service->getControlRiskMatrix();

        $this->assertEquals(0, $result['summary']['controls_with_risks']);
    }

    // ==================== getCategoryPerformance() Tests ====================

    public function testGetCategoryPerformance(): void
    {
        $ctrl1 = $this->createControlWithCategory('A.5.1', 'Test 1', 'implemented', 100, 'A.5');
        $ctrl2 = $this->createControlWithCategory('A.5.2', 'Test 2', 'implemented', 100, 'A.5');
        $ctrl3 = $this->createControlWithCategory('A.6.1', 'Test 3', 'planned', 0, 'A.6');

        $this->controlRepository->method('findAll')->willReturn([$ctrl1, $ctrl2, $ctrl3]);
        $this->requirementRepository->method('findByControl')->willReturn([]);

        $result = $this->service->getCategoryPerformance();

        $this->assertCount(2, $result); // A.5 and A.6

        // Find A.5 category
        $a5 = array_filter($result, fn($r) => $r['category'] === 'A.5');
        $a5 = reset($a5);

        $this->assertEquals(2, $a5['total_controls']);
        $this->assertEquals(2, $a5['implemented']);
        $this->assertEquals(100, $a5['implementation_rate']);
    }

    // ==================== Helper Methods ====================

    private function createControl(string $controlId, string $name, string $status, int $percentage): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('getId')->willReturn(1);
        $control->method('getControlId')->willReturn($controlId);
        $control->method('getName')->willReturn($name);
        $control->method('getImplementationStatus')->willReturn($status);
        $control->method('getImplementationPercentage')->willReturn($percentage);
        $control->method('getCategory')->willReturn('A.5');
        $control->method('getRisks')->willReturn(new ArrayCollection());
        $control->method('getLastReviewDate')->willReturn(null);
        return $control;
    }

    private function createControlWithRisks(string $controlId, string $name, string $status, int $percentage, array $risks): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('getId')->willReturn(1);
        $control->method('getControlId')->willReturn($controlId);
        $control->method('getName')->willReturn($name);
        $control->method('getImplementationStatus')->willReturn($status);
        $control->method('getImplementationPercentage')->willReturn($percentage);
        $control->method('getCategory')->willReturn('A.5');
        $control->method('getRisks')->willReturn(new ArrayCollection($risks));
        $control->method('getLastReviewDate')->willReturn(null);
        return $control;
    }

    private function createControlWithReview(string $controlId, string $name, string $status, int $percentage, \DateTimeInterface $reviewDate): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('getId')->willReturn(1);
        $control->method('getControlId')->willReturn($controlId);
        $control->method('getName')->willReturn($name);
        $control->method('getImplementationStatus')->willReturn($status);
        $control->method('getImplementationPercentage')->willReturn($percentage);
        $control->method('getCategory')->willReturn('A.5');
        $control->method('getRisks')->willReturn(new ArrayCollection());
        $control->method('getLastReviewDate')->willReturn($reviewDate);
        return $control;
    }

    private function createControlWithRisksAndReview(string $controlId, string $name, string $status, int $percentage, array $risks, \DateTimeInterface $reviewDate): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('getId')->willReturn(1);
        $control->method('getControlId')->willReturn($controlId);
        $control->method('getName')->willReturn($name);
        $control->method('getImplementationStatus')->willReturn($status);
        $control->method('getImplementationPercentage')->willReturn($percentage);
        $control->method('getCategory')->willReturn('A.5');
        $control->method('getRisks')->willReturn(new ArrayCollection($risks));
        $control->method('getLastReviewDate')->willReturn($reviewDate);
        return $control;
    }

    private function createControlWithCategory(string $controlId, string $name, string $status, int $percentage, string $category): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('getId')->willReturn(1);
        $control->method('getControlId')->willReturn($controlId);
        $control->method('getName')->willReturn($name);
        $control->method('getImplementationStatus')->willReturn($status);
        $control->method('getImplementationPercentage')->willReturn($percentage);
        $control->method('getCategory')->willReturn($category);
        $control->method('getRisks')->willReturn(new ArrayCollection());
        $control->method('getLastReviewDate')->willReturn(null);
        return $control;
    }

    private function createRiskWithLevels(int $inherent, int $residual): Risk
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $risk->method('getTitle')->willReturn('Test Risk');
        $risk->method('getInherentRiskLevel')->willReturn($inherent);
        $risk->method('getResidualRiskLevel')->willReturn($residual);
        return $risk;
    }
}
