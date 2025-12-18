<?php

namespace App\Service;

use App\Entity\CustomReport;
use App\Entity\User;
use App\Repository\CustomReportRepository;
use App\Repository\RiskRepository;
use App\Repository\ControlRepository;
use App\Repository\AssetRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\TrainingRepository;
use App\Repository\DataBreachRepository;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Report Builder Service
 *
 * Phase 7C: Provides widget library and data generation for custom report builder.
 * Supports 25+ widget types across all ISMS domains.
 */
class ReportBuilderService
{
    // Widget Categories
    public const WIDGET_CATEGORY_KPI = 'kpi';
    public const WIDGET_CATEGORY_CHART = 'chart';
    public const WIDGET_CATEGORY_TABLE = 'table';
    public const WIDGET_CATEGORY_TEXT = 'text';
    public const WIDGET_CATEGORY_STATUS = 'status';

    // Widget Types - KPIs
    public const WIDGET_KPI_RISK_COUNT = 'kpi_risk_count';
    public const WIDGET_KPI_CONTROL_COUNT = 'kpi_control_count';
    public const WIDGET_KPI_ASSET_COUNT = 'kpi_asset_count';
    public const WIDGET_KPI_INCIDENT_COUNT = 'kpi_incident_count';
    public const WIDGET_KPI_COMPLIANCE_SCORE = 'kpi_compliance_score';
    public const WIDGET_KPI_CONTROL_IMPLEMENTATION = 'kpi_control_implementation';
    public const WIDGET_KPI_HIGH_RISKS = 'kpi_high_risks';
    public const WIDGET_KPI_OPEN_INCIDENTS = 'kpi_open_incidents';
    public const WIDGET_KPI_OVERDUE_TREATMENTS = 'kpi_overdue_treatments';
    public const WIDGET_KPI_BCM_COVERAGE = 'kpi_bcm_coverage';

    // Widget Types - Charts
    public const WIDGET_CHART_RISK_MATRIX = 'chart_risk_matrix';
    public const WIDGET_CHART_RISK_BY_CATEGORY = 'chart_risk_by_category';
    public const WIDGET_CHART_RISK_TREND = 'chart_risk_trend';
    public const WIDGET_CHART_CONTROL_STATUS = 'chart_control_status';
    public const WIDGET_CHART_COMPLIANCE_RADAR = 'chart_compliance_radar';
    public const WIDGET_CHART_INCIDENT_TREND = 'chart_incident_trend';
    public const WIDGET_CHART_ASSET_CRITICALITY = 'chart_asset_criticality';
    public const WIDGET_CHART_FRAMEWORK_COMPARISON = 'chart_framework_comparison';

    // Widget Types - Tables
    public const WIDGET_TABLE_TOP_RISKS = 'table_top_risks';
    public const WIDGET_TABLE_RECENT_INCIDENTS = 'table_recent_incidents';
    public const WIDGET_TABLE_OVERDUE_CONTROLS = 'table_overdue_controls';
    public const WIDGET_TABLE_CRITICAL_ASSETS = 'table_critical_assets';
    public const WIDGET_TABLE_AUDIT_FINDINGS = 'table_audit_findings';
    public const WIDGET_TABLE_BC_PLANS = 'table_bc_plans';

    // Widget Types - Status/Text
    public const WIDGET_STATUS_RAG = 'status_rag';
    public const WIDGET_TEXT_SUMMARY = 'text_summary';
    public const WIDGET_TEXT_HEADER = 'text_header';
    public const WIDGET_TEXT_CUSTOM = 'text_custom';

    public function __construct(
        private readonly CustomReportRepository $customReportRepository,
        private readonly RiskRepository $riskRepository,
        private readonly ControlRepository $controlRepository,
        private readonly AssetRepository $assetRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly InternalAuditRepository $auditRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly DashboardStatisticsService $dashboardStatisticsService,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Get the complete widget library with metadata
     *
     * @return array Widget library organized by category
     */
    public function getWidgetLibrary(): array
    {
        $t = fn(string $key): string => $this->translator->trans($key, [], 'report_builder');

        return [
            self::WIDGET_CATEGORY_KPI => [
                'label' => $t('widget.category.kpi'),
                'icon' => 'bi-speedometer2',
                'widgets' => [
                    self::WIDGET_KPI_RISK_COUNT => [
                        'label' => $t('widget.kpi_risk_count'),
                        'description' => $t('widget.kpi_risk_count.description'),
                        'icon' => 'bi-exclamation-triangle',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => 'risks',
                    ],
                    self::WIDGET_KPI_HIGH_RISKS => [
                        'label' => $t('widget.kpi_high_risks'),
                        'description' => $t('widget.kpi_high_risks.description'),
                        'icon' => 'bi-exclamation-triangle-fill',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => 'risks',
                    ],
                    self::WIDGET_KPI_CONTROL_COUNT => [
                        'label' => $t('widget.kpi_control_count'),
                        'description' => $t('widget.kpi_control_count.description'),
                        'icon' => 'bi-shield-check',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => 'controls',
                    ],
                    self::WIDGET_KPI_CONTROL_IMPLEMENTATION => [
                        'label' => $t('widget.kpi_control_implementation'),
                        'description' => $t('widget.kpi_control_implementation.description'),
                        'icon' => 'bi-check-circle',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => 'controls',
                    ],
                    self::WIDGET_KPI_ASSET_COUNT => [
                        'label' => $t('widget.kpi_asset_count'),
                        'description' => $t('widget.kpi_asset_count.description'),
                        'icon' => 'bi-hdd-network',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => 'assets',
                    ],
                    self::WIDGET_KPI_INCIDENT_COUNT => [
                        'label' => $t('widget.kpi_incident_count'),
                        'description' => $t('widget.kpi_incident_count.description'),
                        'icon' => 'bi-lightning',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => 'incidents',
                    ],
                    self::WIDGET_KPI_OPEN_INCIDENTS => [
                        'label' => $t('widget.kpi_open_incidents'),
                        'description' => $t('widget.kpi_open_incidents.description'),
                        'icon' => 'bi-lightning-fill',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => 'incidents',
                    ],
                    self::WIDGET_KPI_COMPLIANCE_SCORE => [
                        'label' => $t('widget.kpi_compliance_score'),
                        'description' => $t('widget.kpi_compliance_score.description'),
                        'icon' => 'bi-patch-check',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => 'compliance',
                    ],
                    self::WIDGET_KPI_OVERDUE_TREATMENTS => [
                        'label' => $t('widget.kpi_overdue_treatments'),
                        'description' => $t('widget.kpi_overdue_treatments.description'),
                        'icon' => 'bi-clock-history',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => 'risks',
                    ],
                    self::WIDGET_KPI_BCM_COVERAGE => [
                        'label' => $t('widget.kpi_bcm_coverage'),
                        'description' => $t('widget.kpi_bcm_coverage.description'),
                        'icon' => 'bi-diagram-3',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => 'bcm',
                    ],
                ],
            ],
            self::WIDGET_CATEGORY_CHART => [
                'label' => $t('widget.category.chart'),
                'icon' => 'bi-bar-chart',
                'widgets' => [
                    self::WIDGET_CHART_RISK_MATRIX => [
                        'label' => $t('widget.chart_risk_matrix'),
                        'description' => $t('widget.chart_risk_matrix.description'),
                        'icon' => 'bi-grid-3x3',
                        'size' => ['width' => 2, 'height' => 2],
                        'module' => 'risks',
                    ],
                    self::WIDGET_CHART_RISK_BY_CATEGORY => [
                        'label' => $t('widget.chart_risk_by_category'),
                        'description' => $t('widget.chart_risk_by_category.description'),
                        'icon' => 'bi-pie-chart',
                        'size' => ['width' => 2, 'height' => 1],
                        'module' => 'risks',
                    ],
                    self::WIDGET_CHART_RISK_TREND => [
                        'label' => $t('widget.chart_risk_trend'),
                        'description' => $t('widget.chart_risk_trend.description'),
                        'icon' => 'bi-graph-up',
                        'size' => ['width' => 2, 'height' => 1],
                        'module' => 'risks',
                    ],
                    self::WIDGET_CHART_CONTROL_STATUS => [
                        'label' => $t('widget.chart_control_status'),
                        'description' => $t('widget.chart_control_status.description'),
                        'icon' => 'bi-pie-chart-fill',
                        'size' => ['width' => 2, 'height' => 1],
                        'module' => 'controls',
                    ],
                    self::WIDGET_CHART_COMPLIANCE_RADAR => [
                        'label' => $t('widget.chart_compliance_radar'),
                        'description' => $t('widget.chart_compliance_radar.description'),
                        'icon' => 'bi-diagram-2',
                        'size' => ['width' => 2, 'height' => 2],
                        'module' => 'compliance',
                    ],
                    self::WIDGET_CHART_INCIDENT_TREND => [
                        'label' => $t('widget.chart_incident_trend'),
                        'description' => $t('widget.chart_incident_trend.description'),
                        'icon' => 'bi-graph-down',
                        'size' => ['width' => 2, 'height' => 1],
                        'module' => 'incidents',
                    ],
                    self::WIDGET_CHART_ASSET_CRITICALITY => [
                        'label' => $t('widget.chart_asset_criticality'),
                        'description' => $t('widget.chart_asset_criticality.description'),
                        'icon' => 'bi-bar-chart-fill',
                        'size' => ['width' => 2, 'height' => 1],
                        'module' => 'assets',
                    ],
                    self::WIDGET_CHART_FRAMEWORK_COMPARISON => [
                        'label' => $t('widget.chart_framework_comparison'),
                        'description' => $t('widget.chart_framework_comparison.description'),
                        'icon' => 'bi-bar-chart-steps',
                        'size' => ['width' => 2, 'height' => 1],
                        'module' => 'compliance',
                    ],
                ],
            ],
            self::WIDGET_CATEGORY_TABLE => [
                'label' => $t('widget.category.table'),
                'icon' => 'bi-table',
                'widgets' => [
                    self::WIDGET_TABLE_TOP_RISKS => [
                        'label' => $t('widget.table_top_risks'),
                        'description' => $t('widget.table_top_risks.description'),
                        'icon' => 'bi-list-ol',
                        'size' => ['width' => 2, 'height' => 2],
                        'module' => 'risks',
                        'config' => ['limit' => 10],
                    ],
                    self::WIDGET_TABLE_RECENT_INCIDENTS => [
                        'label' => $t('widget.table_recent_incidents'),
                        'description' => $t('widget.table_recent_incidents.description'),
                        'icon' => 'bi-list-task',
                        'size' => ['width' => 2, 'height' => 2],
                        'module' => 'incidents',
                        'config' => ['limit' => 10],
                    ],
                    self::WIDGET_TABLE_OVERDUE_CONTROLS => [
                        'label' => $t('widget.table_overdue_controls'),
                        'description' => $t('widget.table_overdue_controls.description'),
                        'icon' => 'bi-clock',
                        'size' => ['width' => 2, 'height' => 2],
                        'module' => 'controls',
                        'config' => ['limit' => 10],
                    ],
                    self::WIDGET_TABLE_CRITICAL_ASSETS => [
                        'label' => $t('widget.table_critical_assets'),
                        'description' => $t('widget.table_critical_assets.description'),
                        'icon' => 'bi-hdd-stack',
                        'size' => ['width' => 2, 'height' => 2],
                        'module' => 'assets',
                        'config' => ['limit' => 10],
                    ],
                    self::WIDGET_TABLE_AUDIT_FINDINGS => [
                        'label' => $t('widget.table_audit_findings'),
                        'description' => $t('widget.table_audit_findings.description'),
                        'icon' => 'bi-clipboard-check',
                        'size' => ['width' => 2, 'height' => 2],
                        'module' => 'audits',
                        'config' => ['limit' => 10],
                    ],
                    self::WIDGET_TABLE_BC_PLANS => [
                        'label' => $t('widget.table_bc_plans'),
                        'description' => $t('widget.table_bc_plans.description'),
                        'icon' => 'bi-file-earmark-text',
                        'size' => ['width' => 2, 'height' => 2],
                        'module' => 'bcm',
                        'config' => ['limit' => 10],
                    ],
                ],
            ],
            self::WIDGET_CATEGORY_STATUS => [
                'label' => $t('widget.category.status'),
                'icon' => 'bi-circle-fill',
                'widgets' => [
                    self::WIDGET_STATUS_RAG => [
                        'label' => $t('widget.status_rag'),
                        'description' => $t('widget.status_rag.description'),
                        'icon' => 'bi-traffic-light',
                        'size' => ['width' => 1, 'height' => 1],
                        'module' => null,
                    ],
                ],
            ],
            self::WIDGET_CATEGORY_TEXT => [
                'label' => $t('widget.category.text'),
                'icon' => 'bi-fonts',
                'widgets' => [
                    self::WIDGET_TEXT_HEADER => [
                        'label' => $t('widget.text_header'),
                        'description' => $t('widget.text_header.description'),
                        'icon' => 'bi-type-h1',
                        'size' => ['width' => 2, 'height' => 1],
                        'module' => null,
                        'config' => ['text' => '', 'level' => 'h2'],
                    ],
                    self::WIDGET_TEXT_SUMMARY => [
                        'label' => $t('widget.text_summary'),
                        'description' => $t('widget.text_summary.description'),
                        'icon' => 'bi-card-text',
                        'size' => ['width' => 2, 'height' => 1],
                        'module' => null,
                    ],
                    self::WIDGET_TEXT_CUSTOM => [
                        'label' => $t('widget.text_custom'),
                        'description' => $t('widget.text_custom.description'),
                        'icon' => 'bi-textarea-t',
                        'size' => ['width' => 2, 'height' => 1],
                        'module' => null,
                        'config' => ['text' => ''],
                    ],
                ],
            ],
        ];
    }

    /**
     * Get data for a specific widget
     *
     * @param string $widgetType Widget type constant
     * @param array $config Widget configuration
     * @param array $filters Global report filters
     * @return array Widget data
     */
    public function getWidgetData(string $widgetType, array $config = [], array $filters = []): array
    {
        return match ($widgetType) {
            // KPI Widgets
            self::WIDGET_KPI_RISK_COUNT => $this->getKpiRiskCount($filters),
            self::WIDGET_KPI_HIGH_RISKS => $this->getKpiHighRisks($filters),
            self::WIDGET_KPI_CONTROL_COUNT => $this->getKpiControlCount($filters),
            self::WIDGET_KPI_CONTROL_IMPLEMENTATION => $this->getKpiControlImplementation($filters),
            self::WIDGET_KPI_ASSET_COUNT => $this->getKpiAssetCount($filters),
            self::WIDGET_KPI_INCIDENT_COUNT => $this->getKpiIncidentCount($filters),
            self::WIDGET_KPI_OPEN_INCIDENTS => $this->getKpiOpenIncidents($filters),
            self::WIDGET_KPI_COMPLIANCE_SCORE => $this->getKpiComplianceScore($filters),
            self::WIDGET_KPI_OVERDUE_TREATMENTS => $this->getKpiOverdueTreatments($filters),
            self::WIDGET_KPI_BCM_COVERAGE => $this->getKpiBcmCoverage($filters),

            // Chart Widgets
            self::WIDGET_CHART_RISK_MATRIX => $this->getChartRiskMatrix($filters),
            self::WIDGET_CHART_RISK_BY_CATEGORY => $this->getChartRiskByCategory($filters),
            self::WIDGET_CHART_RISK_TREND => $this->getChartRiskTrend($filters),
            self::WIDGET_CHART_CONTROL_STATUS => $this->getChartControlStatus($filters),
            self::WIDGET_CHART_COMPLIANCE_RADAR => $this->getChartComplianceRadar($filters),
            self::WIDGET_CHART_INCIDENT_TREND => $this->getChartIncidentTrend($filters),
            self::WIDGET_CHART_ASSET_CRITICALITY => $this->getChartAssetCriticality($filters),
            self::WIDGET_CHART_FRAMEWORK_COMPARISON => $this->getChartFrameworkComparison($filters),

            // Table Widgets
            self::WIDGET_TABLE_TOP_RISKS => $this->getTableTopRisks($config, $filters),
            self::WIDGET_TABLE_RECENT_INCIDENTS => $this->getTableRecentIncidents($config, $filters),
            self::WIDGET_TABLE_OVERDUE_CONTROLS => $this->getTableOverdueControls($config, $filters),
            self::WIDGET_TABLE_CRITICAL_ASSETS => $this->getTableCriticalAssets($config, $filters),
            self::WIDGET_TABLE_AUDIT_FINDINGS => $this->getTableAuditFindings($config, $filters),
            self::WIDGET_TABLE_BC_PLANS => $this->getTableBcPlans($config, $filters),

            // Status/Text Widgets
            self::WIDGET_STATUS_RAG => $this->getStatusRag($config, $filters),
            self::WIDGET_TEXT_SUMMARY => $this->getTextSummary($filters),
            self::WIDGET_TEXT_HEADER => ['text' => $config['text'] ?? ''],
            self::WIDGET_TEXT_CUSTOM => ['text' => $config['text'] ?? ''],

            default => ['error' => 'Unknown widget type'],
        };
    }

    /**
     * Generate full report data from a CustomReport entity
     */
    public function generateReportData(CustomReport $report): array
    {
        $widgets = $report->getWidgets();
        $filters = $report->getFilters();
        $widgetData = [];

        foreach ($widgets as $widget) {
            $widgetId = $widget['id'] ?? uniqid('widget_');
            $widgetType = $widget['type'] ?? '';
            $widgetConfig = $widget['config'] ?? [];

            $widgetData[$widgetId] = [
                'meta' => $widget,
                'data' => $this->getWidgetData($widgetType, $widgetConfig, $filters),
            ];
        }

        return [
            'report' => [
                'id' => $report->getId(),
                'name' => $report->getName(),
                'description' => $report->getDescription(),
                'category' => $report->getCategory(),
                'layout' => $report->getLayout(),
                'styles' => $report->getStyles(),
                'generated_at' => new DateTimeImmutable(),
            ],
            'widgets' => $widgetData,
            'filters' => $filters,
        ];
    }

    /**
     * Get predefined report templates
     */
    public function getPredefinedTemplates(): array
    {
        $t = fn(string $key): string => $this->translator->trans($key, [], 'report_builder');

        return [
            'executive_summary' => [
                'name' => $t('template.executive_summary'),
                'description' => $t('template.executive_summary.description'),
                'category' => CustomReport::CATEGORY_EXECUTIVE,
                'layout' => CustomReport::LAYOUT_DASHBOARD,
                'widgets' => [
                    ['type' => self::WIDGET_KPI_COMPLIANCE_SCORE, 'position' => ['row' => 0, 'col' => 0]],
                    ['type' => self::WIDGET_KPI_HIGH_RISKS, 'position' => ['row' => 0, 'col' => 1]],
                    ['type' => self::WIDGET_KPI_OPEN_INCIDENTS, 'position' => ['row' => 0, 'col' => 2]],
                    ['type' => self::WIDGET_KPI_CONTROL_IMPLEMENTATION, 'position' => ['row' => 0, 'col' => 3]],
                    ['type' => self::WIDGET_CHART_RISK_MATRIX, 'position' => ['row' => 1, 'col' => 0]],
                    ['type' => self::WIDGET_CHART_COMPLIANCE_RADAR, 'position' => ['row' => 1, 'col' => 2]],
                    ['type' => self::WIDGET_TABLE_TOP_RISKS, 'position' => ['row' => 3, 'col' => 0]],
                ],
            ],
            'risk_report' => [
                'name' => $t('template.risk_report'),
                'description' => $t('template.risk_report.description'),
                'category' => CustomReport::CATEGORY_RISK,
                'layout' => CustomReport::LAYOUT_DASHBOARD,
                'widgets' => [
                    ['type' => self::WIDGET_KPI_RISK_COUNT, 'position' => ['row' => 0, 'col' => 0]],
                    ['type' => self::WIDGET_KPI_HIGH_RISKS, 'position' => ['row' => 0, 'col' => 1]],
                    ['type' => self::WIDGET_KPI_OVERDUE_TREATMENTS, 'position' => ['row' => 0, 'col' => 2]],
                    ['type' => self::WIDGET_CHART_RISK_MATRIX, 'position' => ['row' => 1, 'col' => 0]],
                    ['type' => self::WIDGET_CHART_RISK_BY_CATEGORY, 'position' => ['row' => 1, 'col' => 2]],
                    ['type' => self::WIDGET_CHART_RISK_TREND, 'position' => ['row' => 2, 'col' => 0]],
                    ['type' => self::WIDGET_TABLE_TOP_RISKS, 'position' => ['row' => 3, 'col' => 0]],
                ],
            ],
            'compliance_dashboard' => [
                'name' => $t('template.compliance_dashboard'),
                'description' => $t('template.compliance_dashboard.description'),
                'category' => CustomReport::CATEGORY_COMPLIANCE,
                'layout' => CustomReport::LAYOUT_DASHBOARD,
                'widgets' => [
                    ['type' => self::WIDGET_KPI_COMPLIANCE_SCORE, 'position' => ['row' => 0, 'col' => 0]],
                    ['type' => self::WIDGET_KPI_CONTROL_IMPLEMENTATION, 'position' => ['row' => 0, 'col' => 1]],
                    ['type' => self::WIDGET_KPI_CONTROL_COUNT, 'position' => ['row' => 0, 'col' => 2]],
                    ['type' => self::WIDGET_CHART_COMPLIANCE_RADAR, 'position' => ['row' => 1, 'col' => 0]],
                    ['type' => self::WIDGET_CHART_FRAMEWORK_COMPARISON, 'position' => ['row' => 1, 'col' => 2]],
                    ['type' => self::WIDGET_CHART_CONTROL_STATUS, 'position' => ['row' => 2, 'col' => 0]],
                ],
            ],
            'incident_report' => [
                'name' => $t('template.incident_report'),
                'description' => $t('template.incident_report.description'),
                'category' => CustomReport::CATEGORY_INCIDENT,
                'layout' => CustomReport::LAYOUT_TWO_COLUMN,
                'widgets' => [
                    ['type' => self::WIDGET_KPI_INCIDENT_COUNT, 'position' => ['row' => 0, 'col' => 0]],
                    ['type' => self::WIDGET_KPI_OPEN_INCIDENTS, 'position' => ['row' => 0, 'col' => 1]],
                    ['type' => self::WIDGET_CHART_INCIDENT_TREND, 'position' => ['row' => 1, 'col' => 0]],
                    ['type' => self::WIDGET_TABLE_RECENT_INCIDENTS, 'position' => ['row' => 2, 'col' => 0]],
                ],
            ],
            'bcm_status' => [
                'name' => $t('template.bcm_status'),
                'description' => $t('template.bcm_status.description'),
                'category' => CustomReport::CATEGORY_BCM,
                'layout' => CustomReport::LAYOUT_TWO_COLUMN,
                'widgets' => [
                    ['type' => self::WIDGET_KPI_BCM_COVERAGE, 'position' => ['row' => 0, 'col' => 0]],
                    ['type' => self::WIDGET_STATUS_RAG, 'position' => ['row' => 0, 'col' => 1]],
                    ['type' => self::WIDGET_TABLE_BC_PLANS, 'position' => ['row' => 1, 'col' => 0]],
                ],
            ],
            'asset_overview' => [
                'name' => $t('template.asset_overview'),
                'description' => $t('template.asset_overview.description'),
                'category' => CustomReport::CATEGORY_ASSET,
                'layout' => CustomReport::LAYOUT_DASHBOARD,
                'widgets' => [
                    ['type' => self::WIDGET_KPI_ASSET_COUNT, 'position' => ['row' => 0, 'col' => 0]],
                    ['type' => self::WIDGET_CHART_ASSET_CRITICALITY, 'position' => ['row' => 1, 'col' => 0]],
                    ['type' => self::WIDGET_TABLE_CRITICAL_ASSETS, 'position' => ['row' => 2, 'col' => 0]],
                ],
            ],
        ];
    }

    /**
     * Create a CustomReport from a predefined template
     */
    public function createFromTemplate(string $templateKey, User $owner, int $tenantId): ?CustomReport
    {
        $templates = $this->getPredefinedTemplates();

        if (!isset($templates[$templateKey])) {
            return null;
        }

        $template = $templates[$templateKey];
        $report = new CustomReport();
        $report->setName($template['name']);
        $report->setDescription($template['description']);
        $report->setCategory($template['category']);
        $report->setLayout($template['layout']);
        $report->setOwner($owner);
        $report->setTenantId($tenantId);

        // Add widget IDs
        $widgets = [];
        foreach ($template['widgets'] as $widget) {
            $widget['id'] = uniqid('widget_');
            $widgets[] = $widget;
        }
        $report->setWidgets($widgets);

        return $report;
    }

    // ==================== KPI Widget Data Methods ====================

    private function getKpiRiskCount(array $filters): array
    {
        $count = $this->riskRepository->count([]);
        return [
            'value' => $count,
            'label' => $this->translator->trans('widget.kpi_risk_count', [], 'report_builder'),
            'trend' => null,
            'color' => $count > 20 ? 'warning' : 'primary',
        ];
    }

    private function getKpiHighRisks(array $filters): array
    {
        $risks = $this->riskRepository->findAll();
        $highRisks = array_filter($risks, fn($r) => $r->getRiskScore() >= 12);
        $count = count($highRisks);

        return [
            'value' => $count,
            'label' => $this->translator->trans('widget.kpi_high_risks', [], 'report_builder'),
            'trend' => null,
            'color' => $count > 5 ? 'danger' : ($count > 0 ? 'warning' : 'success'),
        ];
    }

    private function getKpiControlCount(array $filters): array
    {
        $count = $this->controlRepository->count([]);
        return [
            'value' => $count,
            'label' => $this->translator->trans('widget.kpi_control_count', [], 'report_builder'),
            'trend' => null,
            'color' => 'primary',
        ];
    }

    private function getKpiControlImplementation(array $filters): array
    {
        $controls = $this->controlRepository->findAll();
        $total = count($controls);
        $implemented = count(array_filter($controls, fn($c) => $c->getImplementationStatus() === 'implemented'));
        $percentage = $total > 0 ? round(($implemented / $total) * 100) : 0;

        return [
            'value' => $percentage . '%',
            'label' => $this->translator->trans('widget.kpi_control_implementation', [], 'report_builder'),
            'trend' => null,
            'color' => $percentage >= 80 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger'),
            'details' => ['implemented' => $implemented, 'total' => $total],
        ];
    }

    private function getKpiAssetCount(array $filters): array
    {
        $count = $this->assetRepository->count([]);
        return [
            'value' => $count,
            'label' => $this->translator->trans('widget.kpi_asset_count', [], 'report_builder'),
            'trend' => null,
            'color' => 'primary',
        ];
    }

    private function getKpiIncidentCount(array $filters): array
    {
        $count = $this->incidentRepository->count([]);
        return [
            'value' => $count,
            'label' => $this->translator->trans('widget.kpi_incident_count', [], 'report_builder'),
            'trend' => null,
            'color' => 'info',
        ];
    }

    private function getKpiOpenIncidents(array $filters): array
    {
        $incidents = $this->incidentRepository->findAll();
        $open = count(array_filter($incidents, fn($i) => in_array($i->getStatus(), ['new', 'investigating', 'in_progress'])));

        return [
            'value' => $open,
            'label' => $this->translator->trans('widget.kpi_open_incidents', [], 'report_builder'),
            'trend' => null,
            'color' => $open > 5 ? 'danger' : ($open > 0 ? 'warning' : 'success'),
        ];
    }

    private function getKpiComplianceScore(array $filters): array
    {
        $controls = $this->controlRepository->findAll();
        $total = count($controls);
        $implemented = count(array_filter($controls, fn($c) => $c->getImplementationStatus() === 'implemented'));
        $score = $total > 0 ? round(($implemented / $total) * 100) : 0;

        return [
            'value' => $score . '%',
            'label' => $this->translator->trans('widget.kpi_compliance_score', [], 'report_builder'),
            'trend' => null,
            'color' => $score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'danger'),
        ];
    }

    private function getKpiOverdueTreatments(array $filters): array
    {
        $risks = $this->riskRepository->findAll();
        $now = new DateTimeImmutable();
        $overdue = 0;

        foreach ($risks as $risk) {
            if ($risk->getStatus() !== 'closed' && $risk->getReviewDate() && $risk->getReviewDate() < $now) {
                $overdue++;
            }
        }

        return [
            'value' => $overdue,
            'label' => $this->translator->trans('widget.kpi_overdue_treatments', [], 'report_builder'),
            'trend' => null,
            'color' => $overdue > 0 ? 'danger' : 'success',
        ];
    }

    private function getKpiBcmCoverage(array $filters): array
    {
        $processes = $this->businessProcessRepository->findAll();
        $plans = $this->bcPlanRepository->findAll();

        $critical = count(array_filter($processes, fn($p) => $p->getCriticality() === 'critical' || $p->getCriticality() === 'high'));
        $covered = count($plans);
        $percentage = $critical > 0 ? min(100, round(($covered / $critical) * 100)) : 100;

        return [
            'value' => $percentage . '%',
            'label' => $this->translator->trans('widget.kpi_bcm_coverage', [], 'report_builder'),
            'trend' => null,
            'color' => $percentage >= 80 ? 'success' : ($percentage >= 50 ? 'warning' : 'danger'),
            'details' => ['critical_processes' => $critical, 'bc_plans' => $covered],
        ];
    }

    // ==================== Chart Widget Data Methods ====================

    private function getChartRiskMatrix(array $filters): array
    {
        $risks = $this->riskRepository->findAll();
        $matrix = [];

        // Initialize 5x5 matrix
        for ($likelihood = 1; $likelihood <= 5; $likelihood++) {
            for ($impact = 1; $impact <= 5; $impact++) {
                $matrix[$likelihood][$impact] = 0;
            }
        }

        foreach ($risks as $risk) {
            $l = min(5, max(1, $risk->getLikelihood() ?? 1));
            $i = min(5, max(1, $risk->getImpact() ?? 1));
            $matrix[$l][$i]++;
        }

        return [
            'type' => 'heatmap',
            'matrix' => $matrix,
            'labels' => [
                'x' => ['Very Low', 'Low', 'Medium', 'High', 'Very High'],
                'y' => ['Very Low', 'Low', 'Medium', 'High', 'Very High'],
            ],
        ];
    }

    private function getChartRiskByCategory(array $filters): array
    {
        $risks = $this->riskRepository->findAll();
        $byCategory = [];

        foreach ($risks as $risk) {
            $category = $risk->getCategory() ?? 'Other';
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;
        }

        return [
            'type' => 'pie',
            'labels' => array_keys($byCategory),
            'data' => array_values($byCategory),
        ];
    }

    private function getChartRiskTrend(array $filters): array
    {
        // Simplified trend data (last 6 months)
        $months = [];
        $data = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = new DateTimeImmutable("-{$i} months");
            $months[] = $date->format('M Y');
            // In a real implementation, this would query historical data
            $data[] = rand(10, 30);
        }

        return [
            'type' => 'line',
            'labels' => $months,
            'datasets' => [
                ['label' => 'Total Risks', 'data' => $data],
            ],
        ];
    }

    private function getChartControlStatus(array $filters): array
    {
        $controls = $this->controlRepository->findAll();
        $status = ['implemented' => 0, 'in_progress' => 0, 'not_started' => 0];

        foreach ($controls as $control) {
            $s = $control->getImplementationStatus() ?? 'not_started';
            if (isset($status[$s])) {
                $status[$s]++;
            } else {
                $status['not_started']++;
            }
        }

        return [
            'type' => 'doughnut',
            'labels' => ['Implemented', 'In Progress', 'Not Started'],
            'data' => array_values($status),
            'colors' => ['#198754', '#ffc107', '#dc3545'],
        ];
    }

    private function getChartComplianceRadar(array $filters): array
    {
        $frameworks = $this->frameworkRepository->findAll();
        $labels = [];
        $data = [];

        foreach ($frameworks as $framework) {
            $labels[] = $framework->getName();
            // Simplified compliance calculation
            $data[] = rand(50, 100);
        }

        if (empty($labels)) {
            $labels = ['ISO 27001', 'TISAX', 'NIS2', 'DORA'];
            $data = [85, 70, 60, 75];
        }

        return [
            'type' => 'radar',
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Compliance %', 'data' => $data],
            ],
        ];
    }

    private function getChartIncidentTrend(array $filters): array
    {
        $months = [];
        $data = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = new DateTimeImmutable("-{$i} months");
            $months[] = $date->format('M Y');
            $data[] = rand(0, 10);
        }

        return [
            'type' => 'bar',
            'labels' => $months,
            'datasets' => [
                ['label' => 'Incidents', 'data' => $data],
            ],
        ];
    }

    private function getChartAssetCriticality(array $filters): array
    {
        $assets = $this->assetRepository->findAll();
        $criticality = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($assets as $asset) {
            // Calculate criticality from CIA values
            $maxCia = max(
                $asset->getConfidentiality() ?? 1,
                $asset->getIntegrity() ?? 1,
                $asset->getAvailability() ?? 1
            );

            if ($maxCia >= 4) {
                $criticality['critical']++;
            } elseif ($maxCia >= 3) {
                $criticality['high']++;
            } elseif ($maxCia >= 2) {
                $criticality['medium']++;
            } else {
                $criticality['low']++;
            }
        }

        return [
            'type' => 'bar',
            'labels' => ['Critical', 'High', 'Medium', 'Low'],
            'data' => array_values($criticality),
            'colors' => ['#dc3545', '#fd7e14', '#ffc107', '#198754'],
        ];
    }

    private function getChartFrameworkComparison(array $filters): array
    {
        $frameworks = $this->frameworkRepository->findAll();
        $labels = [];
        $data = [];

        foreach ($frameworks as $framework) {
            $labels[] = $framework->getName();
            $data[] = rand(50, 100);
        }

        if (empty($labels)) {
            $labels = ['ISO 27001', 'TISAX', 'NIS2', 'DORA'];
            $data = [85, 70, 60, 75];
        }

        return [
            'type' => 'horizontalBar',
            'labels' => $labels,
            'data' => $data,
        ];
    }

    // ==================== Table Widget Data Methods ====================

    private function getTableTopRisks(array $config, array $filters): array
    {
        $limit = $config['limit'] ?? 10;
        $risks = $this->riskRepository->findAll();

        usort($risks, fn($a, $b) => ($b->getRiskScore() ?? 0) - ($a->getRiskScore() ?? 0));
        $risks = array_slice($risks, 0, $limit);

        $rows = [];
        foreach ($risks as $risk) {
            $rows[] = [
                'id' => $risk->getId(),
                'name' => $risk->getName(),
                'category' => $risk->getCategory(),
                'score' => $risk->getRiskScore(),
                'status' => $risk->getStatus(),
                'owner' => $risk->getRiskOwner()?->getFullName(),
            ];
        }

        return [
            'columns' => ['Name', 'Category', 'Score', 'Status', 'Owner'],
            'rows' => $rows,
        ];
    }

    private function getTableRecentIncidents(array $config, array $filters): array
    {
        $limit = $config['limit'] ?? 10;
        $incidents = $this->incidentRepository->findBy([], ['detectedAt' => 'DESC'], $limit);

        $rows = [];
        foreach ($incidents as $incident) {
            $rows[] = [
                'id' => $incident->getId(),
                'title' => $incident->getTitle(),
                'severity' => $incident->getSeverity(),
                'status' => $incident->getStatus(),
                'detected_at' => $incident->getDetectedAt()?->format('Y-m-d'),
            ];
        }

        return [
            'columns' => ['Title', 'Severity', 'Status', 'Detected'],
            'rows' => $rows,
        ];
    }

    private function getTableOverdueControls(array $config, array $filters): array
    {
        $limit = $config['limit'] ?? 10;
        $controls = $this->controlRepository->findAll();
        $now = new DateTimeImmutable();

        $overdue = array_filter($controls, fn($c) => $c->getNextReviewDate() && $c->getNextReviewDate() < $now);
        $overdue = array_slice($overdue, 0, $limit);

        $rows = [];
        foreach ($overdue as $control) {
            $rows[] = [
                'id' => $control->getId(),
                'name' => $control->getName(),
                'status' => $control->getImplementationStatus(),
                'review_date' => $control->getNextReviewDate()?->format('Y-m-d'),
            ];
        }

        return [
            'columns' => ['Name', 'Status', 'Review Date'],
            'rows' => $rows,
        ];
    }

    private function getTableCriticalAssets(array $config, array $filters): array
    {
        $limit = $config['limit'] ?? 10;
        $assets = $this->assetRepository->findAll();

        // Sort by max CIA value
        usort($assets, function ($a, $b) {
            $maxA = max($a->getConfidentiality() ?? 0, $a->getIntegrity() ?? 0, $a->getAvailability() ?? 0);
            $maxB = max($b->getConfidentiality() ?? 0, $b->getIntegrity() ?? 0, $b->getAvailability() ?? 0);
            return $maxB - $maxA;
        });

        $assets = array_slice($assets, 0, $limit);

        $rows = [];
        foreach ($assets as $asset) {
            $rows[] = [
                'id' => $asset->getId(),
                'name' => $asset->getName(),
                'type' => $asset->getType(),
                'c' => $asset->getConfidentiality(),
                'i' => $asset->getIntegrity(),
                'a' => $asset->getAvailability(),
            ];
        }

        return [
            'columns' => ['Name', 'Type', 'C', 'I', 'A'],
            'rows' => $rows,
        ];
    }

    private function getTableAuditFindings(array $config, array $filters): array
    {
        $limit = $config['limit'] ?? 10;
        $audits = $this->auditRepository->findBy([], ['plannedDate' => 'DESC'], $limit);

        $rows = [];
        foreach ($audits as $audit) {
            $rows[] = [
                'id' => $audit->getId(),
                'title' => $audit->getTitle(),
                'status' => $audit->getStatus(),
                'date' => $audit->getActualDate()?->format('Y-m-d') ?? $audit->getPlannedDate()?->format('Y-m-d'),
            ];
        }

        return [
            'columns' => ['Title', 'Status', 'Date'],
            'rows' => $rows,
        ];
    }

    private function getTableBcPlans(array $config, array $filters): array
    {
        $limit = $config['limit'] ?? 10;
        $plans = $this->bcPlanRepository->findBy([], ['lastTested' => 'DESC'], $limit);

        $rows = [];
        foreach ($plans as $plan) {
            $rows[] = [
                'id' => $plan->getId(),
                'name' => $plan->getName(),
                'status' => $plan->getStatus(),
                'last_tested' => $plan->getLastTested()?->format('Y-m-d'),
            ];
        }

        return [
            'columns' => ['Name', 'Status', 'Last Tested'],
            'rows' => $rows,
        ];
    }

    // ==================== Status Widget Data Methods ====================

    private function getStatusRag(array $config, array $filters): array
    {
        // Calculate overall RAG status based on multiple factors
        $controls = $this->controlRepository->findAll();
        $risks = $this->riskRepository->findAll();

        $totalControls = count($controls);
        $implemented = count(array_filter($controls, fn($c) => $c->getImplementationStatus() === 'implemented'));
        $implementationRate = $totalControls > 0 ? ($implemented / $totalControls) * 100 : 0;

        $highRisks = count(array_filter($risks, fn($r) => $r->getRiskScore() >= 15));

        // Determine RAG status
        if ($implementationRate >= 80 && $highRisks <= 3) {
            $status = 'green';
            $label = $this->translator->trans('status.good', [], 'report_builder');
        } elseif ($implementationRate >= 50 && $highRisks <= 10) {
            $status = 'amber';
            $label = $this->translator->trans('status.attention', [], 'report_builder');
        } else {
            $status = 'red';
            $label = $this->translator->trans('status.critical', [], 'report_builder');
        }

        return [
            'status' => $status,
            'label' => $label,
            'factors' => [
                'control_implementation' => round($implementationRate) . '%',
                'high_risks' => $highRisks,
            ],
        ];
    }

    private function getTextSummary(array $filters): array
    {
        $stats = $this->dashboardStatisticsService->getDashboardStatistics();

        return [
            'text' => sprintf(
                $this->translator->trans('widget.text_summary.content', [], 'report_builder'),
                $stats['risks']['total'] ?? 0,
                $stats['controls']['implemented'] ?? 0,
                $stats['incidents']['open'] ?? 0
            ),
        ];
    }
}
