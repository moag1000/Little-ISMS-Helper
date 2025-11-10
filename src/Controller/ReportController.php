<?php

namespace App\Controller;

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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reports')]
#[IsGranted('ROLE_USER')]
class ReportController extends AbstractController
{
    public function __construct(
        private PdfExportService $pdfService,
        private ExcelExportService $excelService,
        private AssetRepository $assetRepository,
        private RiskRepository $riskRepository,
        private IncidentRepository $incidentRepository,
        private InternalAuditRepository $auditRepository,
        private TrainingRepository $trainingRepository,
        private ControlRepository $controlRepository,
        private ManagementReviewRepository $managementReviewRepository,
        private ISMSObjectiveRepository $objectiveRepository
    ) {}

    #[Route('/', name: 'app_reports_index')]
    public function index(): Response
    {
        return $this->render('reports/index.html.twig');
    }

    // ===================== DASHBOARD REPORTS =====================

    #[Route('/dashboard/pdf', name: 'app_reports_dashboard_pdf')]
    public function dashboardPdf(): Response
    {
        $data = $this->getDashboardData();

        $pdf = $this->pdfService->generatePdf('reports/dashboard_pdf.html.twig', [
            'data' => $data,
            'generated_at' => new \DateTime(),
        ]);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="isms_dashboard_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/dashboard/excel', name: 'app_reports_dashboard_excel')]
    public function dashboardExcel(): Response
    {
        $data = $this->getDashboardData();

        $spreadsheet = $this->excelService->createSpreadsheet('ISMS Dashboard');
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

        $excel = $this->excelService->generateExcel($spreadsheet);

        return new Response($excel, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="isms_dashboard_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== RISK REPORTS =====================

    #[Route('/risks/pdf', name: 'app_reports_risks_pdf')]
    public function risksPdf(): Response
    {
        $risks = $this->riskRepository->findAll();

        $pdf = $this->pdfService->generatePdf('reports/risks_pdf.html.twig', [
            'risks' => $risks,
            'generated_at' => new \DateTime(),
        ]);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="risk_register_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/risks/excel', name: 'app_reports_risks_excel')]
    public function risksExcel(): Response
    {
        $risks = $this->riskRepository->findAll();

        $headers = ['ID', 'Title', 'Category', 'Likelihood', 'Impact', 'Risk Score', 'Treatment', 'Status', 'Owner'];
        $data = [];

        foreach ($risks as $risk) {
            $data[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getCategory(),
                $risk->getLikelihood(),
                $risk->getImpact(),
                $risk->getRiskScore(),
                $risk->getTreatmentPlan() ? substr($risk->getTreatmentPlan(), 0, 50) : '-',
                $risk->getStatus(),
                $risk->getOwner() ? $risk->getOwner()->getEmail() : '-',
            ];
        }

        $spreadsheet = $this->excelService->exportArray($data, $headers, 'Risk Register');
        $excel = $this->excelService->generateExcel($spreadsheet);

        return new Response($excel, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="risk_register_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== CONTROL REPORTS (SoA) =====================

    #[Route('/controls/pdf', name: 'app_reports_controls_pdf')]
    public function controlsPdf(): Response
    {
        $controls = $this->controlRepository->findAll();

        $pdf = $this->pdfService->generatePdf('reports/controls_pdf.html.twig', [
            'controls' => $controls,
            'generated_at' => new \DateTime(),
        ], ['orientation' => 'landscape']);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="statement_of_applicability_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/controls/excel', name: 'app_reports_controls_excel')]
    public function controlsExcel(): Response
    {
        $controls = $this->controlRepository->findAll();

        $headers = ['Control ID', 'Name', 'Category', 'Applicability', 'Status', 'Progress %', 'Responsible', 'Target Date'];
        $data = [];

        foreach ($controls as $control) {
            $data[] = [
                $control->getControlId(),
                $control->getName(),
                $control->getCategory(),
                $control->getApplicability(),
                $control->getImplementationStatus(),
                $control->getImplementationProgress() . '%',
                $control->getResponsiblePerson() ? $control->getResponsiblePerson()->getEmail() : '-',
                $control->getTargetDate() ? $control->getTargetDate()->format('Y-m-d') : '-',
            ];
        }

        $spreadsheet = $this->excelService->exportArray($data, $headers, 'Statement of Applicability');
        $excel = $this->excelService->generateExcel($spreadsheet);

        return new Response($excel, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="statement_of_applicability_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== INCIDENT REPORTS =====================

    #[Route('/incidents/pdf', name: 'app_reports_incidents_pdf')]
    public function incidentsPdf(): Response
    {
        $incidents = $this->incidentRepository->findAll();

        $pdf = $this->pdfService->generatePdf('reports/incidents_pdf.html.twig', [
            'incidents' => $incidents,
            'generated_at' => new \DateTime(),
        ]);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="incident_log_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/incidents/excel', name: 'app_reports_incidents_excel')]
    public function incidentsExcel(): Response
    {
        $incidents = $this->incidentRepository->findAll();

        $headers = ['ID', 'Title', 'Severity', 'Category', 'Status', 'Detected Date', 'Resolved Date', 'Reporter'];
        $data = [];

        foreach ($incidents as $incident) {
            $data[] = [
                $incident->getId(),
                $incident->getTitle(),
                $incident->getSeverity(),
                $incident->getCategory(),
                $incident->getStatus(),
                $incident->getDetectedDate()->format('Y-m-d'),
                $incident->getResolvedDate() ? $incident->getResolvedDate()->format('Y-m-d') : 'Open',
                $incident->getReportedBy() ? $incident->getReportedBy()->getEmail() : '-',
            ];
        }

        $spreadsheet = $this->excelService->exportArray($data, $headers, 'Incident Log');
        $excel = $this->excelService->generateExcel($spreadsheet);

        return new Response($excel, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="incident_log_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== TRAINING REPORTS =====================

    #[Route('/trainings/pdf', name: 'app_reports_trainings_pdf')]
    public function trainingsPdf(): Response
    {
        $trainings = $this->trainingRepository->findAll();

        $pdf = $this->pdfService->generatePdf('reports/trainings_pdf.html.twig', [
            'trainings' => $trainings,
            'generated_at' => new \DateTime(),
        ]);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="training_log_' . date('Y-m-d') . '.pdf"',
        ]);
    }

    #[Route('/trainings/excel', name: 'app_reports_trainings_excel')]
    public function trainingsExcel(): Response
    {
        $trainings = $this->trainingRepository->findAll();

        $headers = ['ID', 'Title', 'Type', 'Date', 'Duration (min)', 'Participants', 'Mandatory', 'Status'];
        $data = [];

        foreach ($trainings as $training) {
            $data[] = [
                $training->getId(),
                $training->getTitle(),
                $training->getTrainingType(),
                $training->getScheduledDate()->format('Y-m-d H:i'),
                $training->getDuration(),
                count($training->getParticipants()),
                $training->isMandatory() ? 'Yes' : 'No',
                $training->getStatus(),
            ];
        }

        $spreadsheet = $this->excelService->exportArray($data, $headers, 'Training Log');
        $excel = $this->excelService->generateExcel($spreadsheet);

        return new Response($excel, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="training_log_' . date('Y-m-d') . '.xlsx"',
        ]);
    }

    // ===================== HELPER METHODS =====================

    private function getDashboardData(): array
    {
        $risks = $this->riskRepository->findAll();
        $highRisks = array_filter($risks, function($risk) {
            return $risk->getRiskScore() >= 12;
        });

        $controls = $this->controlRepository->findAll();
        $implementedControls = array_filter($controls, function($control) {
            return $control->getImplementationStatus() === 'implemented';
        });

        return [
            'assets_count' => $this->assetRepository->count([]),
            'risks_count' => count($risks),
            'high_risks' => count($highRisks),
            'controls_count' => count($controls),
            'implemented_controls' => count($implementedControls),
            'compliance_percentage' => count($controls) > 0 ? round((count($implementedControls) / count($controls)) * 100) : 0,
            'open_incidents' => $this->incidentRepository->count(['status' => ['new', 'investigating', 'in_progress']]),
            'total_trainings' => $this->trainingRepository->count([]),
            'audits_this_year' => count($this->auditRepository->findBy(['status' => 'completed'])),
        ];
    }
}
