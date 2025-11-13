<?php

namespace App\Controller;

use App\Entity\Risk;
use App\Form\RiskType;
use App\Repository\AuditLogRepository;
use App\Repository\RiskRepository;
use App\Service\RiskMatrixService;
use App\Service\ExcelExportService;
use App\Service\PdfExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/risk')]
class RiskController extends AbstractController
{
    public function __construct(
        private RiskRepository $riskRepository,
        private AuditLogRepository $auditLogRepository,
        private EntityManagerInterface $entityManager,
        private RiskMatrixService $riskMatrixService,
        private TranslatorInterface $translator,
        private ExcelExportService $excelExportService,
        private PdfExportService $pdfExportService
    ) {}

    #[Route('/', name: 'app_risk_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get filter parameters
        $level = $request->query->get('level'); // critical, high, medium, low
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');

        // Get all risks
        $risks = $this->riskRepository->findAll();

        // Apply filters
        if ($level) {
            $risks = array_filter($risks, function($risk) use ($level) {
                $score = $risk->getRiskScore();
                return match($level) {
                    'critical' => $score >= 15,
                    'high' => $score >= 8 && $score < 15,
                    'medium' => $score >= 4 && $score < 8,
                    'low' => $score < 4,
                    default => true
                };
            });
        }

        if ($status) {
            $risks = array_filter($risks, fn($risk) => $risk->getStatus() === $status);
        }

        if ($treatment) {
            $risks = array_filter($risks, fn($risk) => $risk->getTreatmentStrategy() === $treatment);
        }

        if ($owner) {
            $risks = array_filter($risks, fn($risk) =>
                $risk->getRiskOwner() && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false
            );
        }

        // Re-index array after filtering
        $risks = array_values($risks);

        $highRisks = $this->riskRepository->findHighRisks();
        $treatmentStats = $this->riskRepository->countByTreatmentStrategy();

        return $this->render('risk/index_modern.html.twig', [
            'risks' => $risks,
            'highRisks' => $highRisks,
            'treatmentStats' => $treatmentStats,
        ]);
    }

    #[Route('/export', name: 'app_risk_export')]
    #[IsGranted('ROLE_USER')]
    public function export(Request $request): Response
    {
        // Get filter parameters (same as index)
        $level = $request->query->get('level');
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');

        // Get all risks
        $risks = $this->riskRepository->findAll();

        // Apply filters (same logic as index)
        if ($level) {
            $risks = array_filter($risks, function($risk) use ($level) {
                $score = $risk->getRiskScore();
                return match($level) {
                    'critical' => $score >= 15,
                    'high' => $score >= 8 && $score < 15,
                    'medium' => $score >= 4 && $score < 8,
                    'low' => $score < 4,
                    default => true
                };
            });
        }

        if ($status) {
            $risks = array_filter($risks, fn($risk) => $risk->getStatus() === $status);
        }

        if ($treatment) {
            $risks = array_filter($risks, fn($risk) => $risk->getTreatmentStrategy() === $treatment);
        }

        if ($owner) {
            $risks = array_filter($risks, fn($risk) =>
                $risk->getRiskOwner() && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false
            );
        }

        // Re-index array after filtering
        $risks = array_values($risks);

        // Close session to prevent blocking other requests during CSV generation
        $request->getSession()->save();

        // Create CSV content
        $csv = [];

        // CSV Header
        $csv[] = [
            'ID',
            'Titel',
            'Beschreibung',
            'Bedrohung',
            'Schwachstelle',
            'Asset',
            'Wahrscheinlichkeit',
            'Auswirkung',
            'Risiko-Score',
            'Risikolevel',
            'Rest-Wahrscheinlichkeit',
            'Rest-Auswirkung',
            'Rest-Risiko-Score',
            'Rest-Risikolevel',
            'Behandlungsstrategie',
            'Status',
            'Risikoinhaber',
            'Erstellt am',
            'Überprüfungsdatum',
        ];

        // CSV Data
        foreach ($risks as $risk) {
            $riskScore = $risk->getRiskScore();
            $residualScore = $risk->getResidualRiskScore();

            // Determine risk levels
            $riskLevel = match(true) {
                $riskScore >= 15 => 'Kritisch',
                $riskScore >= 8 => 'Hoch',
                $riskScore >= 4 => 'Mittel',
                default => 'Niedrig'
            };

            $residualRiskLevel = match(true) {
                $residualScore >= 15 => 'Kritisch',
                $residualScore >= 8 => 'Hoch',
                $residualScore >= 4 => 'Mittel',
                default => 'Niedrig'
            };

            // Translate treatment strategy
            $treatmentMap = [
                'accept' => 'Akzeptieren',
                'mitigate' => 'Mindern',
                'transfer' => 'Übertragen',
                'avoid' => 'Vermeiden',
            ];

            // Translate status
            $statusMap = [
                'identified' => 'Identifiziert',
                'assessed' => 'Bewertet',
                'treated' => 'Behandelt',
                'monitored' => 'Überwacht',
                'closed' => 'Geschlossen',
                'accepted' => 'Akzeptiert',
            ];

            $csv[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getDescription(),
                $risk->getThreat() ?? '-',
                $risk->getVulnerability() ?? '-',
                $risk->getAsset() ? $risk->getAsset()->getName() : '-',
                $risk->getProbability(),
                $risk->getImpact(),
                $riskScore,
                $riskLevel,
                $risk->getResidualProbability(),
                $risk->getResidualImpact(),
                $residualScore,
                $residualRiskLevel,
                $treatmentMap[$risk->getTreatmentStrategy()] ?? $risk->getTreatmentStrategy(),
                $statusMap[$risk->getStatus()] ?? $risk->getStatus(),
                $risk->getRiskOwner() ? $risk->getRiskOwner()->getFullName() : '-',
                $risk->getCreatedAt() ? $risk->getCreatedAt()->format('Y-m-d H:i') : '-',
                $risk->getReviewDate() ? $risk->getReviewDate()->format('Y-m-d') : '-',
            ];
        }

        // Generate CSV file
        $filename = sprintf(
            'risk_export_%s.csv',
            date('Y-m-d_His')
        );

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        // Add BOM for Excel UTF-8 support
        $csvContent = "\xEF\xBB\xBF";

        // Create CSV content
        $handle = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($handle, $row, ';'); // Use semicolon as delimiter for Excel compatibility
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/export/excel', name: 'app_risk_export_excel')]
    #[IsGranted('ROLE_USER')]
    public function exportExcel(Request $request): Response
    {
        // Get filter parameters (same as index)
        $level = $request->query->get('level');
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');

        // Get all risks
        $risks = $this->riskRepository->findAll();

        // Apply filters (same logic as index)
        if ($level) {
            $risks = array_filter($risks, function($risk) use ($level) {
                $score = $risk->getRiskScore();
                return match($level) {
                    'critical' => $score >= 15,
                    'high' => $score >= 8 && $score < 15,
                    'medium' => $score >= 4 && $score < 8,
                    'low' => $score < 4,
                    default => true
                };
            });
        }

        if ($status) {
            $risks = array_filter($risks, fn($risk) => $risk->getStatus() === $status);
        }

        if ($treatment) {
            $risks = array_filter($risks, fn($risk) => $risk->getTreatmentStrategy() === $treatment);
        }

        if ($owner) {
            $risks = array_filter($risks, fn($risk) =>
                $risk->getRiskOwner() && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false
            );
        }

        // Re-index array after filtering
        $risks = array_values($risks);

        // Calculate statistics
        $totalRisks = count($risks);
        $criticalRisks = count(array_filter($risks, fn($risk) => $risk->getRiskScore() >= 15));
        $highRisks = count(array_filter($risks, fn($risk) => $risk->getRiskScore() >= 8 && $risk->getRiskScore() < 15));
        $mediumRisks = count(array_filter($risks, fn($risk) => $risk->getRiskScore() >= 4 && $risk->getRiskScore() < 8));
        $lowRisks = count(array_filter($risks, fn($risk) => $risk->getRiskScore() < 4));

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        // Create spreadsheet
        $spreadsheet = $this->excelExportService->createSpreadsheet('Risk Management Report');

        // === TAB 1: Summary ===
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Zusammenfassung');

        $metrics = [
            'Gesamt Risiken' => $totalRisks,
            'Kritische Risiken' => $criticalRisks,
            'Hohe Risiken' => $highRisks,
            'Mittlere Risiken' => $mediumRisks,
            'Niedrige Risiken' => $lowRisks,
            'Export-Datum' => date('d.m.Y H:i'),
        ];

        $nextRow = $this->excelExportService->addSummarySection($summarySheet, $metrics, 1, 'Risk Management Übersicht');

        // Add status breakdown
        $statusMetrics = [
            'Identifiziert' => count(array_filter($risks, fn($r) => $r->getStatus() === 'identified')),
            'Bewertet' => count(array_filter($risks, fn($r) => $r->getStatus() === 'assessed')),
            'Behandelt' => count(array_filter($risks, fn($r) => $r->getStatus() === 'treated')),
            'Überwacht' => count(array_filter($risks, fn($r) => $r->getStatus() === 'monitored')),
            'Geschlossen' => count(array_filter($risks, fn($r) => $r->getStatus() === 'closed')),
            'Akzeptiert' => count(array_filter($risks, fn($r) => $r->getStatus() === 'accepted')),
        ];

        $this->excelExportService->addSummarySection($summarySheet, $statusMetrics, $nextRow, 'Status-Verteilung');
        $this->excelExportService->autoSizeColumns($summarySheet);

        // === TAB 2: All Risks ===
        $allRisksSheet = $this->excelExportService->createSheet($spreadsheet, 'Alle Risiken');

        $headers = [
            'ID', 'Titel', 'Asset', 'Wkt.', 'Ausw.', 'Score', 'Level',
            'Rest-Wkt.', 'Rest-Ausw.', 'Rest-Score', 'Rest-Level',
            'Strategie', 'Status', 'Owner', 'Erstellt'
        ];

        $this->excelExportService->addFormattedHeaderRow($allRisksSheet, $headers, 1, true);

        $data = [];
        foreach ($risks as $risk) {
            $riskScore = $risk->getRiskScore();
            $residualScore = $risk->getResidualRiskScore();

            $riskLevel = match(true) {
                $riskScore >= 15 => 'Kritisch',
                $riskScore >= 8 => 'Hoch',
                $riskScore >= 4 => 'Mittel',
                default => 'Niedrig'
            };

            $residualLevel = match(true) {
                $residualScore >= 15 => 'Kritisch',
                $residualScore >= 8 => 'Hoch',
                $residualScore >= 4 => 'Mittel',
                default => 'Niedrig'
            };

            $data[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getAsset() ? $risk->getAsset()->getName() : '-',
                $risk->getProbability(),
                $risk->getImpact(),
                $riskScore,
                $riskLevel,
                $risk->getResidualProbability(),
                $risk->getResidualImpact(),
                $residualScore,
                $residualLevel,
                match($risk->getTreatmentStrategy()) {
                    'accept' => 'Akzeptieren',
                    'mitigate' => 'Mindern',
                    'transfer' => 'Übertragen',
                    'avoid' => 'Vermeiden',
                    default => $risk->getTreatmentStrategy()
                },
                match($risk->getStatus()) {
                    'identified' => 'Identifiziert',
                    'assessed' => 'Bewertet',
                    'treated' => 'Behandelt',
                    'monitored' => 'Überwacht',
                    'closed' => 'Geschlossen',
                    'accepted' => 'Akzeptiert',
                    default => $risk->getStatus()
                },
                $risk->getRiskOwner() ? $risk->getRiskOwner()->getFullName() : '-',
                $risk->getCreatedAt() ? $risk->getCreatedAt()->format('d.m.Y') : '-',
            ];
        }

        // Conditional formatting for risk level column (index 6) and residual level (index 10)
        $conditionalFormatting = [
            6 => [ // Risk Level
                'Kritisch' => $this->excelExportService->getColor('critical'),
                'Hoch' => $this->excelExportService->getColor('high'),
                'Mittel' => $this->excelExportService->getColor('medium'),
                'Niedrig' => $this->excelExportService->getColor('low'),
            ],
            10 => [ // Residual Level
                'Kritisch' => $this->excelExportService->getColor('critical'),
                'Hoch' => $this->excelExportService->getColor('high'),
                'Mittel' => $this->excelExportService->getColor('medium'),
                'Niedrig' => $this->excelExportService->getColor('low'),
            ],
        ];

        $this->excelExportService->addFormattedDataRows($allRisksSheet, $data, 2, $conditionalFormatting);
        $this->excelExportService->autoSizeColumns($allRisksSheet);

        // === TAB 3: Critical & High Risks ===
        $criticalHighRisks = array_filter($risks, fn($r) => $r->getRiskScore() >= 8);

        if (!empty($criticalHighRisks)) {
            $criticalSheet = $this->excelExportService->createSheet($spreadsheet, 'Kritische & Hohe Risiken');

            $this->excelExportService->addFormattedHeaderRow($criticalSheet, $headers, 1, true);

            $criticalData = [];
            foreach ($criticalHighRisks as $risk) {
                $riskScore = $risk->getRiskScore();
                $residualScore = $risk->getResidualRiskScore();

                $riskLevel = $riskScore >= 15 ? 'Kritisch' : 'Hoch';
                $residualLevel = match(true) {
                    $residualScore >= 15 => 'Kritisch',
                    $residualScore >= 8 => 'Hoch',
                    $residualScore >= 4 => 'Mittel',
                    default => 'Niedrig'
                };

                $criticalData[] = [
                    $risk->getId(),
                    $risk->getTitle(),
                    $risk->getAsset() ? $risk->getAsset()->getName() : '-',
                    $risk->getProbability(),
                    $risk->getImpact(),
                    $riskScore,
                    $riskLevel,
                    $risk->getResidualProbability(),
                    $risk->getResidualImpact(),
                    $residualScore,
                    $residualLevel,
                    match($risk->getTreatmentStrategy()) {
                        'accept' => 'Akzeptieren',
                        'mitigate' => 'Mindern',
                        'transfer' => 'Übertragen',
                        'avoid' => 'Vermeiden',
                        default => $risk->getTreatmentStrategy()
                    },
                    match($risk->getStatus()) {
                        'identified' => 'Identifiziert',
                        'assessed' => 'Bewertet',
                        'treated' => 'Behandelt',
                        'monitored' => 'Überwacht',
                        'closed' => 'Geschlossen',
                        'accepted' => 'Akzeptiert',
                        default => $risk->getStatus()
                    },
                    $risk->getRiskOwner() ? $risk->getRiskOwner()->getFullName() : '-',
                    $risk->getCreatedAt() ? $risk->getCreatedAt()->format('d.m.Y') : '-',
                ];
            }

            $this->excelExportService->addFormattedDataRows($criticalSheet, $criticalData, 2, $conditionalFormatting);
            $this->excelExportService->autoSizeColumns($criticalSheet);
        }

        // Generate Excel file
        $content = $this->excelExportService->generateExcel($spreadsheet);

        $filename = sprintf(
            'risk_management_report_%s.xlsx',
            date('Y-m-d_His')
        );

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', strlen($content));

        return $response;
    }

    #[Route('/export/pdf', name: 'app_risk_export_pdf')]
    #[IsGranted('ROLE_USER')]
    public function exportPdf(Request $request): Response
    {
        // Get filter parameters (same as index)
        $level = $request->query->get('level');
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');

        // Get all risks
        $risks = $this->riskRepository->findAll();

        // Build filter info string
        $filterParts = [];
        if ($level) $filterParts[] = "Level: $level";
        if ($status) $filterParts[] = "Status: $status";
        if ($treatment) $filterParts[] = "Behandlung: $treatment";
        if ($owner) $filterParts[] = "Owner: $owner";
        $filterInfo = !empty($filterParts) ? implode(', ', $filterParts) : null;

        // Apply filters (same logic as index)
        if ($level) {
            $risks = array_filter($risks, function($risk) use ($level) {
                $score = $risk->getRiskScore();
                return match($level) {
                    'critical' => $score >= 15,
                    'high' => $score >= 8 && $score < 15,
                    'medium' => $score >= 4 && $score < 8,
                    'low' => $score < 4,
                    default => true
                };
            });
        }

        if ($status) {
            $risks = array_filter($risks, fn($risk) => $risk->getStatus() === $status);
        }

        if ($treatment) {
            $risks = array_filter($risks, fn($risk) => $risk->getTreatmentStrategy() === $treatment);
        }

        if ($owner) {
            $risks = array_filter($risks, fn($risk) =>
                $risk->getRiskOwner() && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false
            );
        }

        // Re-index array after filtering
        $risks = array_values($risks);

        // Calculate statistics
        $totalRisks = count($risks);
        $criticalRisks = count(array_filter($risks, fn($risk) => $risk->getRiskScore() >= 15));
        $highRisks = count(array_filter($risks, fn($risk) => $risk->getRiskScore() >= 8 && $risk->getRiskScore() < 15));
        $mediumRisks = count(array_filter($risks, fn($risk) => $risk->getRiskScore() >= 4 && $risk->getRiskScore() < 8));
        $lowRisks = count(array_filter($risks, fn($risk) => $risk->getRiskScore() < 4));

        // Status breakdown
        $statusBreakdown = [
            'identified' => count(array_filter($risks, fn($r) => $r->getStatus() === 'identified')),
            'assessed' => count(array_filter($risks, fn($r) => $r->getStatus() === 'assessed')),
            'treated' => count(array_filter($risks, fn($r) => $r->getStatus() === 'treated')),
            'monitored' => count(array_filter($risks, fn($r) => $r->getStatus() === 'monitored')),
            'closed' => count(array_filter($risks, fn($r) => $r->getStatus() === 'closed')),
            'accepted' => count(array_filter($risks, fn($r) => $r->getStatus() === 'accepted')),
        ];
        // Remove zero counts
        $statusBreakdown = array_filter($statusBreakdown, fn($count) => $count > 0);

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate PDF
        $pdfContent = $this->pdfExportService->generatePdf('pdf/risk_report.html.twig', [
            'risks' => $risks,
            'total_risks' => $totalRisks,
            'critical_risks' => $criticalRisks,
            'high_risks' => $highRisks,
            'medium_risks' => $mediumRisks,
            'low_risks' => $lowRisks,
            'status_breakdown' => $statusBreakdown,
            'filter_info' => $filterInfo,
            'pdf_generation_date' => new \DateTime(),
        ]);

        $filename = sprintf('risk_management_report_%s.pdf', date('Y-m-d_His'));

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', strlen($pdfContent));

        return $response;
    }

    #[Route('/new', name: 'app_risk_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $risk = new Risk();
        $form = $this->createForm(RiskType::class, $risk);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($risk);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk.success.created'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        return $this->render('risk/new.html.twig', [
            'risk' => $risk,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_risk_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Risk $risk): Response
    {
        // Get audit log history for this risk (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('Risk', $risk->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        return $this->render('risk/show.html.twig', [
            'risk' => $risk,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_risk_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Risk $risk): Response
    {
        $form = $this->createForm(RiskType::class, $risk);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk.success.updated'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        return $this->render('risk/edit.html.twig', [
            'risk' => $risk,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_risk_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Risk $risk): Response
    {
        if ($this->isCsrfTokenValid('delete'.$risk->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($risk);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk.success.deleted'));
        }

        return $this->redirectToRoute('app_risk_index');
    }

    #[Route('/matrix', name: 'app_risk_matrix')]
    public function matrix(): Response
    {
        $risks = $this->riskRepository->findAll();
        $matrixData = $this->riskMatrixService->generateMatrix();
        $statistics = $this->riskMatrixService->getRiskStatistics();
        $risksByLevel = $this->riskMatrixService->getRisksByLevel();

        return $this->render('risk/matrix.html.twig', [
            'risks' => $risks,
            'matrixData' => $matrixData,
            'statistics' => $statistics,
            'risksByLevel' => $risksByLevel,
        ]);
    }
}
