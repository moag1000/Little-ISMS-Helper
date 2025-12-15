<?php

namespace App\Controller;

use DateTime;
use App\Service\ManagementReportService;
use App\Service\PdfExportService;
use App\Service\ExcelExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function executive(): Response
    {
        $summary = $this->reportService->getExecutiveSummary();
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
    public function riskManagement(): Response
    {
        $riskReport = $this->reportService->getRiskManagementReport();
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
    public function compliance(): Response
    {
        $complianceReport = $this->reportService->getComplianceStatusReport();

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
}
