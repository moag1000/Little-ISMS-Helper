<?php

namespace App\Tests\Service;

use App\Entity\Control;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\ComplianceAnalyticsService;
use App\Service\ControlEffectivenessService;
use App\Service\DashboardStatisticsService;
use App\Service\RiskForecastService;
use App\Service\RoleDashboardService;
use App\Service\TenantContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Tests for RoleDashboardService
 *
 * Phase 7D: Tests for role-specific dashboard data
 */
class RoleDashboardServiceTest extends TestCase
{
    private MockObject $dashboardStatisticsService;
    private MockObject $complianceAnalyticsService;
    private MockObject $controlEffectivenessService;
    private MockObject $riskForecastService;
    private MockObject $riskRepository;
    private MockObject $incidentRepository;
    private MockObject $controlRepository;
    private MockObject $workflowInstanceRepository;
    private MockObject $security;
    private MockObject $tenantContext;

    protected function setUp(): void
    {
        $this->dashboardStatisticsService = $this->createMock(DashboardStatisticsService::class);
        $this->complianceAnalyticsService = $this->createMock(ComplianceAnalyticsService::class);
        $this->controlEffectivenessService = $this->createMock(ControlEffectivenessService::class);
        $this->riskForecastService = $this->createMock(RiskForecastService::class);
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->controlRepository = $this->createMock(ControlRepository::class);
        $this->workflowInstanceRepository = $this->createMock(WorkflowInstanceRepository::class);
        $this->security = $this->createMock(Security::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
    }

    private function createService(): RoleDashboardService
    {
        return new RoleDashboardService(
            $this->dashboardStatisticsService,
            $this->complianceAnalyticsService,
            $this->controlEffectivenessService,
            $this->riskForecastService,
            $this->riskRepository,
            $this->incidentRepository,
            $this->controlRepository,
            $this->workflowInstanceRepository,
            $this->security,
            $this->tenantContext,
        );
    }

    // ==================== getCisoDashboard() Tests ====================

    public function testGetCisoDashboardReturnsExpectedStructure(): void
    {
        $this->setupAllMocks();
        $service = $this->createService();

        $result = $service->getCisoDashboard();

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('compliance', $result);
        $this->assertArrayHasKey('controls', $result);
        $this->assertArrayHasKey('risk_posture', $result);
        $this->assertArrayHasKey('critical_risks', $result);
        $this->assertArrayHasKey('pending_approvals', $result);
    }

    public function testGetCisoDashboardSummaryContainsExpectedKeys(): void
    {
        $this->setupAllMocks();
        $service = $this->createService();

        $result = $service->getCisoDashboard();

        $this->assertArrayHasKey('overall_compliance', $result['summary']);
        $this->assertArrayHasKey('control_implementation', $result['summary']);
        $this->assertArrayHasKey('high_risks', $result['summary']);
        $this->assertArrayHasKey('risk_trend', $result['summary']);
    }

    // ==================== getRiskManagerDashboard() Tests ====================

    public function testGetRiskManagerDashboardReturnsExpectedStructure(): void
    {
        $this->setupAllMocks();
        $service = $this->createService();

        $result = $service->getRiskManagerDashboard();

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('treatment_pipeline', $result);
        $this->assertArrayHasKey('risks_by_category', $result);
        $this->assertArrayHasKey('risk_appetite', $result);
        $this->assertArrayHasKey('risk_velocity', $result);
        $this->assertArrayHasKey('untreated_risks', $result);
    }

    public function testGetRiskManagerDashboardTreatmentPipeline(): void
    {
        $risk1 = $this->createRiskMock(1, 'Risk 1', 'mitigate', 12);
        $risk2 = $this->createRiskMock(2, 'Risk 2', null, 16);
        $risk3 = $this->createRiskMock(3, 'Risk 3', 'accept', 8);

        $this->riskRepository->method('findAll')->willReturn([$risk1, $risk2, $risk3]);
        $this->setupAllMocksExceptRisk();
        $service = $this->createService();

        $result = $service->getRiskManagerDashboard();

        $this->assertEquals(3, $result['treatment_pipeline']['total']);
        $this->assertEquals(2, $result['treatment_pipeline']['treated']);
        $this->assertEquals(1, $result['treatment_pipeline']['untreated']);
        $this->assertEquals(67, $result['treatment_pipeline']['treated_percentage']);
    }

    // ==================== getAuditorDashboard() Tests ====================

    public function testGetAuditorDashboardReturnsExpectedStructure(): void
    {
        $this->setupAllMocks();
        $service = $this->createService();

        $result = $service->getAuditorDashboard();

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('audit_status', $result);
        $this->assertArrayHasKey('evidence_status', $result);
        $this->assertArrayHasKey('compliance_gaps', $result);
        $this->assertArrayHasKey('control_status', $result);
    }

    public function testGetAuditorDashboardEvidenceStatus(): void
    {
        $control1 = $this->createControlMock(1, 'Control 1', new \DateTime());
        $control2 = $this->createControlMock(2, 'Control 2', null);
        $control3 = $this->createControlMock(3, 'Control 3', new \DateTime('-30 days'));

        $this->controlRepository->method('findApplicableControls')
            ->willReturn([$control1, $control2, $control3]);

        $this->setupAllMocksExceptControl();
        $service = $this->createService();

        $result = $service->getAuditorDashboard();

        $this->assertEquals(3, $result['evidence_status']['total_controls']);
        $this->assertEquals(2, $result['evidence_status']['with_evidence']);
        $this->assertEquals(67, $result['evidence_status']['coverage_percentage']);
    }

    // ==================== getBoardDashboard() Tests ====================

    public function testGetBoardDashboardReturnsExpectedStructure(): void
    {
        $this->setupAllMocks();
        $service = $this->createService();

        $result = $service->getBoardDashboard();

        // New structure
        $this->assertArrayHasKey('rag_status', $result);
        $this->assertArrayHasKey('rag_details', $result);
        $this->assertArrayHasKey('metrics', $result);
        $this->assertArrayHasKey('trends', $result);
        $this->assertArrayHasKey('executive_summary', $result);
        $this->assertArrayHasKey('milestones', $result);
        $this->assertArrayHasKey('attention_items', $result);
        $this->assertArrayHasKey('resources', $result);
        $this->assertArrayHasKey('quarterly', $result);
        // Legacy structure
        $this->assertArrayHasKey('headline', $result);
        $this->assertArrayHasKey('critical_items', $result);
    }

    public function testGetBoardDashboardRAGStatusStructure(): void
    {
        $this->setupAllMocks();
        $service = $this->createService();

        $result = $service->getBoardDashboard();

        $this->assertArrayHasKey('security', $result['rag_status']);
        $this->assertArrayHasKey('compliance', $result['rag_status']);
        $this->assertArrayHasKey('risk', $result['rag_status']);
        $this->assertArrayHasKey('operations', $result['rag_status']);
    }

    public function testGetBoardDashboardMetricsStructure(): void
    {
        $this->setupAllMocks();
        $service = $this->createService();

        $result = $service->getBoardDashboard();

        $this->assertArrayHasKey('overall_compliance', $result['metrics']);
        $this->assertArrayHasKey('total_risks', $result['metrics']);
        $this->assertArrayHasKey('critical_risks', $result['metrics']);
        $this->assertArrayHasKey('incidents_ytd', $result['metrics']);
        $this->assertArrayHasKey('controls_implemented', $result['metrics']);
    }

    // ==================== getRecommendedDashboard() Tests ====================

    public function testGetRecommendedDashboardForAdmin(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')
            ->willReturnCallback(function ($role) {
                return $role === 'ROLE_ADMIN';
            });

        $service = $this->createService();
        $result = $service->getRecommendedDashboard();

        $this->assertEquals('ciso', $result);
    }

    public function testGetRecommendedDashboardForManager(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')
            ->willReturnCallback(function ($role) {
                return $role === 'ROLE_MANAGER';
            });

        $service = $this->createService();
        $result = $service->getRecommendedDashboard();

        $this->assertEquals('risk_manager', $result);
    }

    public function testGetRecommendedDashboardForAuditor(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')
            ->willReturnCallback(function ($role) {
                return $role === 'ROLE_AUDITOR';
            });

        $service = $this->createService();
        $result = $service->getRecommendedDashboard();

        $this->assertEquals('auditor', $result);
    }

    public function testGetRecommendedDashboardForRegularUser(): void
    {
        $user = $this->createMock(User::class);
        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')->willReturn(false);

        $service = $this->createService();
        $result = $service->getRecommendedDashboard();

        $this->assertEquals('default', $result);
    }

    public function testGetRecommendedDashboardForNoUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $service = $this->createService();
        $result = $service->getRecommendedDashboard();

        $this->assertEquals('default', $result);
    }

    // ==================== Helper Methods ====================

    private function setupAllMocks(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->complianceAnalyticsService->method('getFrameworkComparison')
            ->willReturn([
                'frameworks' => [],
                'summary' => ['average_compliance' => 0, 'at_risk' => 0, 'compliant' => 0],
            ]);

        $this->complianceAnalyticsService->method('getGapAnalysis')
            ->willReturn([
                'summary' => ['total_gaps' => 0],
                'by_priority' => ['critical' => [], 'high' => []],
            ]);

        $this->complianceAnalyticsService->method('getExecutiveSummary')
            ->willReturn([
                'overall_compliance' => 0,
                'gaps' => ['total' => 0],
            ]);

        $this->controlEffectivenessService->method('getEffectivenessDashboard')
            ->willReturn([
                'metrics' => [
                    'total_controls' => 0,
                    'implemented' => 0,
                    'average_effectiveness' => 0,
                ],
                'aging_analysis' => ['distribution' => ['overdue' => 0]],
                'implementation_status' => [
                    'implemented' => 0,
                    'partially_implemented' => 0,
                    'not_implemented' => 0,
                ],
            ]);

        $this->controlEffectivenessService->method('getControlRiskMatrix')
            ->willReturn([
                'summary' => ['total_risk_reduction' => 0, 'controls_with_risks' => 0],
            ]);

        $this->riskForecastService->method('getRiskVelocity')
            ->willReturn([
                'trend' => 'stable',
                'last_30_days' => ['net_change' => 0],
            ]);

        $this->riskForecastService->method('getRiskAppetiteCompliance')
            ->willReturn([
                'is_compliant' => true,
                'compliance_score' => 100,
                'breaches' => [],
            ]);

        $this->riskForecastService->method('getRiskForecast')
            ->willReturn([
                'trend' => 'stable',
                'forecast' => [],
            ]);

        $this->dashboardStatisticsService->method('getDashboardStatistics')
            ->willReturn([
                'compliancePercentage' => 0,
                'risks_high' => 0,
                'incidents_open' => 0,
                'controls_implemented' => 0,
            ]);

        $this->dashboardStatisticsService->method('getManagementKPIs')
            ->willReturn([]);

        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findApplicableControls')->willReturn([]);
        $this->workflowInstanceRepository->method('findPendingForUser')->willReturn([]);
        $this->security->method('getUser')->willReturn(null);
    }

    private function setupAllMocksExceptRisk(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->complianceAnalyticsService->method('getFrameworkComparison')
            ->willReturn([
                'frameworks' => [],
                'summary' => ['average_compliance' => 0, 'at_risk' => 0, 'compliant' => 0],
            ]);

        $this->complianceAnalyticsService->method('getGapAnalysis')
            ->willReturn([
                'summary' => ['total_gaps' => 0],
                'by_priority' => ['critical' => [], 'high' => []],
            ]);

        $this->complianceAnalyticsService->method('getExecutiveSummary')
            ->willReturn([
                'overall_compliance' => 0,
                'gaps' => ['total' => 0],
            ]);

        $this->controlEffectivenessService->method('getEffectivenessDashboard')
            ->willReturn([
                'metrics' => [
                    'total_controls' => 0,
                    'implemented' => 0,
                    'average_effectiveness' => 0,
                ],
                'aging_analysis' => ['distribution' => ['overdue' => 0]],
                'implementation_status' => [
                    'implemented' => 0,
                    'partially_implemented' => 0,
                    'not_implemented' => 0,
                ],
            ]);

        $this->controlEffectivenessService->method('getControlRiskMatrix')
            ->willReturn([
                'summary' => ['total_risk_reduction' => 0, 'controls_with_risks' => 0],
            ]);

        $this->riskForecastService->method('getRiskVelocity')
            ->willReturn([
                'trend' => 'stable',
                'last_30_days' => ['net_change' => 0],
            ]);

        $this->riskForecastService->method('getRiskAppetiteCompliance')
            ->willReturn([
                'is_compliant' => true,
                'compliance_score' => 100,
                'breaches' => [],
            ]);

        $this->riskForecastService->method('getRiskForecast')
            ->willReturn([
                'trend' => 'stable',
                'forecast' => [],
            ]);

        $this->dashboardStatisticsService->method('getDashboardStatistics')
            ->willReturn([
                'compliancePercentage' => 0,
                'risks_high' => 0,
                'incidents_open' => 0,
                'controls_implemented' => 0,
            ]);

        $this->dashboardStatisticsService->method('getManagementKPIs')
            ->willReturn([]);

        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->controlRepository->method('findApplicableControls')->willReturn([]);
        $this->workflowInstanceRepository->method('findPendingForUser')->willReturn([]);
        $this->security->method('getUser')->willReturn(null);
    }

    private function setupAllMocksExceptControl(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->complianceAnalyticsService->method('getFrameworkComparison')
            ->willReturn([
                'frameworks' => [],
                'summary' => ['average_compliance' => 0, 'at_risk' => 0, 'compliant' => 0],
            ]);

        $this->complianceAnalyticsService->method('getGapAnalysis')
            ->willReturn([
                'summary' => ['total_gaps' => 0],
                'by_priority' => ['critical' => [], 'high' => []],
            ]);

        $this->complianceAnalyticsService->method('getExecutiveSummary')
            ->willReturn([
                'overall_compliance' => 0,
                'gaps' => ['total' => 0],
            ]);

        $this->controlEffectivenessService->method('getEffectivenessDashboard')
            ->willReturn([
                'metrics' => [
                    'total_controls' => 0,
                    'implemented' => 0,
                    'average_effectiveness' => 0,
                ],
                'aging_analysis' => ['distribution' => ['overdue' => 0]],
                'implementation_status' => [
                    'implemented' => 0,
                    'partially_implemented' => 0,
                    'not_implemented' => 0,
                ],
            ]);

        $this->controlEffectivenessService->method('getControlRiskMatrix')
            ->willReturn([
                'summary' => ['total_risk_reduction' => 0, 'controls_with_risks' => 0],
            ]);

        $this->riskForecastService->method('getRiskVelocity')
            ->willReturn([
                'trend' => 'stable',
                'last_30_days' => ['net_change' => 0],
            ]);

        $this->riskForecastService->method('getRiskAppetiteCompliance')
            ->willReturn([
                'is_compliant' => true,
                'compliance_score' => 100,
                'breaches' => [],
            ]);

        $this->riskForecastService->method('getRiskForecast')
            ->willReturn([
                'trend' => 'stable',
                'forecast' => [],
            ]);

        $this->dashboardStatisticsService->method('getDashboardStatistics')
            ->willReturn([
                'compliancePercentage' => 0,
                'risks_high' => 0,
                'incidents_open' => 0,
                'controls_implemented' => 0,
            ]);

        $this->dashboardStatisticsService->method('getManagementKPIs')
            ->willReturn([]);

        $this->riskRepository->method('findAll')->willReturn([]);
        $this->incidentRepository->method('findAll')->willReturn([]);
        $this->workflowInstanceRepository->method('findPendingForUser')->willReturn([]);
        $this->security->method('getUser')->willReturn(null);
    }

    private function createRiskMock(int $id, string $title, ?string $treatmentStrategy, int $level): Risk
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn($id);
        $risk->method('getTitle')->willReturn($title);
        $risk->method('getTreatmentStrategy')->willReturn($treatmentStrategy);
        $risk->method('getInherentRiskLevel')->willReturn($level);
        $risk->method('getCategory')->willReturn('General');
        $risk->method('getStatus')->willReturn('active');

        return $risk;
    }

    private function createControlMock(int $id, string $name, ?\DateTime $lastReviewDate): Control
    {
        $control = $this->createMock(Control::class);
        $control->method('getId')->willReturn($id);
        $control->method('getName')->willReturn($name);
        $control->method('getLastReviewDate')->willReturn($lastReviewDate);

        return $control;
    }
}
