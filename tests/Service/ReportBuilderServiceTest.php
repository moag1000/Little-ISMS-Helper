<?php

namespace App\Tests\Service;

use App\Entity\CustomReport;
use App\Entity\User;
use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\CustomReportRepository;
use App\Repository\DataBreachRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\RiskRepository;
use App\Repository\TrainingRepository;
use App\Service\DashboardStatisticsService;
use App\Service\ReportBuilderService;
use App\Service\TenantContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ReportBuilderService Unit Tests
 *
 * Phase 7C: Tests for the custom report builder service
 */
class ReportBuilderServiceTest extends TestCase
{
    private ReportBuilderService $service;
    private MockObject $customReportRepository;
    private MockObject $riskRepository;
    private MockObject $controlRepository;
    private MockObject $assetRepository;
    private MockObject $incidentRepository;
    private MockObject $auditRepository;
    private MockObject $businessProcessRepository;
    private MockObject $bcPlanRepository;
    private MockObject $frameworkRepository;
    private MockObject $trainingRepository;
    private MockObject $dataBreachRepository;
    private MockObject $dashboardStatisticsService;
    private MockObject $translator;
    private MockObject $tenantContext;

    protected function setUp(): void
    {
        $this->customReportRepository = $this->createMock(CustomReportRepository::class);
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->controlRepository = $this->createMock(ControlRepository::class);
        $this->assetRepository = $this->createMock(AssetRepository::class);
        $this->incidentRepository = $this->createMock(IncidentRepository::class);
        $this->auditRepository = $this->createMock(InternalAuditRepository::class);
        $this->businessProcessRepository = $this->createMock(BusinessProcessRepository::class);
        $this->bcPlanRepository = $this->createMock(BusinessContinuityPlanRepository::class);
        $this->frameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->trainingRepository = $this->createMock(TrainingRepository::class);
        $this->dataBreachRepository = $this->createMock(DataBreachRepository::class);
        $this->dashboardStatisticsService = $this->createMock(DashboardStatisticsService::class);
        $this->translator = $this->createMock(TranslatorInterface::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        // Setup translator mock to return the key as translation
        $this->translator->method('trans')
            ->willReturnCallback(fn(string $key) => $key);

        $this->service = new ReportBuilderService(
            $this->customReportRepository,
            $this->riskRepository,
            $this->controlRepository,
            $this->assetRepository,
            $this->incidentRepository,
            $this->auditRepository,
            $this->businessProcessRepository,
            $this->bcPlanRepository,
            $this->frameworkRepository,
            $this->trainingRepository,
            $this->dataBreachRepository,
            $this->dashboardStatisticsService,
            $this->translator,
            $this->tenantContext
        );
    }

    public function testGetWidgetLibraryReturnsAllCategories(): void
    {
        $library = $this->service->getWidgetLibrary();

        $this->assertArrayHasKey(ReportBuilderService::WIDGET_CATEGORY_KPI, $library);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_CATEGORY_CHART, $library);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_CATEGORY_TABLE, $library);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_CATEGORY_STATUS, $library);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_CATEGORY_TEXT, $library);
    }

    public function testGetWidgetLibraryHasKpiWidgets(): void
    {
        $library = $this->service->getWidgetLibrary();
        $kpiWidgets = $library[ReportBuilderService::WIDGET_CATEGORY_KPI]['widgets'];

        $this->assertArrayHasKey(ReportBuilderService::WIDGET_KPI_RISK_COUNT, $kpiWidgets);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_KPI_HIGH_RISKS, $kpiWidgets);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_KPI_CONTROL_COUNT, $kpiWidgets);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_KPI_COMPLIANCE_SCORE, $kpiWidgets);
    }

    public function testGetWidgetLibraryHasChartWidgets(): void
    {
        $library = $this->service->getWidgetLibrary();
        $chartWidgets = $library[ReportBuilderService::WIDGET_CATEGORY_CHART]['widgets'];

        $this->assertArrayHasKey(ReportBuilderService::WIDGET_CHART_RISK_MATRIX, $chartWidgets);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_CHART_COMPLIANCE_RADAR, $chartWidgets);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_CHART_CONTROL_STATUS, $chartWidgets);
    }

    public function testGetWidgetLibraryHasTableWidgets(): void
    {
        $library = $this->service->getWidgetLibrary();
        $tableWidgets = $library[ReportBuilderService::WIDGET_CATEGORY_TABLE]['widgets'];

        $this->assertArrayHasKey(ReportBuilderService::WIDGET_TABLE_TOP_RISKS, $tableWidgets);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_TABLE_RECENT_INCIDENTS, $tableWidgets);
        $this->assertArrayHasKey(ReportBuilderService::WIDGET_TABLE_CRITICAL_ASSETS, $tableWidgets);
    }

    public function testGetWidgetDataReturnsRiskCount(): void
    {
        $this->riskRepository->method('count')->willReturn(15);

        $data = $this->service->getWidgetData(ReportBuilderService::WIDGET_KPI_RISK_COUNT);

        $this->assertArrayHasKey('value', $data);
        $this->assertArrayHasKey('label', $data);
        $this->assertEquals(15, $data['value']);
    }

    public function testGetWidgetDataReturnsControlCount(): void
    {
        $this->controlRepository->method('count')->willReturn(93);

        $data = $this->service->getWidgetData(ReportBuilderService::WIDGET_KPI_CONTROL_COUNT);

        $this->assertArrayHasKey('value', $data);
        $this->assertEquals(93, $data['value']);
    }

    public function testGetWidgetDataReturnsAssetCount(): void
    {
        $this->assetRepository->method('count')->willReturn(50);

        $data = $this->service->getWidgetData(ReportBuilderService::WIDGET_KPI_ASSET_COUNT);

        $this->assertArrayHasKey('value', $data);
        $this->assertEquals(50, $data['value']);
    }

    public function testGetWidgetDataReturnsIncidentCount(): void
    {
        $this->incidentRepository->method('count')->willReturn(12);

        $data = $this->service->getWidgetData(ReportBuilderService::WIDGET_KPI_INCIDENT_COUNT);

        $this->assertArrayHasKey('value', $data);
        $this->assertEquals(12, $data['value']);
    }

    public function testGetWidgetDataReturnsRiskMatrixStructure(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        $data = $this->service->getWidgetData(ReportBuilderService::WIDGET_CHART_RISK_MATRIX);

        $this->assertArrayHasKey('type', $data);
        $this->assertArrayHasKey('matrix', $data);
        $this->assertArrayHasKey('labels', $data);
        $this->assertEquals('heatmap', $data['type']);
    }

    public function testGetWidgetDataReturnsTopRisksTable(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        $data = $this->service->getWidgetData(ReportBuilderService::WIDGET_TABLE_TOP_RISKS, ['limit' => 5]);

        $this->assertArrayHasKey('columns', $data);
        $this->assertArrayHasKey('rows', $data);
        $this->assertIsArray($data['columns']);
        $this->assertIsArray($data['rows']);
    }

    public function testGetWidgetDataReturnsRAGStatus(): void
    {
        $this->controlRepository->method('findAll')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $data = $this->service->getWidgetData(ReportBuilderService::WIDGET_STATUS_RAG);

        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('label', $data);
        $this->assertContains($data['status'], ['green', 'amber', 'red']);
    }

    public function testGetWidgetDataReturnsUnknownWidgetError(): void
    {
        $data = $this->service->getWidgetData('invalid_widget_type');

        $this->assertArrayHasKey('error', $data);
    }

    public function testGetPredefinedTemplatesReturnsAllTemplates(): void
    {
        $templates = $this->service->getPredefinedTemplates();

        $this->assertArrayHasKey('executive_summary', $templates);
        $this->assertArrayHasKey('risk_report', $templates);
        $this->assertArrayHasKey('compliance_dashboard', $templates);
        $this->assertArrayHasKey('incident_report', $templates);
        $this->assertArrayHasKey('bcm_status', $templates);
        $this->assertArrayHasKey('asset_overview', $templates);
    }

    public function testGetPredefinedTemplatesHaveRequiredFields(): void
    {
        $templates = $this->service->getPredefinedTemplates();

        foreach ($templates as $key => $template) {
            $this->assertArrayHasKey('name', $template, "Template $key missing 'name'");
            $this->assertArrayHasKey('description', $template, "Template $key missing 'description'");
            $this->assertArrayHasKey('category', $template, "Template $key missing 'category'");
            $this->assertArrayHasKey('layout', $template, "Template $key missing 'layout'");
            $this->assertArrayHasKey('widgets', $template, "Template $key missing 'widgets'");
        }
    }

    public function testCreateFromTemplateReturnsReport(): void
    {
        $user = $this->createMock(User::class);

        $report = $this->service->createFromTemplate('executive_summary', $user, 1);

        $this->assertInstanceOf(CustomReport::class, $report);
        $this->assertEquals('template.executive_summary', $report->getName());
        $this->assertEquals(CustomReport::CATEGORY_EXECUTIVE, $report->getCategory());
        $this->assertEquals(CustomReport::LAYOUT_DASHBOARD, $report->getLayout());
        $this->assertNotEmpty($report->getWidgets());
    }

    public function testCreateFromTemplateReturnsNullForInvalidTemplate(): void
    {
        $user = $this->createMock(User::class);

        $report = $this->service->createFromTemplate('invalid_template', $user, 1);

        $this->assertNull($report);
    }

    public function testGenerateReportDataReturnsExpectedStructure(): void
    {
        $report = new CustomReport();
        $report->setName('Test Report');
        $report->setDescription('Test Description');
        $report->setCategory(CustomReport::CATEGORY_RISK);
        $report->setLayout(CustomReport::LAYOUT_DASHBOARD);
        $report->setWidgets([
            ['id' => 'widget1', 'type' => ReportBuilderService::WIDGET_KPI_RISK_COUNT, 'config' => []],
        ]);
        $report->setFilters([]);
        $report->setStyles(['primaryColor' => '#0d6efd']);

        $this->riskRepository->method('count')->willReturn(10);

        $data = $this->service->generateReportData($report);

        $this->assertArrayHasKey('report', $data);
        $this->assertArrayHasKey('widgets', $data);
        $this->assertArrayHasKey('filters', $data);

        $this->assertEquals('Test Report', $data['report']['name']);
        $this->assertEquals('Test Description', $data['report']['description']);
        $this->assertArrayHasKey('widget1', $data['widgets']);
    }

    public function testWidgetLibraryWidgetsHaveRequiredMetadata(): void
    {
        $library = $this->service->getWidgetLibrary();

        foreach ($library as $categoryKey => $category) {
            $this->assertArrayHasKey('label', $category, "Category $categoryKey missing 'label'");
            $this->assertArrayHasKey('icon', $category, "Category $categoryKey missing 'icon'");
            $this->assertArrayHasKey('widgets', $category, "Category $categoryKey missing 'widgets'");

            foreach ($category['widgets'] as $widgetKey => $widget) {
                $this->assertArrayHasKey('label', $widget, "Widget $widgetKey missing 'label'");
                $this->assertArrayHasKey('icon', $widget, "Widget $widgetKey missing 'icon'");
                $this->assertArrayHasKey('size', $widget, "Widget $widgetKey missing 'size'");
            }
        }
    }

    public function testWidgetCountMeetsMinimumRequirement(): void
    {
        $library = $this->service->getWidgetLibrary();

        $totalWidgets = 0;
        foreach ($library as $category) {
            $totalWidgets += count($category['widgets']);
        }

        // Phase 7C requires 20+ widgets
        $this->assertGreaterThanOrEqual(20, $totalWidgets, 'Should have at least 20 widgets');
    }

    public function testTemplateCountMeetsMinimumRequirement(): void
    {
        $templates = $this->service->getPredefinedTemplates();

        // Should have at least 5 predefined templates
        $this->assertGreaterThanOrEqual(5, count($templates), 'Should have at least 5 predefined templates');
    }
}
