<?php

namespace App\Controller;

use DateTime;
use DateTimeImmutable;
use App\Repository\ControlRepository;
use App\Repository\KpiSnapshotRepository;
use App\Repository\RiskRepository;
use App\Service\ComplianceAnalyticsService;
use App\Service\ComplianceWizardService;
use App\Service\DashboardStatisticsService;
use App\Service\ManagementReportService;
use App\Service\PdfExportService;
use App\Service\ExcelExportService;
use App\Service\RoleDashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Management Report Controller
 *
 * Phase 7A: Provides management reporting endpoints for executive dashboards,
 * risk management, BCM, compliance, audit, and asset reports.
 */
#[Route('/reports/management')]
#[IsGranted('ROLE_AUDITOR')]
class ManagementReportController extends AbstractController
{
    public function __construct(
        private readonly ManagementReportService $reportService,
        private readonly PdfExportService $pdfExportService,
        private readonly ExcelExportService $excelExportService,
        private readonly DashboardStatisticsService $dashboardStatisticsService,
        private readonly RoleDashboardService $roleDashboardService,
        private readonly RiskRepository $riskRepository,
        private readonly ComplianceAnalyticsService $complianceAnalyticsService,
        private readonly Security $security,
        private readonly ComplianceWizardService $complianceWizardService,
        private readonly ControlRepository $controlRepository,
        private readonly KpiSnapshotRepository $kpiSnapshotRepository,
    ) {
    }

    // ===================== REPORT CENTER =====================

    #[Route('/', name: 'app_management_reports')]
    public function index(): Response
    {
        $categories = $this->reportService->getReportCategories();
        $summary = $this->reportService->getExecutiveSummary();

        return $this->render('management_reports/index.html.twig', [
            'categories' => $categories,
            'summary' => $summary,
        ]);
    }

    // ===================== EXECUTIVE REPORTS =====================

    #[Route('/executive', name: 'app_management_reports_executive')]
    public function executive(Request $request): Response
    {
        [$from, $to] = $this->parseDateRange($request);

        $summary = $this->reportService->getExecutiveSummary($from, $to);
        $riskTrends = $this->reportService->getRiskTrendData(6);
        $incidentTrends = $this->reportService->getIncidentTrendData(6);

        return $this->render('management_reports/executive.html.twig', [
            'summary' => $summary,
            'risk_trends' => $riskTrends,
            'incident_trends' => $incidentTrends,
        ]);
    }

    #[Route('/executive/pdf', name: 'app_management_reports_executive_pdf')]
    public function executivePdf(Request $request): Response
    {
        $summary = $this->reportService->getExecutiveSummary();
        $riskTrends = $this->reportService->getRiskTrendData(12);
        $incidentTrends = $this->reportService->getIncidentTrendData(12);

        $request->getSession()->save();

        $pdf = $this->pdfExportService->generatePdf('management_reports/executive_pdf.html.twig', [
            'summary' => $summary,
            'risk_trends' => $riskTrends,
            'incident_trends' => $incidentTrends,
            'generated_at' => new DateTime(),
            'version' => (new DateTime())->format('Y.m.d'),
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="executive_summary_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    // ===================== RISK MANAGEMENT REPORTS =====================

    #[Route('/risk', name: 'app_management_reports_risk')]
    public function riskManagement(Request $request): Response
    {
        [$from, $to] = $this->parseDateRange($request);

        $riskReport = $this->reportService->getRiskManagementReport([], $from, $to);
        $trends = $this->reportService->getRiskTrendData(12);

        return $this->render('management_reports/risk.html.twig', [
            'report' => $riskReport,
            'trends' => $trends,
        ]);
    }

    #[Route('/risk/pdf', name: 'app_management_reports_risk_pdf')]
    public function riskManagementPdf(Request $request): Response
    {
        $riskReport = $this->reportService->getRiskManagementReport();
        $trends = $this->reportService->getRiskTrendData(12);

        $request->getSession()->save();

        $pdf = $this->pdfExportService->generatePdf('management_reports/risk_pdf.html.twig', [
            'report' => $riskReport,
            'trends' => $trends,
            'generated_at' => new DateTime(),
            'version' => (new DateTime())->format('Y.m.d'),
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="risk_management_report_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/risk/excel', name: 'app_management_reports_risk_excel')]
    public function riskManagementExcel(Request $request): Response
    {
        $riskReport = $this->reportService->getRiskManagementReport();

        $request->getSession()->save();

        $headers = ['ID', 'Title', 'Category', 'Likelihood', 'Impact', 'Risk Score', 'Level', 'Treatment', 'Status', 'Owner'];
        $data = [];

        foreach ($riskReport['risks'] as $risk) {
            $score = $risk->getRiskScore();
            $level = match (true) {
                $score >= 20 => 'Critical',
                $score >= 12 => 'High',
                $score >= 6 => 'Medium',
                default => 'Low',
            };

            $data[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getCategory(),
                $risk->getProbability(),
                $risk->getImpact(),
                $score,
                $level,
                $risk->getTreatmentStrategy() ? substr((string) $risk->getTreatmentStrategy(), 0, 50) : '-',
                $risk->getStatus(),
                $risk->getRiskOwner() ? $risk->getRiskOwner()->getEmail() : '-',
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'Risk Management Report');

        // Add summary sheet
        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('Summary');
        $summarySheet->setCellValue('A1', 'Risk Management Summary');
        $summarySheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $row = 3;
        $summarySheet->setCellValue('A' . $row, 'Total Risks:');
        $summarySheet->setCellValue('B' . $row++, $riskReport['total_risks']);
        $summarySheet->setCellValue('A' . $row, 'Critical:');
        $summarySheet->setCellValue('B' . $row++, $riskReport['level_counts']['critical']);
        $summarySheet->setCellValue('A' . $row, 'High:');
        $summarySheet->setCellValue('B' . $row++, $riskReport['level_counts']['high']);
        $summarySheet->setCellValue('A' . $row, 'Medium:');
        $summarySheet->setCellValue('B' . $row++, $riskReport['level_counts']['medium']);
        $summarySheet->setCellValue('A' . $row, 'Low:');
        $summarySheet->setCellValue('B' . $row++, $riskReport['level_counts']['low']);
        $summarySheet->setCellValue('A' . $row, 'Average Risk Score:');
        $summarySheet->setCellValue('B' . $row++, $riskReport['average_risk_score']);

        $spreadsheet->setActiveSheetIndex(0);
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="risk_management_report_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== BCM REPORTS =====================

    #[Route('/bcm', name: 'app_management_reports_bcm')]
    public function bcm(): Response
    {
        $bcmReport = $this->reportService->getBCMReport();
        $biaSummary = $this->reportService->getBIASummary();

        return $this->render('management_reports/bcm.html.twig', [
            'report' => $bcmReport,
            'bia' => $biaSummary,
        ]);
    }

    #[Route('/bcm/pdf', name: 'app_management_reports_bcm_pdf')]
    public function bcmPdf(Request $request): Response
    {
        $bcmReport = $this->reportService->getBCMReport();
        $biaSummary = $this->reportService->getBIASummary();

        $request->getSession()->save();

        $pdf = $this->pdfExportService->generatePdf('management_reports/bcm_pdf.html.twig', [
            'report' => $bcmReport,
            'bia' => $biaSummary,
            'generated_at' => new DateTime(),
            'version' => (new DateTime())->format('Y.m.d'),
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="bcm_report_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    // ===================== COMPLIANCE REPORTS =====================

    #[Route('/compliance', name: 'app_management_reports_compliance')]
    public function compliance(Request $request): Response
    {
        [$from, $to] = $this->parseDateRange($request);

        $complianceReport = $this->reportService->getComplianceStatusReport($from, $to);

        return $this->render('management_reports/compliance.html.twig', [
            'report' => $complianceReport,
        ]);
    }

    #[Route('/compliance/pdf', name: 'app_management_reports_compliance_pdf')]
    public function compliancePdf(Request $request): Response
    {
        $complianceReport = $this->reportService->getComplianceStatusReport();

        $request->getSession()->save();

        $pdf = $this->pdfExportService->generatePdf('management_reports/compliance_pdf.html.twig', [
            'report' => $complianceReport,
            'generated_at' => new DateTime(),
            'version' => (new DateTime())->format('Y.m.d'),
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="compliance_status_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    // ===================== AUDIT REPORTS =====================

    #[Route('/audit', name: 'app_management_reports_audit')]
    public function audit(): Response
    {
        $auditReport = $this->reportService->getAuditManagementReport();

        return $this->render('management_reports/audit.html.twig', [
            'report' => $auditReport,
        ]);
    }

    #[Route('/audit/pdf', name: 'app_management_reports_audit_pdf')]
    public function auditPdf(Request $request): Response
    {
        $auditReport = $this->reportService->getAuditManagementReport();

        $request->getSession()->save();

        $pdf = $this->pdfExportService->generatePdf('management_reports/audit_pdf.html.twig', [
            'report' => $auditReport,
            'generated_at' => new DateTime(),
            'version' => (new DateTime())->format('Y.m.d'),
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="audit_report_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    // ===================== ASSET REPORTS =====================

    #[Route('/assets', name: 'app_management_reports_assets')]
    public function assets(): Response
    {
        $assetReport = $this->reportService->getAssetManagementReport();

        return $this->render('management_reports/assets.html.twig', [
            'report' => $assetReport,
        ]);
    }

    #[Route('/assets/pdf', name: 'app_management_reports_assets_pdf')]
    public function assetsPdf(Request $request): Response
    {
        $assetReport = $this->reportService->getAssetManagementReport();

        $request->getSession()->save();

        $pdf = $this->pdfExportService->generatePdf('management_reports/assets_pdf.html.twig', [
            'report' => $assetReport,
            'generated_at' => new DateTime(),
            'version' => (new DateTime())->format('Y.m.d'),
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="asset_inventory_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/assets/excel', name: 'app_management_reports_assets_excel')]
    public function assetsExcel(Request $request): Response
    {
        $assetReport = $this->reportService->getAssetManagementReport();

        $request->getSession()->save();

        $headers = ['ID', 'Name', 'Type', 'Classification', 'Owner', 'Location', 'Status'];
        $data = [];

        foreach ($assetReport['assets'] as $asset) {
            $data[] = [
                $asset->getId(),
                $asset->getName(),
                $asset->getAssetType(),
                $asset->getClassification(),
                $asset->getOwner() ? $asset->getOwner()->getEmail() : '-',
                $asset->getLocation() ? $asset->getLocation()->getName() : '-',
                $asset->getStatus(),
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'Asset Inventory');
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="asset_inventory_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== GDPR / DATA BREACH REPORTS =====================

    #[Route('/gdpr', name: 'app_management_reports_gdpr')]
    public function gdpr(): Response
    {
        $dataBreachReport = $this->reportService->getDataBreachReport();

        return $this->render('management_reports/gdpr.html.twig', [
            'report' => $dataBreachReport,
        ]);
    }

    #[Route('/gdpr/pdf', name: 'app_management_reports_gdpr_pdf')]
    public function gdprPdf(Request $request): Response
    {
        $dataBreachReport = $this->reportService->getDataBreachReport();

        $request->getSession()->save();

        $pdf = $this->pdfExportService->generatePdf('management_reports/gdpr_pdf.html.twig', [
            'report' => $dataBreachReport,
            'generated_at' => new DateTime(),
            'version' => (new DateTime())->format('Y.m.d'),
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="data_breach_report_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    // ===================== BOARD ONE-PAGER =====================

    #[Route('/board-one-pager/pdf', name: 'app_reports_board_one_pager_pdf')]
    #[IsGranted('ROLE_MANAGER')]
    public function boardOnePagerPdf(Request $request): Response
    {
        // Gather data from existing services
        $kpis = $this->dashboardStatisticsService->getManagementKPIs();
        $boardData = $this->roleDashboardService->getBoardDashboard();

        // Top 5 risks sorted by inherent risk level
        $tenant = $this->security->getUser()?->getTenant();
        $allRisks = $tenant ? $this->riskRepository->findByTenant($tenant) : [];
        usort($allRisks, fn($a, $b) => $b->getInherentRiskLevel() - $a->getInherentRiskLevel());
        $topRiskEntities = array_slice($allRisks, 0, 5);

        // Convert to arrays with 'level' key for template compatibility
        $topRisks = [];
        foreach ($topRiskEntities as $risk) {
            $score = $risk->getInherentRiskLevel();
            $level = match (true) {
                $score >= 20 => 'Critical',
                $score >= 12 => 'High',
                $score >= 6 => 'Medium',
                default => 'Low',
            };
            $topRisks[] = [
                'title' => $risk->getTitle(),
                'level' => $level,
                'score' => $score,
            ];
        }

        // Framework compliance from ComplianceAnalyticsService
        $frameworkCompliance = [];
        $comparison = $this->complianceAnalyticsService->getFrameworkComparison();
        foreach ($comparison['frameworks'] ?? [] as $fw) {
            $frameworkCompliance[] = [
                'name' => $fw['name'],
                'percentage' => round($fw['compliance_percentage'] ?? 0),
            ];
        }

        $request->getSession()->save();

        $generatedAt = new DateTime();

        $pdf = $this->pdfExportService->generatePdf('reports/board_one_pager.html.twig', [
            'board_data' => $boardData,
            'kpis' => $kpis,
            'top_risks' => $topRisks,
            'framework_compliance' => $frameworkCompliance,
            'prepared_by' => $this->security->getUser()?->getFullName() ?? 'System',
            'generated_at' => $generatedAt,
        ], [
            'classification' => 'CONFIDENTIAL',
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="board-one-pager_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    // ===================== CERTIFICATION READINESS =====================

    #[Route('/certification-readiness', name: 'app_management_reports_cert_readiness')]
    #[IsGranted('ROLE_MANAGER')]
    public function certificationReadiness(): Response
    {
        $data = $this->getCertificationReadinessData();

        return $this->render('management_reports/certification_readiness.html.twig', $data);
    }

    #[Route('/certification-readiness/pdf', name: 'app_management_reports_cert_readiness_pdf')]
    #[IsGranted('ROLE_MANAGER')]
    public function certificationReadinessPdf(Request $request): Response
    {
        $data = $this->getCertificationReadinessData();
        $data['generated_at'] = new DateTime();
        $data['version'] = (new DateTime())->format('Y.m.d');

        $request->getSession()->save();

        $pdf = $this->pdfExportService->generatePdf(
            'management_reports/certification_readiness_pdf.html.twig',
            $data,
            ['classification' => 'CONFIDENTIAL']
        );

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="certification_readiness_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    /**
     * Assemble certification readiness data from multiple sources
     */
    private function getCertificationReadinessData(): array
    {
        $tenant = $this->security->getUser()?->getTenant();

        // Section 1: Clause compliance via ComplianceWizardService
        $clauseCompliance = [];
        $isoAssessment = null;
        if ($this->complianceWizardService->isWizardAvailable('iso27001')) {
            $isoAssessment = $this->complianceWizardService->runAssessment('iso27001');
            if ($isoAssessment['success']) {
                foreach ($isoAssessment['categories'] as $key => $category) {
                    $clauseCompliance[$key] = [
                        'name' => $category['name'] ?? $key,
                        'score' => $category['score'] ?? 0,
                        'status' => match (true) {
                            ($category['score'] ?? 0) >= 90 => 'complete',
                            ($category['score'] ?? 0) >= 40 => 'partial',
                            default => 'not_started',
                        },
                        'gaps' => $category['gaps'] ?? [],
                        'items' => $category['items'] ?? [],
                    ];
                }
            }
        }

        // Section 2: Annex A control coverage
        $controlStats = ['total' => 0, 'implemented' => 0, 'in_progress' => 0, 'not_started' => 0, 'not_applicable' => 0];
        $controlsNotStarted = [];
        $controlsInProgress = [];
        if ($tenant) {
            $controlStats = $this->controlRepository->getImplementationStats($tenant);
            $allControls = $this->controlRepository->findByTenant($tenant);
            foreach ($allControls as $control) {
                if (!$control->isApplicable()) {
                    continue;
                }
                $status = $control->getImplementationStatus();
                if ($status === 'not_started') {
                    $controlsNotStarted[] = [
                        'id' => $control->getControlId(),
                        'title' => $control->getTitle(),
                    ];
                } elseif ($status === 'in_progress') {
                    $controlsInProgress[] = [
                        'id' => $control->getControlId(),
                        'title' => $control->getTitle(),
                    ];
                }
            }
        }

        $applicableTotal = $controlStats['total'];
        $controlPercentage = $applicableTotal > 0
            ? round(($controlStats['implemented'] / $applicableTotal) * 100, 1)
            : 0;

        // Section 3: Readiness score
        $clauseScore = $isoAssessment && $isoAssessment['success']
            ? $isoAssessment['overall_score']
            : 0;
        // Weighted: 60% clause compliance + 40% control implementation
        $overallScore = round(($clauseScore * 0.6) + ($controlPercentage * 0.4), 1);

        $recommendation = match (true) {
            $overallScore >= 85 => 'READY',
            $overallScore >= 70 => 'CONDITIONAL',
            default => 'NOT_READY',
        };

        // Section 4: Gap list
        $criticalGaps = $isoAssessment && $isoAssessment['success']
            ? $isoAssessment['critical_gaps']
            : [];
        $recommendedImprovements = [];
        if ($isoAssessment && $isoAssessment['success']) {
            foreach ($isoAssessment['categories'] as $category) {
                foreach ($category['gaps'] ?? [] as $gap) {
                    if (($gap['priority'] ?? 'medium') !== 'critical') {
                        $recommendedImprovements[] = $gap;
                    }
                }
            }
        }

        // Dashboard KPIs for additional context
        $kpis = $this->dashboardStatisticsService->getManagementKPIs();

        return [
            'clause_compliance' => $clauseCompliance,
            'clause_score' => $clauseScore,
            'control_stats' => $controlStats,
            'control_percentage' => $controlPercentage,
            'controls_not_started' => $controlsNotStarted,
            'controls_in_progress' => $controlsInProgress,
            'overall_score' => $overallScore,
            'recommendation' => $recommendation,
            'critical_gaps' => $criticalGaps,
            'recommended_improvements' => $recommendedImprovements,
            'kpis' => $kpis,
        ];
    }

    // ===================== BCM EXCEL EXPORT =====================

    #[Route('/bcm/excel', name: 'app_management_reports_bcm_excel')]
    public function bcmExcel(Request $request): Response
    {
        $bcmReport = $this->reportService->getBCMReport();

        $request->getSession()->save();

        // BC Plans sheet
        $headers = ['ID', 'Name', 'Status', 'Last Review', 'Next Review'];
        $data = [];

        foreach ($bcmReport['bc_plans']['plans'] as $plan) {
            $data[] = [
                $plan->getId(),
                $plan->getName(),
                $plan->getStatus(),
                $plan->getLastReviewDate() ? $plan->getLastReviewDate()->format('Y-m-d') : '-',
                $plan->getNextReviewDate() ? $plan->getNextReviewDate()->format('Y-m-d') : '-',
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'BC Plans');

        // Exercises sheet
        $exerciseSheet = $spreadsheet->createSheet();
        $exerciseSheet->setTitle('BC Exercises');
        $exerciseHeaders = ['ID', 'Name', 'Type', 'Date', 'Status', 'Results'];
        $col = 'A';
        foreach ($exerciseHeaders as $header) {
            $exerciseSheet->setCellValue($col . '1', $header);
            $exerciseSheet->getStyle($col . '1')->getFont()->setBold(true);
            $exerciseSheet->getColumnDimension($col)->setAutoSize(true);
            $col = str_increment($col);
        }

        $row = 2;
        foreach ($bcmReport['exercises']['exercises'] as $exercise) {
            $exerciseSheet->setCellValue('A' . $row, $exercise->getId());
            $exerciseSheet->setCellValue('B' . $row, $exercise->getName());
            $exerciseSheet->setCellValue('C' . $row, $exercise->getExerciseType());
            $exerciseSheet->setCellValue('D' . $row, $exercise->getExerciseDate() ? $exercise->getExerciseDate()->format('Y-m-d') : '-');
            $exerciseSheet->setCellValue('E' . $row, $exercise->getStatus());
            $exerciseSheet->setCellValue('F' . $row, $exercise->getResults() ? substr((string) $exercise->getResults(), 0, 100) : '-');
            $row++;
        }

        // Summary sheet
        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('Summary');
        $summarySheet->setCellValue('A1', 'BCM Report Summary');
        $summarySheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $r = 3;
        $summarySheet->setCellValue('A' . $r, 'Total BC Plans:');
        $summarySheet->setCellValue('B' . $r++, $bcmReport['bc_plans']['total']);
        $summarySheet->setCellValue('A' . $r, 'Active Plans:');
        $summarySheet->setCellValue('B' . $r++, $bcmReport['bc_plans']['active']);
        $summarySheet->setCellValue('A' . $r, 'Total Exercises:');
        $summarySheet->setCellValue('B' . $r++, $bcmReport['exercises']['total']);
        $summarySheet->setCellValue('A' . $r, 'Exercises (Last 12 Months):');
        $summarySheet->setCellValue('B' . $r++, $bcmReport['exercises']['last_12_months']);
        $summarySheet->setCellValue('A' . $r, 'Total Business Processes:');
        $summarySheet->setCellValue('B' . $r++, $bcmReport['business_processes']['total']);
        $summarySheet->setCellValue('A' . $r, 'Critical Processes:');
        $summarySheet->setCellValue('B' . $r++, $bcmReport['business_processes']['critical']);

        $spreadsheet->setActiveSheetIndex(0);
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="bcm_report_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== COMPLIANCE EXCEL EXPORT =====================

    #[Route('/compliance/excel', name: 'app_management_reports_compliance_excel')]
    public function complianceExcel(Request $request): Response
    {
        $complianceReport = $this->reportService->getComplianceStatusReport();

        $request->getSession()->save();

        // Frameworks sheet
        $headers = ['Name', 'Code', 'Active'];
        $data = [];

        foreach ($complianceReport['frameworks'] as $framework) {
            $data[] = [
                $framework['name'],
                $framework['code'],
                $framework['active'] ? 'Yes' : 'No',
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'Compliance Frameworks');

        // Summary sheet
        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('Summary');
        $summarySheet->setCellValue('A1', 'Compliance Status Summary');
        $summarySheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $r = 3;
        $summarySheet->setCellValue('A' . $r, 'Overall Compliance:');
        $summarySheet->setCellValue('B' . $r++, $complianceReport['overall_compliance'] . '%');
        $summarySheet->setCellValue('A' . $r, 'Total Controls:');
        $summarySheet->setCellValue('B' . $r++, $complianceReport['controls']['total']);
        $summarySheet->setCellValue('A' . $r, 'Applicable Controls:');
        $summarySheet->setCellValue('B' . $r++, $complianceReport['controls']['applicable']);
        $summarySheet->setCellValue('A' . $r, 'Implemented Controls:');
        $summarySheet->setCellValue('B' . $r++, $complianceReport['controls']['implemented']);
        $summarySheet->setCellValue('A' . $r, 'Not Applicable:');
        $summarySheet->setCellValue('B' . $r++, $complianceReport['controls']['not_applicable']);
        $summarySheet->setCellValue('A' . $r, 'Active Frameworks:');
        $summarySheet->setCellValue('B' . $r++, $complianceReport['active_frameworks']);

        $spreadsheet->setActiveSheetIndex(0);
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="compliance_status_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== AUDIT EXCEL EXPORT =====================

    #[Route('/audit/excel', name: 'app_management_reports_audit_excel')]
    public function auditExcel(Request $request): Response
    {
        $auditReport = $this->reportService->getAuditManagementReport();

        $request->getSession()->save();

        // Summary sheet as main data
        $headers = ['Metric', 'Value'];
        $data = [
            ['Total Audits', $auditReport['audits']['total']],
            ['Audits This Year', $auditReport['audits']['this_year']],
            ['Planned', $auditReport['audits']['by_status']['planned']],
            ['In Progress', $auditReport['audits']['by_status']['in_progress']],
            ['Completed', $auditReport['audits']['by_status']['completed']],
            ['Cancelled', $auditReport['audits']['by_status']['cancelled']],
            ['Total Management Reviews', $auditReport['management_reviews']['total']],
            ['Management Reviews This Year', $auditReport['management_reviews']['this_year']],
        ];

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'Audit Summary');

        $spreadsheet->setActiveSheetIndex(0);
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="audit_report_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== GDPR EXCEL EXPORT =====================

    #[Route('/gdpr/excel', name: 'app_management_reports_gdpr_excel')]
    public function gdprExcel(Request $request): Response
    {
        $dataBreachReport = $this->reportService->getDataBreachReport();

        $request->getSession()->save();

        $headers = ['ID', 'Title', 'Severity', 'Status'];
        $data = [];

        foreach ($dataBreachReport['breaches'] as $breach) {
            $data[] = [
                $breach->getId(),
                $breach->getTitle(),
                $breach->getSeverity() ?? 'medium',
                $breach->getStatus(),
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'Data Breaches');

        // Summary sheet
        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('Summary');
        $summarySheet->setCellValue('A1', 'GDPR Data Breach Summary');
        $summarySheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $r = 3;
        $summarySheet->setCellValue('A' . $r, 'Total Breaches:');
        $summarySheet->setCellValue('B' . $r++, $dataBreachReport['total_breaches']);
        $summarySheet->setCellValue('A' . $r, 'This Year:');
        $summarySheet->setCellValue('B' . $r++, $dataBreachReport['this_year']);
        $summarySheet->setCellValue('A' . $r, 'Critical:');
        $summarySheet->setCellValue('B' . $r++, $dataBreachReport['by_severity']['critical']);
        $summarySheet->setCellValue('A' . $r, 'High:');
        $summarySheet->setCellValue('B' . $r++, $dataBreachReport['by_severity']['high']);
        $summarySheet->setCellValue('A' . $r, 'Medium:');
        $summarySheet->setCellValue('B' . $r++, $dataBreachReport['by_severity']['medium']);
        $summarySheet->setCellValue('A' . $r, 'Low:');
        $summarySheet->setCellValue('B' . $r++, $dataBreachReport['by_severity']['low']);
        $summarySheet->setCellValue('A' . $r, 'Notified:');
        $summarySheet->setCellValue('B' . $r++, $dataBreachReport['notification_status']['notified']);
        $summarySheet->setCellValue('A' . $r, 'Pending Notification:');
        $summarySheet->setCellValue('B' . $r++, $dataBreachReport['notification_status']['pending']);

        $spreadsheet->setActiveSheetIndex(0);
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="data_breach_report_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== MANAGEMENT REVIEW OUTPUT (ISO 27001 Clause 9.3) =====================

    /**
     * Generate Management Review Output PDF per ISO 27001:2022 Clause 9.3
     *
     * Aggregates inputs from all ISMS domains into a structured management review output:
     * - Status of actions from previous reviews
     * - Changes in internal/external issues
     * - Performance information (KPIs, nonconformities, audit results, objectives)
     * - Feedback from interested parties
     * - Risk assessment/treatment results
     * - Decisions: improvement opportunities, ISMS changes, resource needs
     */
    #[Route('/review-output/pdf', name: 'app_management_reports_review_output_pdf')]
    #[IsGranted('ROLE_MANAGER')]
    public function reviewOutputPdf(Request $request): Response
    {
        $locale = $request->getLocale();
        $reviewData = $this->reportService->getManagementReviewReport($locale);

        $request->getSession()->save();

        $generatedAt = new DateTime();

        $pdf = $this->pdfExportService->generatePdf('management_reports/review_output_pdf.html.twig', [
            'generated_at' => $generatedAt,
            'executive_data' => $reviewData['executive_data'],
            'risk_data' => $reviewData['risk_data'],
            'audit_data' => $reviewData['audit_data'],
            'compliance_data' => $reviewData['compliance_data'],
            'kpi_summary' => $reviewData['kpi_summary'],
            'treatment_data' => $reviewData['treatment_data'],
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="management_review_output_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    // ===================== QUARTERLY TREND COMPARISON =====================

    /**
     * Display quarterly trend comparison report (HTML version)
     *
     * Shows period-over-period comparison of key ISMS metrics using KPI snapshot data.
     * Compares current quarter against previous quarter with delta indicators.
     */
    #[Route('/quarterly-trend', name: 'app_management_reports_quarterly_trend')]
    #[IsGranted('ROLE_MANAGER')]
    public function quarterlyTrend(): Response
    {
        $metrics = $this->buildQuarterlyTrendMetrics();

        return $this->render('management_reports/quarterly_trend.html.twig', [
            'metrics' => $metrics['metrics'],
            'has_historical_data' => $metrics['has_historical_data'],
        ]);
    }

    /**
     * Generate quarterly trend comparison PDF report
     */
    #[Route('/quarterly-trend/pdf', name: 'app_management_reports_quarterly_trend_pdf')]
    #[IsGranted('ROLE_MANAGER')]
    public function quarterlyTrendPdf(Request $request): Response
    {
        $metrics = $this->buildQuarterlyTrendMetrics();

        $request->getSession()->save();

        $generatedAt = new DateTime();

        $pdf = $this->pdfExportService->generatePdf('management_reports/quarterly_trend_pdf.html.twig', [
            'metrics' => $metrics['metrics'],
            'has_historical_data' => $metrics['has_historical_data'],
            'generated_at' => $generatedAt,
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="quarterly_trend_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    // ===================== EVIDENCE PACKAGE (ZIP) =====================

    #[Route('/evidence-package', name: 'app_management_reports_evidence_package')]
    #[IsGranted('ROLE_MANAGER')]
    public function evidencePackage(Request $request): Response
    {
        $request->getSession()->save();

        $zipFilename = 'isms-evidence-package-' . date('Y-m-d') . '.zip';
        $tempFile = tempnam(sys_get_temp_dir(), 'evidence_');

        $zip = new \ZipArchive();
        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create ZIP archive');
        }

        $generatedAt = new DateTime();
        $version = $generatedAt->format('Y.m.d');

        // Generate each report as PDF and add to ZIP
        $reports = $this->generateEvidenceReports($generatedAt, $version);

        foreach ($reports as $filename => $pdfContent) {
            $zip->addFromString($filename, $pdfContent);
        }

        $zip->close();

        $response = new BinaryFileResponse($tempFile);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $zipFilename);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    // ===================== HELPER METHODS =====================

    /**
     * Parse date range from request query parameters
     *
     * @return array{0: ?\DateTime, 1: ?\DateTime} [from, to]
     */
    private function parseDateRange(Request $request): array
    {
        $from = null;
        $to = null;

        $fromStr = $request->query->get('from');
        $toStr = $request->query->get('to');

        if ($fromStr) {
            try {
                $from = new DateTime($fromStr);
                $from->setTime(0, 0, 0);
            } catch (\Exception) {
                $from = null;
            }
        }

        if ($toStr) {
            try {
                $to = new DateTime($toStr);
                $to->setTime(23, 59, 59);
            } catch (\Exception) {
                $to = null;
            }
        }

        return [$from, $to];
    }

    /**
     * Generate all evidence report PDFs for the ZIP package
     *
     * @return array<string, string> Map of filename => PDF content
     */
    private function generateEvidenceReports(DateTime $generatedAt, string $version): array
    {
        $reports = [];

        // Executive Summary PDF
        $summary = $this->reportService->getExecutiveSummary();
        $riskTrends = $this->reportService->getRiskTrendData(12);
        $incidentTrends = $this->reportService->getIncidentTrendData(12);
        $pdfOptions = ['classification' => 'CONFIDENTIAL'];

        $reports['01-executive-summary.pdf'] = $this->pdfExportService->generatePdf(
            'management_reports/executive_pdf.html.twig',
            [
                'summary' => $summary,
                'risk_trends' => $riskTrends,
                'incident_trends' => $incidentTrends,
                'generated_at' => $generatedAt,
                'version' => $version,
            ],
            $pdfOptions
        );

        // Risk Management PDF
        $riskReport = $this->reportService->getRiskManagementReport();
        $reports['02-risk-register.pdf'] = $this->pdfExportService->generatePdf(
            'management_reports/risk_pdf.html.twig',
            [
                'report' => $riskReport,
                'trends' => $riskTrends,
                'generated_at' => $generatedAt,
                'version' => $version,
            ],
            $pdfOptions
        );

        // Compliance Status PDF
        $complianceReport = $this->reportService->getComplianceStatusReport();
        $reports['03-compliance-status.pdf'] = $this->pdfExportService->generatePdf(
            'management_reports/compliance_pdf.html.twig',
            [
                'report' => $complianceReport,
                'generated_at' => $generatedAt,
                'version' => $version,
            ],
            $pdfOptions
        );

        // BCM Report PDF
        $bcmReport = $this->reportService->getBCMReport();
        $biaSummary = $this->reportService->getBIASummary();
        $reports['04-bcm-report.pdf'] = $this->pdfExportService->generatePdf(
            'management_reports/bcm_pdf.html.twig',
            [
                'report' => $bcmReport,
                'bia' => $biaSummary,
                'generated_at' => $generatedAt,
                'version' => $version,
            ],
            $pdfOptions
        );

        // Audit Report PDF
        $auditReport = $this->reportService->getAuditManagementReport();
        $reports['05-audit-report.pdf'] = $this->pdfExportService->generatePdf(
            'management_reports/audit_pdf.html.twig',
            [
                'report' => $auditReport,
                'generated_at' => $generatedAt,
                'version' => $version,
            ],
            $pdfOptions
        );

        // GDPR / Data Breach PDF
        $dataBreachReport = $this->reportService->getDataBreachReport();
        $reports['06-data-breach-report.pdf'] = $this->pdfExportService->generatePdf(
            'management_reports/gdpr_pdf.html.twig',
            [
                'report' => $dataBreachReport,
                'generated_at' => $generatedAt,
                'version' => $version,
            ],
            $pdfOptions
        );

        // Asset Inventory PDF
        $assetReport = $this->reportService->getAssetManagementReport();
        $reports['07-asset-inventory.pdf'] = $this->pdfExportService->generatePdf(
            'management_reports/assets_pdf.html.twig',
            [
                'report' => $assetReport,
                'generated_at' => $generatedAt,
                'version' => $version,
            ],
            $pdfOptions
        );

        return $reports;
    }

    /**
     * Build quarterly trend comparison metrics.
     *
     * Uses KpiSnapshotRepository to get historical data for the previous quarter.
     * Falls back to showing current data only when no snapshots are available.
     *
     * @return array{metrics: array, has_historical_data: bool}
     */
    private function buildQuarterlyTrendMetrics(): array
    {
        $tenant = $this->security->getUser()?->getTenant();
        $hasHistoricalData = false;
        $previousSnapshot = null;

        // Try to find a snapshot from ~3 months ago (previous quarter)
        if ($tenant !== null) {
            $threeMonthsAgo = new DateTimeImmutable('-3 months');
            $previousSnapshot = $this->kpiSnapshotRepository->findClosestBefore($tenant, $threeMonthsAgo);
            if ($previousSnapshot !== null) {
                $hasHistoricalData = true;
            }
        }

        $previousData = $previousSnapshot?->getKpiData() ?? [];

        // Get current data from existing services
        $executiveSummary = $this->reportService->getExecutiveSummary();
        $riskReport = $this->reportService->getRiskManagementReport();
        $complianceReport = $this->reportService->getComplianceStatusReport();

        // Treatment plan completion
        $treatmentTotal = $riskReport['treatment_plans']['total'];
        $treatmentActive = $riskReport['treatment_plans']['active'];
        $treatmentCompletion = $treatmentTotal > 0
            ? round((($treatmentTotal - $treatmentActive) / $treatmentTotal) * 100)
            : 100;

        // Build metrics array
        $metrics = [];

        // 1. Compliance %
        $currentCompliance = (int) round($complianceReport['overall_compliance']);
        $prevCompliance = isset($previousData['control_compliance']) ? (int) $previousData['control_compliance'] : null;
        $metrics[] = $this->buildMetricRow(
            'management_reports.quarterly_trend.compliance_pct',
            $currentCompliance,
            $prevCompliance,
            '%',
            true,
            100
        );

        // 2. Risk count
        $currentRisks = $riskReport['total_risks'];
        $prevRisks = $previousData['total_risks'] ?? null;
        $metrics[] = $this->buildMetricRow(
            'management_reports.quarterly_trend.risk_count',
            $currentRisks,
            $prevRisks !== null ? (int) $prevRisks : null,
            '',
            false,
            max($currentRisks, ($prevRisks ?? 0), 1)
        );

        // 3. High risks
        $currentHighRisks = $riskReport['level_counts']['critical'] + $riskReport['level_counts']['high'];
        $prevHighRisks = isset($previousData['high_risks']) ? (int) $previousData['high_risks'] : null;
        if ($prevHighRisks === null && isset($previousData['critical_risks'])) {
            $prevHighRisks = (int) ($previousData['high_risks'] ?? 0) + (int) $previousData['critical_risks'];
        }
        $metrics[] = $this->buildMetricRow(
            'management_reports.quarterly_trend.high_risks',
            $currentHighRisks,
            $prevHighRisks,
            '',
            false,
            max($currentHighRisks, ($prevHighRisks ?? 0), 1)
        );

        // 4. Open incidents
        $currentOpenIncidents = $executiveSummary['incident_summary']['open'];
        $prevOpenIncidents = isset($previousData['open_incidents']) ? (int) $previousData['open_incidents'] : null;
        $metrics[] = $this->buildMetricRow(
            'management_reports.quarterly_trend.open_incidents',
            $currentOpenIncidents,
            $prevOpenIncidents,
            '',
            false,
            max($currentOpenIncidents, ($prevOpenIncidents ?? 0), 1)
        );

        // 5. Treatment plan completion
        $prevTreatment = isset($previousData['risk_treatment_rate']) ? (int) $previousData['risk_treatment_rate'] : null;
        $metrics[] = $this->buildMetricRow(
            'management_reports.quarterly_trend.treatment_completion',
            $treatmentCompletion,
            $prevTreatment,
            '%',
            true,
            100
        );

        // 6. Training completion
        $currentTraining = isset($previousData['training_completion']) ? (int) $previousData['training_completion'] : 0;
        $kpis = $this->dashboardStatisticsService->getManagementKPIs();
        if (isset($kpis['training']['training_completion_rate'])) {
            $currentTraining = (int) $kpis['training']['training_completion_rate']['value'];
        }
        $prevTraining = isset($previousData['training_completion']) ? (int) $previousData['training_completion'] : null;
        $metrics[] = $this->buildMetricRow(
            'management_reports.quarterly_trend.training_completion',
            $currentTraining,
            $prevTraining,
            '%',
            true,
            100
        );

        return [
            'metrics' => $metrics,
            'has_historical_data' => $hasHistoricalData,
        ];
    }

    /**
     * Build a single metric row for the quarterly trend comparison.
     *
     * @param string $label Translation key for the metric label
     * @param int|float $current Current quarter value
     * @param int|float|null $previous Previous quarter value (null if no data)
     * @param string $unit Unit suffix (e.g., '%', '')
     * @param bool $higherIsBetter Whether a higher value is an improvement
     * @param int|float $maxValue Maximum value for progress bar scaling
     * @return array Metric row data
     */
    private function buildMetricRow(
        string $label,
        int|float $current,
        int|float|null $previous,
        string $unit,
        bool $higherIsBetter,
        int|float $maxValue
    ): array {
        $delta = $previous !== null ? $current - $previous : null;
        $trend = 'stable';

        if ($delta !== null && $delta != 0) {
            if ($higherIsBetter) {
                $trend = $delta > 0 ? 'better' : 'worse';
            } else {
                $trend = $delta < 0 ? 'better' : 'worse';
            }
        }

        return [
            'label' => $label,
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'unit' => $unit,
            'trend' => $trend,
            'higher_is_better' => $higherIsBetter,
            'max_value' => $maxValue,
        ];
    }
}
