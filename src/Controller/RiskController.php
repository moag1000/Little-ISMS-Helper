<?php

namespace App\Controller;

use App\Entity\User;
use DateTime;
use Symfony\Component\Security\Core\User\UserInterface;
use Traversable;
use Exception;
use DomainException;
use App\Entity\Risk;
use App\Form\RiskType;
use App\Repository\AuditLogRepository;
use App\Repository\RiskRepository;
use App\Service\RiskMatrixService;
use App\Service\RiskService;
use App\Service\RiskAcceptanceWorkflowService;
use App\Service\ExcelExportService;
use App\Service\PdfExportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class RiskController extends AbstractController
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly RiskService $riskService,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RiskMatrixService $riskMatrixService,
        private readonly RiskAcceptanceWorkflowService $riskAcceptanceWorkflowService,
        private readonly TranslatorInterface $translator,
        private readonly ExcelExportService $excelExportService,
        private readonly PdfExportService $pdfExportService,
        private readonly Security $security
    ) {}
    #[Route('/risk/', name: 'app_risk_index')]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get filter parameters
        $level = $request->query->get('level'); // critical, high, medium, low
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');
        $view = $request->query->get('view', 'own'); // Default: own tenant's risks

        // Get risks based on view filter
        if ($tenant) {
            // Determine which risks to load based on view parameter
            $risks = match ($view) {
                // Only own risks
                'own' => $this->riskRepository->findByTenant($tenant),
                // Own + from all subsidiaries (for parent companies)
                'subsidiaries' => $this->riskRepository->findByTenantIncludingSubsidiaries($tenant),
                // Own + inherited from parents (default behavior)
                default => $this->riskService->getRisksForTenant($tenant),
            };
            // Filter high risks from the selected risk set
            $highRisks = array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() >= 12);
            // Calculate detailed statistics based on origin
            $detailedStats = $this->calculateDetailedStats($risks, $tenant);
            $inheritanceInfo = $this->riskService->getRiskInheritanceInfo($tenant);
            $inheritanceInfo['hasSubsidiaries'] = $tenant->getSubsidiaries()->count() > 0;
            $inheritanceInfo['currentView'] = $view;
        } else {
            // Fallback for users without tenant (e.g., super admins)
            $risks = $this->riskRepository->findAll();
            $highRisks = $this->riskRepository->findHighRisks();
            $detailedStats = ['own' => count($risks), 'inherited' => 0, 'subsidiaries' => 0, 'total' => count($risks)];
            $inheritanceInfo = [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

        // Apply filters
        if ($level) {
            $risks = array_filter($risks, function(Risk $risk) use ($level): bool {
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
            $risks = array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === $status);
        }

        if ($treatment) {
            $risks = array_filter($risks, fn(Risk $risk): bool => $risk->getTreatmentStrategy() === $treatment);
        }

        if ($owner) {
            $risks = array_filter($risks, fn(Risk $risk): bool =>
                $risk->getRiskOwner() instanceof User && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false
            );
        }

        // Re-index array after filtering
        $risks = array_values($risks);

        $treatmentStats = $this->riskRepository->countByTreatmentStrategy();

        return $this->render('risk/index.html.twig', [
            'risks' => $risks,
            'highRisks' => $highRisks,
            'treatmentStats' => $treatmentStats,
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
            'detailedStats' => $detailedStats,
        ]);
    }
    #[Route('/risk/export', name: 'app_risk_export')]
    #[IsGranted('ROLE_USER')]
    public function export(Request $request): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get filter parameters (same as index)
        $level = $request->query->get('level');
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');

        // Get risks: tenant-filtered if user has tenant, all risks if not
        $risks = $tenant ? $this->riskService->getRisksForTenant($tenant) : $this->riskRepository->findAll();

        // Apply filters (same logic as index)
        if ($level) {
            $risks = array_filter($risks, function(Risk $risk) use ($level): bool {
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
            $risks = array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === $status);
        }

        if ($treatment) {
            $risks = array_filter($risks, fn(Risk $risk): bool => $risk->getTreatmentStrategy() === $treatment);
        }

        if ($owner) {
            $risks = array_filter($risks, fn(Risk $risk): bool =>
                $risk->getRiskOwner() instanceof User && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false
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
            fputcsv($handle, $row, ';', escape: '\\'); // Use semicolon as delimiter for Excel compatibility
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }
    #[Route('/risk/export/excel', name: 'app_risk_export_excel')]
    #[IsGranted('ROLE_USER')]
    public function exportExcel(Request $request): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get filter parameters (same as index)
        $level = $request->query->get('level');
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');

        // Get risks: tenant-filtered if user has tenant, all risks if not
        $risks = $tenant ? $this->riskService->getRisksForTenant($tenant) : $this->riskRepository->findAll();

        // Apply filters (same logic as index)
        if ($level) {
            $risks = array_filter($risks, function(Risk $risk) use ($level): bool {
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
            $risks = array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === $status);
        }

        if ($treatment) {
            $risks = array_filter($risks, fn(Risk $risk): bool => $risk->getTreatmentStrategy() === $treatment);
        }

        if ($owner) {
            $risks = array_filter($risks, fn(Risk $risk): bool =>
                $risk->getRiskOwner() instanceof User && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false
            );
        }

        // Re-index array after filtering
        $risks = array_values($risks);

        // Calculate statistics
        $totalRisks = count($risks);
        $criticalRisks = count(array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() >= 15));
        $highRisks = count(array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() >= 8 && $risk->getRiskScore() < 15));
        $mediumRisks = count(array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() >= 4 && $risk->getRiskScore() < 8));
        $lowRisks = count(array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() < 4));

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        // Create spreadsheet
        $spreadsheet = $this->excelExportService->createSpreadsheet('Risk Management Report');

        // === TAB 1: Summary ===
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle('Zusammenfassung');

        $metrics = [
            'Gesamt Risiken' => $totalRisks,
            'Kritische Risiken' => $criticalRisks,
            'Hohe Risiken' => $highRisks,
            'Mittlere Risiken' => $mediumRisks,
            'Niedrige Risiken' => $lowRisks,
            'Export-Datum' => date('d.m.Y H:i'),
        ];

        $nextRow = $this->excelExportService->addSummarySection($worksheet, $metrics, 1, 'Risk Management Übersicht');

        // Add status breakdown
        $statusMetrics = [
            'Identifiziert' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'identified')),
            'Bewertet' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'assessed')),
            'Behandelt' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'treated')),
            'Überwacht' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'monitored')),
            'Geschlossen' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'closed')),
            'Akzeptiert' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'accepted')),
        ];

        $this->excelExportService->addSummarySection($worksheet, $statusMetrics, $nextRow, 'Status-Verteilung');
        $this->excelExportService->autoSizeColumns($worksheet);

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
        $criticalHighRisks = array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() >= 8);

        if ($criticalHighRisks !== []) {
            $criticalSheet = $this->excelExportService->createSheet($spreadsheet, 'Kritische & Hohe Risiken');

            $this->excelExportService->addFormattedHeaderRow($criticalSheet, $headers, 1, true);

            $criticalData = [];
            foreach ($criticalHighRisks as $criticalHighRisk) {
                $riskScore = $criticalHighRisk->getRiskScore();
                $residualScore = $criticalHighRisk->getResidualRiskScore();

                $riskLevel = $riskScore >= 15 ? 'Kritisch' : 'Hoch';
                $residualLevel = match(true) {
                    $residualScore >= 15 => 'Kritisch',
                    $residualScore >= 8 => 'Hoch',
                    $residualScore >= 4 => 'Mittel',
                    default => 'Niedrig'
                };

                $criticalData[] = [
                    $criticalHighRisk->getId(),
                    $criticalHighRisk->getTitle(),
                    $criticalHighRisk->getAsset() ? $criticalHighRisk->getAsset()->getName() : '-',
                    $criticalHighRisk->getProbability(),
                    $criticalHighRisk->getImpact(),
                    $riskScore,
                    $riskLevel,
                    $criticalHighRisk->getResidualProbability(),
                    $criticalHighRisk->getResidualImpact(),
                    $residualScore,
                    $residualLevel,
                    match($criticalHighRisk->getTreatmentStrategy()) {
                        'accept' => 'Akzeptieren',
                        'mitigate' => 'Mindern',
                        'transfer' => 'Übertragen',
                        'avoid' => 'Vermeiden',
                        default => $criticalHighRisk->getTreatmentStrategy()
                    },
                    match($criticalHighRisk->getStatus()) {
                        'identified' => 'Identifiziert',
                        'assessed' => 'Bewertet',
                        'treated' => 'Behandelt',
                        'monitored' => 'Überwacht',
                        'closed' => 'Geschlossen',
                        'accepted' => 'Akzeptiert',
                        default => $criticalHighRisk->getStatus()
                    },
                    $criticalHighRisk->getRiskOwner() ? $criticalHighRisk->getRiskOwner()->getFullName() : '-',
                    $criticalHighRisk->getCreatedAt() ? $criticalHighRisk->getCreatedAt()->format('d.m.Y') : '-',
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
    #[Route('/risk/export/pdf', name: 'app_risk_export_pdf')]
    #[IsGranted('ROLE_USER')]
    public function exportPdf(Request $request): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get filter parameters (same as index)
        $level = $request->query->get('level');
        $status = $request->query->get('status');
        $treatment = $request->query->get('treatment');
        $owner = $request->query->get('owner');

        // Get risks: tenant-filtered if user has tenant, all risks if not
        $risks = $tenant ? $this->riskService->getRisksForTenant($tenant) : $this->riskRepository->findAll();

        // Build filter info string
        $filterParts = [];
        if ($level) {
            $filterParts[] = "Level: $level";
        }
        if ($status) {
            $filterParts[] = "Status: $status";
        }
        if ($treatment) {
            $filterParts[] = "Behandlung: $treatment";
        }
        if ($owner) {
            $filterParts[] = "Owner: $owner";
        }
        $filterInfo = $filterParts === [] ? null : implode(', ', $filterParts);

        // Apply filters (same logic as index)
        if ($level) {
            $risks = array_filter($risks, function(Risk $risk) use ($level): bool {
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
            $risks = array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === $status);
        }

        if ($treatment) {
            $risks = array_filter($risks, fn(Risk $risk): bool => $risk->getTreatmentStrategy() === $treatment);
        }

        if ($owner) {
            $risks = array_filter($risks, fn(Risk $risk): bool =>
                $risk->getRiskOwner() instanceof User && stripos($risk->getRiskOwner()->getFullName(), $owner) !== false
            );
        }

        // Re-index array after filtering
        $risks = array_values($risks);

        // Calculate statistics
        $totalRisks = count($risks);
        $criticalRisks = count(array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() >= 15));
        $highRisks = count(array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() >= 8 && $risk->getRiskScore() < 15));
        $mediumRisks = count(array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() >= 4 && $risk->getRiskScore() < 8));
        $lowRisks = count(array_filter($risks, fn(Risk $risk): bool => $risk->getRiskScore() < 4));

        // Status breakdown
        $statusBreakdown = [
            'identified' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'identified')),
            'assessed' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'assessed')),
            'treated' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'treated')),
            'monitored' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'monitored')),
            'closed' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'closed')),
            'accepted' => count(array_filter($risks, fn(Risk $risk): bool => $risk->getStatus() === 'accepted')),
        ];
        // Remove zero counts
        $statusBreakdown = array_filter($statusBreakdown, fn(int $count): bool => $count > 0);

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
            'pdf_generation_date' => new DateTime(),
        ]);

        $filename = sprintf('risk_management_report_%s.pdf', date('Y-m-d_His'));

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', strlen($pdfContent));

        return $response;
    }
    #[Route('/risk/new', name: 'app_risk_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $risk = new Risk();

        // Set tenant from current user
        $user = $this->security->getUser();
        if ($user instanceof UserInterface && $user->getTenant()) {
            $risk->setTenant($user->getTenant());
        }

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
    #[Route('/risk/matrix', name: 'app_risk_matrix')]
    public function matrix(): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get risks: tenant-filtered if user has tenant, all risks if not
        $risks = $tenant ? $this->riskService->getRisksForTenant($tenant) : $this->riskRepository->findAll();

        $matrixData = $this->riskMatrixService->generateMatrix();
        $statistics = $this->riskMatrixService->getRiskStatistics();
        $risksByLevel = $this->riskMatrixService->getRisksByLevel();

        // Serialize risks for JavaScript consumption
        $serializedRisks = array_map(fn(Risk $risk): array => [
            'id' => $risk->getId(),
            'title' => $risk->getTitle(),
            'probability' => $risk->getProbability() ?? 1,
            'impact' => $risk->getImpact() ?? 1,
        ], $risks instanceof Traversable ? iterator_to_array($risks) : $risks);

        return $this->render('risk/matrix.html.twig', [
            'risks' => $serializedRisks,
            'matrixData' => $matrixData,
            'statistics' => $statistics,
            'risksByLevel' => $risksByLevel,
        ]);
    }
    #[Route('/risk/bulk-delete', name: 'app_risk_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function bulkDelete(Request $request): Response
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $risk = $this->riskRepository->find($id);

                if (!$risk) {
                    $errors[] = "Risk ID $id not found";
                    continue;
                }

                // Security check: cannot delete inherited risks
                if ($tenant && !$this->riskService->canEditRisk($risk, $tenant)) {
                    $errors[] = "Risk ID $id is inherited and cannot be deleted";
                    continue;
                }

                $this->entityManager->remove($risk);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting risk ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        if ($errors !== []) {
            return $this->json([
                'success' => $deleted > 0,
                'deleted' => $deleted,
                'errors' => $errors
            ], $deleted > 0 ? 200 : 400);
        }

        return $this->json([
            'success' => true,
            'deleted' => $deleted,
            'message' => "$deleted risks deleted successfully"
        ]);
    }
    #[Route('/risk/{id}', name: 'app_risk_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Risk $risk): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get audit log history for this risk (last 10 entries)
        $auditLogs = $this->auditLogRepository->findByEntity('Risk', $risk->getId());
        $recentAuditLogs = array_slice($auditLogs, 0, 10);

        // Check if risk is inherited (only if user has tenant)
        if ($tenant) {
            $isInherited = $this->riskService->isInheritedRisk($risk, $tenant);
            $canEdit = $this->riskService->canEditRisk($risk, $tenant);
        } else {
            // Users without tenant (e.g., super admins) can edit everything
            $isInherited = false;
            $canEdit = true;
        }

        return $this->render('risk/show.html.twig', [
            'risk' => $risk,
            'auditLogs' => $recentAuditLogs,
            'totalAuditLogs' => count($auditLogs),
            'isInherited' => $isInherited,
            'canEdit' => $canEdit,
            'currentTenant' => $tenant,
        ]);
    }
    #[Route('/risk/{id}/edit', name: 'app_risk_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Risk $risk): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if risk can be edited (not inherited) - only if user has tenant
        if ($tenant && !$this->riskService->canEditRisk($risk, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_edit_inherited'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

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
    #[Route('/risk/{id}/delete', name: 'app_risk_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Risk $risk): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Check if risk can be deleted (not inherited) - only if user has tenant
        if ($tenant && !$this->riskService->canEditRisk($risk, $tenant)) {
            $this->addFlash('error', $this->translator->trans('corporate.inheritance.cannot_edit_inherited'));
            return $this->redirectToRoute('app_risk_index');
        }

        if ($this->isCsrfTokenValid('delete'.$risk->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($risk);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('risk.success.deleted'));
        }

        return $this->redirectToRoute('app_risk_index');
    }
    /**
     * Request formal risk acceptance (Priority 2.1 - Risk Acceptance Workflow)
     * ISO 27005:2022 Section 8.4.4
     */
    #[Route('/risk/{id}/request-acceptance', name: 'app_risk_request_acceptance', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function requestAcceptance(Request $request, Risk $risk): Response
    {
        $user = $this->security->getUser();

        // Check if risk has "accept" treatment strategy
        if ($risk->getTreatmentStrategy() !== 'accept') {
            $this->addFlash('error', $this->translator->trans('risk.acceptance.error.wrong_strategy'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        // Check if already formally accepted
        if ($risk->isFormallyAccepted()) {
            $this->addFlash('warning', $this->translator->trans('risk.acceptance.error.already_accepted'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        // Handle form submission
        if ($request->isMethod('POST')) {
            // CSRF token validation
            if (!$this->isCsrfTokenValid('request-acceptance'.$risk->getId(), $request->request->get('_token'))) {
                $this->addFlash('error', $this->translator->trans('security.csrf_token_invalid'));
                return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
            }

            $justification = $request->request->get('justification');

            if (empty($justification)) {
                $this->addFlash('error', $this->translator->trans('risk.acceptance.error.justification_required'));
            } else {
                try {
                    $result = $this->riskAcceptanceWorkflowService->requestAcceptance(
                        $risk,
                        $user,
                        $justification
                    );

                    if ($result['status'] === 'accepted') {
                        // Automatic acceptance
                        $this->addFlash('success', $this->translator->trans('risk.acceptance.success.auto_accepted'));
                    } else {
                        // Pending approval
                        $this->addFlash('success', $this->translator->trans(
                            'risk.acceptance.success.approval_requested',
                            ['%approver%' => $result['approver'], '%level%' => $result['approval_level']]
                        ));
                    }

                    return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
                } catch (DomainException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        // Get approval thresholds for display
        $thresholds = $this->riskAcceptanceWorkflowService->getApprovalThresholds();
        $requiredLevel = $this->riskAcceptanceWorkflowService->determineApprovalLevel($risk);

        return $this->render('risk/request_acceptance.html.twig', [
            'risk' => $risk,
            'thresholds' => $thresholds,
            'required_level' => $requiredLevel,
        ]);
    }
    /**
     * Approve risk acceptance (Priority 2.1 - Risk Acceptance Workflow)
     */
    #[Route('/risk/{id}/approve-acceptance', name: 'app_risk_approve_acceptance', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function approveAcceptance(Request $request, Risk $risk): Response
    {
        $user = $this->security->getUser();

        if (!$this->isCsrfTokenValid('approve-acceptance'.$risk->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('security.csrf_token_invalid'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $comments = $request->request->get('comments', '');

        try {
            $result = $this->riskAcceptanceWorkflowService->approveAcceptance($risk, $user, $comments);
            $this->addFlash('success', $this->translator->trans('risk.acceptance.success.approved'));
        } catch (Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
    }
    /**
     * Reject risk acceptance (Priority 2.1 - Risk Acceptance Workflow)
     */
    #[Route('/risk/{id}/reject-acceptance', name: 'app_risk_reject_acceptance', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function rejectAcceptance(Request $request, Risk $risk): Response
    {
        $user = $this->security->getUser();

        if (!$this->isCsrfTokenValid('reject-acceptance'.$risk->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('security.csrf_token_invalid'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        $reason = $request->request->get('reason');

        if (empty($reason)) {
            $this->addFlash('error', $this->translator->trans('risk.acceptance.error.reason_required'));
            return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
        }

        try {
            $result = $this->riskAcceptanceWorkflowService->rejectAcceptance($risk, $user, $reason);
            $this->addFlash('warning', $this->translator->trans('risk.acceptance.success.rejected'));
        } catch (Exception $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_risk_show', ['id' => $risk->getId()]);
    }
    /**
     * Calculate detailed statistics showing breakdown by origin
     *
     * @param array $items Array of entities to analyze
     * @param mixed $currentTenant Current tenant for comparison
     * @return array Statistics with keys: own, inherited, subsidiaries, total
     */
    private function calculateDetailedStats(array $items, mixed $currentTenant): array
    {
        $ownCount = 0;
        $inheritedCount = 0;
        $subsidiariesCount = 0;

        // Get ancestors and subsidiaries for comparison
        $ancestors = $currentTenant->getAllAncestors();
        $ancestorIds = array_map(fn($t) => $t->getId(), $ancestors);

        $subsidiaries = $currentTenant->getAllSubsidiaries();
        $subsidiaryIds = array_map(fn($t) => $t->getId(), $subsidiaries);

        foreach ($items as $item) {
            $itemTenant = $item->getTenant();
            if (!$itemTenant) {
                continue;
            }

            $itemTenantId = $itemTenant->getId();
            $currentTenantId = $currentTenant->getId();

            if ($itemTenantId === $currentTenantId) {
                // Own record
                $ownCount++;
            } elseif (in_array($itemTenantId, $ancestorIds)) {
                // Inherited from parent/ancestor
                $inheritedCount++;
            } elseif (in_array($itemTenantId, $subsidiaryIds)) {
                // From subsidiary
                $subsidiariesCount++;
            }
        }

        return [
            'own' => $ownCount,
            'inherited' => $inheritedCount,
            'subsidiaries' => $subsidiariesCount,
            'total' => $ownCount + $inheritedCount + $subsidiariesCount
        ];
    }
}
