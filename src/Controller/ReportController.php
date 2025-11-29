<?php

namespace App\Controller;

use DateTime;
use App\Entity\Risk;
use App\Entity\Control;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\TrainingRepository;
use App\Repository\ControlRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Service\PdfExportService;
use App\Service\ExcelExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ReportController extends AbstractController
{
    public function __construct(
        private readonly PdfExportService $pdfExportService,
        private readonly ExcelExportService $excelExportService,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly InternalAuditRepository $internalAuditRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly ControlRepository $controlRepository,
        private readonly ManagementReviewRepository $managementReviewRepository,
        private readonly ISMSObjectiveRepository $ismsObjectiveRepository
    ) {}

    #[Route('/reports/', name: 'app_reports_index')]
    public function index(): Response
    {
        return $this->render('reports/index.html.twig');
    }

    // ===================== DASHBOARD REPORTS =====================

    #[Route('/reports/dashboard/pdf', name: 'app_reports_dashboard_pdf')]
    public function dashboardPdf(Request $request): Response
    {
        $data = $this->getDashboardData();

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from current date (Format: Year.Month.Day)
        $generatedAt = new DateTime();
        $version = $generatedAt->format('Y.m.d');

        $pdf = $this->pdfExportService->generatePdf('reports/dashboard_pdf.html.twig', [
            'data' => $data,
            'generated_at' => $generatedAt,
            'version' => $version,
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="isms_dashboard_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/reports/dashboard/excel', name: 'app_reports_dashboard_excel')]
    public function dashboardExcel(Request $request): Response
    {
        $data = $this->getDashboardData();

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        $spreadsheet = $this->excelExportService->createSpreadsheet('ISMS Dashboard');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Dashboard');

        // Summary section
        $sheet->setCellValue('A1', 'ISMS Dashboard Summary');
        $sheet->mergeCells('A1:B1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

        $row = 3;
        $sheet->setCellValue('A' . $row, 'Assets:');
        $sheet->setCellValue('B' . $row++, $data['assets_count']);
        $sheet->setCellValue('A' . $row, 'Risks:');
        $sheet->setCellValue('B' . $row++, $data['risks_count']);
        $sheet->setCellValue('A' . $row, 'High/Critical Risks:');
        $sheet->setCellValue('B' . $row++, $data['high_risks']);
        $sheet->setCellValue('A' . $row, 'Controls:');
        $sheet->setCellValue('B' . $row++, $data['controls_count']);
        $sheet->setCellValue('A' . $row, 'Implemented Controls:');
        $sheet->setCellValue('B' . $row++, $data['implemented_controls']);
        $sheet->setCellValue('A' . $row, 'Open Incidents:');
        $sheet->setCellValue('B' . $row++, $data['open_incidents']);

        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);

        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="isms_dashboard_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== RISK REPORTS =====================

    #[Route('/reports/risks/pdf', name: 'app_reports_risks_pdf')]
    public function risksPdf(Request $request): Response
    {
        $risks = $this->riskRepository->findAll();

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from latest risk update date (Format: Year.Month.Day)
        $latestUpdate = null;
        foreach ($risks as $risk) {
            $updateDate = $risk->getUpdatedAt() ?? $risk->getCreatedAt();
            if ($latestUpdate === null || ($updateDate !== null && $updateDate > $latestUpdate)) {
                $latestUpdate = $updateDate;
            }
        }
        $latestUpdate ??= new DateTime();
        $version = $latestUpdate->format('Y.m.d');

        $pdf = $this->pdfExportService->generatePdf('reports/risks_pdf.html.twig', [
            'risks' => $risks,
            'generated_at' => new DateTime(),
            'version' => $version,
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="risk_register_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/reports/risks/excel', name: 'app_reports_risks_excel')]
    public function risksExcel(Request $request): Response
    {
        $risks = $this->riskRepository->findAll();

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        $headers = ['ID', 'Title', 'Category', 'Likelihood', 'Impact', 'Risk Score', 'Treatment', 'Status', 'Owner'];
        $data = [];

        foreach ($risks as $risk) {
            $data[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getCategory(),
                $risk->getProbability(),
                $risk->getImpact(),
                $risk->getRiskScore(),
                $risk->getTreatmentStrategy() ? substr((string) $risk->getTreatmentStrategy(), 0, 50) : '-',
                $risk->getStatus(),
                $risk->getRiskOwner() ? $risk->getRiskOwner()->getEmail() : '-',
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'Risk Register');
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="risk_register_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== CONTROL REPORTS (SoA) =====================

    #[Route('/reports/controls/pdf', name: 'app_reports_controls_pdf')]
    public function controlsPdf(Request $request): Response
    {
        $controls = $this->controlRepository->findAll();

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from latest control update date (Format: Year.Month.Day)
        $latestUpdate = null;
        foreach ($controls as $control) {
            $updateDate = $control->getUpdatedAt() ?? $control->getCreatedAt();
            if ($latestUpdate === null || ($updateDate !== null && $updateDate > $latestUpdate)) {
                $latestUpdate = $updateDate;
            }
        }
        $latestUpdate ??= new DateTime();
        $version = $latestUpdate->format('Y.m.d');

        $pdf = $this->pdfExportService->generatePdf('reports/controls_pdf.html.twig', [
            'controls' => $controls,
            'generated_at' => new DateTime(),
            'version' => $version,
        ], ['orientation' => 'landscape']);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="statement_of_applicability_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/reports/controls/excel', name: 'app_reports_controls_excel')]
    public function controlsExcel(Request $request): Response
    {
        $controls = $this->controlRepository->findAll();

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        $headers = ['Control ID', 'Name', 'Category', 'Applicability', 'Status', 'Progress %', 'Responsible', 'Target Date'];
        $data = [];

        foreach ($controls as $control) {
            $data[] = [
                $control->getControlId(),
                $control->getName(),
                $control->getCategory(),
                $control->isApplicable(),
                $control->getImplementationStatus(),
                $control->getImplementationPercentage() . '%',
                $control->getResponsiblePerson() ? $control->getResponsiblePerson()->getEmail() : '-',
                $control->getTargetDate() ? $control->getTargetDate()->format('Y-m-d') : '-',
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'Statement of Applicability');
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="statement_of_applicability_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== INCIDENT REPORTS =====================

    #[Route('/reports/incidents/pdf', name: 'app_reports_incidents_pdf')]
    public function incidentsPdf(Request $request): Response
    {
        $incidents = $this->incidentRepository->findAll();

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from latest incident update date (Format: Year.Month.Day)
        $latestUpdate = null;
        foreach ($incidents as $incident) {
            $updateDate = $incident->getUpdatedAt() ?? $incident->getCreatedAt();
            if ($latestUpdate === null || ($updateDate !== null && $updateDate > $latestUpdate)) {
                $latestUpdate = $updateDate;
            }
        }
        $latestUpdate ??= new DateTime();
        $version = $latestUpdate->format('Y.m.d');

        $pdf = $this->pdfExportService->generatePdf('reports/incidents_pdf.html.twig', [
            'incidents' => $incidents,
            'generated_at' => new DateTime(),
            'version' => $version,
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="incident_log_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/reports/incidents/excel', name: 'app_reports_incidents_excel')]
    public function incidentsExcel(Request $request): Response
    {
        $incidents = $this->incidentRepository->findAll();

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        $headers = ['ID', 'Title', 'Severity', 'Category', 'Status', 'Detected Date', 'Resolved Date', 'Reporter'];
        $data = [];

        foreach ($incidents as $incident) {
            $data[] = [
                $incident->getId(),
                $incident->getTitle(),
                $incident->getSeverity(),
                $incident->getCategory(),
                $incident->getStatus(),
                $incident->getDetectedAt()->format('Y-m-d'),
                $incident->getResolvedAt() ? $incident->getResolvedAt()->format('Y-m-d') : 'Open',
                $incident->getReportedBy() ? $incident->getReportedBy()->getEmail() : '-',
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'Incident Log');
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="incident_log_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== TRAINING REPORTS =====================

    #[Route('/reports/trainings/pdf', name: 'app_reports_trainings_pdf')]
    public function trainingsPdf(Request $request): Response
    {
        $trainings = $this->trainingRepository->findAll();

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from latest training update date (Format: Year.Month.Day)
        $latestUpdate = null;
        foreach ($trainings as $training) {
            $updateDate = $training->getUpdatedAt() ?? $training->getCreatedAt();
            if ($latestUpdate === null || ($updateDate !== null && $updateDate > $latestUpdate)) {
                $latestUpdate = $updateDate;
            }
        }
        $latestUpdate ??= new DateTime();
        $version = $latestUpdate->format('Y.m.d');

        $pdf = $this->pdfExportService->generatePdf('reports/trainings_pdf.html.twig', [
            'trainings' => $trainings,
            'generated_at' => new DateTime(),
            'version' => $version,
        ]);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="training_log_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/reports/trainings/excel', name: 'app_reports_trainings_excel')]
    public function trainingsExcel(Request $request): Response
    {
        $trainings = $this->trainingRepository->findAll();

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        $headers = ['ID', 'Title', 'Type', 'Date', 'Duration (min)', 'Participants', 'Mandatory', 'Status'];
        $data = [];

        foreach ($trainings as $training) {
            $data[] = [
                $training->getId(),
                $training->getTitle(),
                $training->getTrainingType(),
                $training->getScheduledDate()->format('Y-m-d H:i'),
                $training->getDurationMinutes(),
                count($training->getParticipants()),
                $training->isMandatory() ? 'Yes' : 'No',
                $training->getStatus(),
            ];
        }

        $spreadsheet = $this->excelExportService->exportArray($data, $headers, 'Training Log');
        $excel = $this->excelExportService->generateExcel($spreadsheet);

        return new Response($excel, Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="training_log_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== HELPER METHODS =====================

    private function getDashboardData(): array
    {
        $risks = $this->riskRepository->findAll();
        $highRisks = array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() >= 12);

        $controls = $this->controlRepository->findAll();
        $implementedControls = array_filter($controls, fn(Control $control): bool => $control->getImplementationStatus() === 'implemented');

        return [
            'assets_count' => $this->assetRepository->count([]),
            'risks_count' => count($risks),
            'high_risks' => count($highRisks),
            'controls_count' => count($controls),
            'implemented_controls' => count($implementedControls),
            'compliance_percentage' => count($controls) > 0 ? round((count($implementedControls) / count($controls)) * 100) : 0,
            'open_incidents' => $this->incidentRepository->count(['status' => ['new', 'investigating', 'in_progress']]),
            'total_trainings' => $this->trainingRepository->count([]),
            'audits_this_year' => count($this->internalAuditRepository->findBy(['status' => 'completed'])),
        ];
    }
}
