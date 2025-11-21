<?php

namespace App\Controller;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceMappingRepository;
use App\Service\ComplianceAssessmentService;
use App\Service\ComplianceMappingService;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\ComplianceRequirementFulfillmentService;
use App\Service\ExcelExportService;
use App\Service\ModuleConfigurationService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/compliance')]
class ComplianceController extends AbstractController
{
    public function __construct(
        private ComplianceFrameworkRepository $frameworkRepository,
        private ComplianceRequirementRepository $requirementRepository,
        private ComplianceMappingRepository $mappingRepository,
        private ComplianceAssessmentService $assessmentService,
        private ComplianceMappingService $mappingService,
        private ComplianceFrameworkLoaderService $frameworkLoaderService,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ExcelExportService $excelExportService,
        private ModuleConfigurationService $moduleConfigurationService,
        private PdfExportService $pdfExportService,
        private ComplianceRequirementFulfillmentService $fulfillmentService,
        private TenantContext $tenantContext
    ) {}

    #[Route('/', name: 'app_compliance_index')]
    public function index(): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $overview = $this->frameworkRepository->getComplianceOverview();
        $mappingStats = $this->mappingRepository->getMappingStatistics();

        // Calculate total data reuse value
        $totalTimeSavings = 0;
        foreach ($frameworks as $framework) {
            $requirements = $this->requirementRepository->findApplicableByFramework($framework);
            foreach ($requirements as $requirement) {
                $reuseValue = $this->mappingService->calculateDataReuseValue($requirement);
                $totalTimeSavings += $reuseValue['estimated_hours_saved'];
            }
        }

        return $this->render('compliance/index.html.twig', [
            'frameworks' => $frameworks,
            'overview' => $overview,
            'mapping_stats' => $mappingStats,
            'total_time_savings' => $totalTimeSavings,
            'total_days_savings' => round($totalTimeSavings / 8, 1),
        ]);
    }

    #[Route('/framework/{id}', name: 'app_compliance_framework', requirements: ['id' => '\d+'])]
    public function frameworkDashboard(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $dashboard = $this->assessmentService->getComplianceDashboard($framework);
        $requirements = $this->requirementRepository->findByFramework($framework);

        $allModules = $this->moduleConfigurationService->getAllModules();
        $activeModules = $this->moduleConfigurationService->getActiveModules();

        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('No tenant assigned to user. Please contact administrator.');
        }

        // Load tenant-specific fulfillments for all requirements (batch)
        // Including detailed requirements (nested)
        // For SUPER_ADMIN without tenant, show empty fulfillments
        $fulfillments = [];
        if ($tenant) {
            foreach ($requirements as $requirement) {
                $fulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $requirement);
                $fulfillments[$requirement->getId()] = $fulfillment;

                // Also load fulfillments for detailed requirements
                if ($requirement->hasDetailedRequirements()) {
                    foreach ($requirement->getDetailedRequirements() as $detailedRequirement) {
                        $detailedFulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $detailedRequirement);
                        $fulfillments[$detailedRequirement->getId()] = $detailedFulfillment;
                    }
                }
            }
        }

        return $this->render('compliance/framework_dashboard.html.twig', [
            'framework' => $framework,
            'dashboard' => $dashboard,
            'requirements' => $requirements,
            'fulfillments' => $fulfillments,
            'all_modules' => $allModules,
            'active_modules' => $activeModules,
        ]);
    }

    #[Route('/framework/{id}/gaps', name: 'app_compliance_gaps', requirements: ['id' => '\d+'])]
    public function gapAnalysis(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $gaps = $this->requirementRepository->findGapsByFramework($framework);
        $criticalGaps = $this->requirementRepository->findByFrameworkAndPriority($framework, 'critical');

        // Analyze each gap for detailed insights
        $gapAnalysis = [];
        foreach ($gaps as $gap) {
            $analysis = $this->assessmentService->assessRequirement($gap);
            $gapAnalysis[] = [
                'requirement' => $gap,
                'analysis' => $analysis,
            ];
        }

        return $this->render('compliance/gap_analysis.html.twig', [
            'framework' => $framework,
            'gaps' => $gapAnalysis,
            'critical_gaps' => $criticalGaps,
            'total_gaps' => count($gaps),
        ]);
    }

    #[Route('/framework/{id}/data-reuse', name: 'app_compliance_data_reuse', requirements: ['id' => '\d+'])]
    public function dataReuseInsights(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $requirements = $this->requirementRepository->findApplicableByFramework($framework);
        $dataReuseAnalysis = [];
        $totalTimeSavings = 0;

        foreach ($requirements as $requirement) {
            $analysis = $this->mappingService->getDataReuseAnalysis($requirement);
            $reuseValue = $this->mappingService->calculateDataReuseValue($requirement);

            $dataReuseAnalysis[] = [
                'requirement' => $requirement,
                'analysis' => $analysis,
                'value' => $reuseValue,
            ];

            $totalTimeSavings += $reuseValue['estimated_hours_saved'];
        }

        return $this->render('compliance/data_reuse_insights.html.twig', [
            'framework' => $framework,
            'analysis' => $dataReuseAnalysis,
            'total_time_savings' => $totalTimeSavings,
            'total_days_savings' => round($totalTimeSavings / 8, 1),
        ]);
    }

    #[Route('/framework/{id}/data-reuse/export', name: 'app_compliance_export_reuse', requirements: ['id' => '\d+'])]
    public function exportDataReuse(Request $request, int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $requirements = $this->requirementRepository->findApplicableByFramework($framework);
        $dataReuseAnalysis = [];
        $totalTimeSavings = 0;

        foreach ($requirements as $requirement) {
            $analysis = $this->mappingService->getDataReuseAnalysis($requirement);
            $reuseValue = $this->mappingService->calculateDataReuseValue($requirement);

            $dataReuseAnalysis[] = [
                'requirement' => $requirement,
                'analysis' => $analysis,
                'value' => $reuseValue,
            ];

            $totalTimeSavings += $reuseValue['estimated_hours_saved'];
        }

        // Close session to prevent blocking other requests during CSV generation
        $request->getSession()->save();

        // Create CSV content
        $csv = [];

        // CSV Header - Title
        $csv[] = ['Data Reuse Insights - ' . $framework->getName()];
        $csv[] = [];

        // Summary section
        $csv[] = ['Zusammenfassung'];
        $csv[] = ['Framework', $framework->getName() . ' (' . $framework->getCode() . ')'];
        $csv[] = ['Gesamt Zeitersparnis (Stunden)', $totalTimeSavings];
        $csv[] = ['Gesamt Zeitersparnis (Tage)', round($totalTimeSavings / 8, 1)];
        $csv[] = ['Anzahl analysierten Anforderungen', count($dataReuseAnalysis)];
        $csv[] = [];

        // CSV Header - Requirements
        $csv[] = [
            'Anforderungs-ID',
            'Titel',
            'Kategorie',
            'Wiederverwendbare Daten',
            'Datenquelle',
            'Geschätzte Zeitersparnis (h)',
            'Reuse-Prozentsatz (%)',
            'Confidence',
        ];

        // CSV Data - Requirements
        foreach ($dataReuseAnalysis as $item) {
            $requirement = $item['requirement'];
            $value = $item['value'];
            $analysis = $item['analysis'];

            $reusableDataSources = [];
            if (!empty($analysis['reusable_data'])) {
                foreach ($analysis['reusable_data'] as $data) {
                    $reusableDataSources[] = $data['source'] ?? 'Unknown';
                }
            }

            $csv[] = [
                $requirement->getRequirementId(),
                $requirement->getTitle(),
                $requirement->getCategory() ?? '-',
                !empty($analysis['reusable_data']) ? count($analysis['reusable_data']) : 0,
                !empty($reusableDataSources) ? implode(', ', $reusableDataSources) : '-',
                $value['estimated_hours_saved'] ?? 0,
                $value['reuse_percentage'] ?? 0,
                $value['confidence'] ?? 'low',
            ];
        }

        // Generate CSV file
        $filename = sprintf(
            'data_reuse_insights_%s_%s.csv',
            $framework->getCode(),
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
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/framework/{id}/data-reuse/export/excel', name: 'app_compliance_export_reuse_excel', requirements: ['id' => '\d+'])]
    public function exportDataReuseExcel(Request $request, int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $requirements = $this->requirementRepository->findApplicableByFramework($framework);
        $dataReuseAnalysis = [];
        $totalTimeSavings = 0;

        foreach ($requirements as $requirement) {
            $analysis = $this->mappingService->getDataReuseAnalysis($requirement);
            $reuseValue = $this->mappingService->calculateDataReuseValue($requirement);

            $dataReuseAnalysis[] = [
                'requirement' => $requirement,
                'analysis' => $analysis,
                'value' => $reuseValue,
            ];

            $totalTimeSavings += $reuseValue['estimated_hours_saved'];
        }

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        // Create spreadsheet
        $spreadsheet = $this->excelExportService->createSpreadsheet('Data Reuse Insights Report');

        // Tab 1: Summary
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Zusammenfassung');

        $metrics = [
            'Framework' => $framework->getName() . ' (' . $framework->getCode() . ')',
            'Analysierte Anforderungen' => count($dataReuseAnalysis),
            'Zeitersparnis (Stunden)' => round($totalTimeSavings, 1),
            'Zeitersparnis (Tage)' => round($totalTimeSavings / 8, 1),
            'Export-Datum' => date('d.m.Y H:i'),
        ];

        $this->excelExportService->addSummarySection($summarySheet, $metrics, 1, 'Data Reuse Insights');
        $this->excelExportService->autoSizeColumns($summarySheet);

        // Tab 2: Details
        $detailsSheet = $this->excelExportService->createSheet($spreadsheet, 'Reuse Details');

        $headers = ['ID', 'Titel', 'Kategorie', 'Reusable Data', 'Quellen', 'Zeitersparnis (h)', 'Reuse %', 'Confidence'];
        $this->excelExportService->addFormattedHeaderRow($detailsSheet, $headers, 1, true);

        $data = [];
        foreach ($dataReuseAnalysis as $item) {
            $requirement = $item['requirement'];
            $value = $item['value'];
            $analysis = $item['analysis'];

            $reusableDataSources = [];
            if (!empty($analysis['reusable_data'])) {
                foreach ($analysis['reusable_data'] as $data) {
                    $reusableDataSources[] = $data['source'] ?? 'Unknown';
                }
            }

            $data[] = [
                $requirement->getRequirementId(),
                $requirement->getTitle(),
                $requirement->getCategory() ?? '-',
                !empty($analysis['reusable_data']) ? count($analysis['reusable_data']) : 0,
                !empty($reusableDataSources) ? implode(', ', array_slice($reusableDataSources, 0, 3)) : '-',
                round($value['estimated_hours_saved'] ?? 0, 1),
                round($value['reuse_percentage'] ?? 0, 1),
                $value['confidence'] ?? 'low',
            ];
        }

        // Conditional formatting
        $conditionalFormatting = [
            6 => [ // Reuse %
                '>=80' => $this->excelExportService->getColor('success'),
                '>=50' => $this->excelExportService->getColor('warning'),
                '<50' => ['color' => $this->excelExportService->getColor('danger'), 'bold' => false],
            ],
        ];

        $this->excelExportService->addFormattedDataRows($detailsSheet, $data, 2, $conditionalFormatting);
        $this->excelExportService->autoSizeColumns($detailsSheet);

        // Generate
        $content = $this->excelExportService->generateExcel($spreadsheet);

        $filename = sprintf('data_reuse_insights_%s_%s.xlsx', $framework->getCode(), date('Y-m-d_His'));

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    #[Route('/framework/{id}/data-reuse/export/pdf', name: 'app_compliance_export_reuse_pdf', requirements: ['id' => '\d+'])]
    public function exportDataReusePdf(Request $request, int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $requirements = $this->requirementRepository->findApplicableByFramework($framework);
        $dataReuseAnalysis = [];
        $totalTimeSavings = 0;
        $totalReusePercentage = 0;

        foreach ($requirements as $requirement) {
            $analysis = $this->mappingService->getDataReuseAnalysis($requirement);
            $reuseValue = $this->mappingService->calculateDataReuseValue($requirement);

            $dataReuseAnalysis[] = [
                'requirement' => $requirement,
                'analysis' => $analysis,
                'value' => $reuseValue,
            ];

            $totalTimeSavings += $reuseValue['estimated_hours_saved'];
            $totalReusePercentage += $reuseValue['reuse_percentage'] ?? 0;
        }

        $avgReusePercentage = count($dataReuseAnalysis) > 0 ? $totalReusePercentage / count($dataReuseAnalysis) : 0;

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from generation date (Format: Year.Month.Day)
        $pdfGenerationDate = new \DateTime();
        $version = $pdfGenerationDate->format('Y.m.d');

        // Generate PDF
        $pdfContent = $this->pdfExportService->generatePdf('pdf/data_reuse_insights_report.html.twig', [
            'framework' => $framework,
            'data_reuse_analysis' => $dataReuseAnalysis,
            'total_requirements' => count($requirements),
            'total_time_savings' => $totalTimeSavings,
            'total_days_savings' => round($totalTimeSavings / 8, 1),
            'avg_reuse_percentage' => $avgReusePercentage,
            'pdf_generation_date' => $pdfGenerationDate,
            'version' => $version,
        ]);

        $filename = sprintf('data_reuse_insights_%s_%s.pdf', $framework->getCode(), date('Y-m-d_His'));

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', strlen($pdfContent));

        return $response;
    }

    #[Route('/framework/{id}/gaps/export', name: 'app_compliance_export_gaps', requirements: ['id' => '\d+'])]
    public function exportGaps(Request $request, int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $gaps = $this->requirementRepository->findGapsByFramework($framework);
        $requirements = $this->requirementRepository->findByFramework($framework);
        $metRequirements = count($requirements) - count($gaps);

        // Analyze each gap
        $gapAnalysis = [];
        foreach ($gaps as $gap) {
            $analysis = $this->assessmentService->assessRequirement($gap);
            $gapAnalysis[] = [
                'requirement' => $gap,
                'analysis' => $analysis,
            ];
        }

        // Close session to prevent blocking other requests during CSV generation
        $request->getSession()->save();

        // Create CSV content
        $csv = [];

        // CSV Header - Title
        $csv[] = ['Gap Analysis - ' . $framework->getName()];
        $csv[] = [];

        // Summary section
        $csv[] = ['Zusammenfassung'];
        $csv[] = ['Framework', $framework->getName() . ' (' . $framework->getCode() . ')'];
        $csv[] = ['Gesamt Anforderungen', count($requirements)];
        $csv[] = ['Erfüllte Anforderungen', $metRequirements];
        $csv[] = ['Identifizierte Gaps', count($gaps)];
        $complianceScore = count($requirements) > 0 ? round(($metRequirements / count($requirements)) * 100, 2) : 0;
        $csv[] = ['Compliance Score (%)', $complianceScore];
        $csv[] = [];

        // Gap severity breakdown
        $criticalCount = 0;
        $highCount = 0;
        $mediumCount = 0;
        $lowCount = 0;

        foreach ($gapAnalysis as $item) {
            $priority = $item['requirement']->getPriority() ?? 'low';
            switch ($priority) {
                case 'critical':
                    $criticalCount++;
                    break;
                case 'high':
                    $highCount++;
                    break;
                case 'medium':
                    $mediumCount++;
                    break;
                default:
                    $lowCount++;
            }
        }

        $csv[] = ['Gaps nach Severity'];
        $csv[] = ['Kritisch', $criticalCount];
        $csv[] = ['Hoch', $highCount];
        $csv[] = ['Mittel', $mediumCount];
        $csv[] = ['Niedrig', $lowCount];
        $csv[] = [];

        // CSV Header - Gaps
        $csv[] = [
            'Anforderungs-ID',
            'Titel',
            'Kategorie',
            'Beschreibung',
            'Priority/Severity',
            'Status',
            'Erfüllungsgrad (%)',
            'Gap-Grund',
        ];

        // CSV Data - Gaps
        foreach ($gapAnalysis as $item) {
            $requirement = $item['requirement'];
            $analysis = $item['analysis'];

            // Translate priority
            $priorityMap = [
                'critical' => 'Kritisch',
                'high' => 'Hoch',
                'medium' => 'Mittel',
                'low' => 'Niedrig',
            ];

            // Translate status
            $statusMap = [
                'not_applicable' => 'Nicht anwendbar',
                'not_implemented' => 'Nicht implementiert',
                'partially_implemented' => 'Teilweise implementiert',
                'implemented' => 'Implementiert',
                'not_assessed' => 'Nicht bewertet',
            ];

            $csv[] = [
                $requirement->getRequirementId(),
                $requirement->getTitle(),
                $requirement->getCategory() ?? '-',
                $requirement->getDescription() ?? '-',
                $priorityMap[$requirement->getPriority() ?? 'low'] ?? 'Niedrig',
                $statusMap[$requirement->getStatus() ?? 'not_assessed'] ?? 'Nicht bewertet',
                $requirement->getFulfillmentPercentage() ?? 0,
                $analysis['gap_reason'] ?? 'Nicht erfüllt',
            ];
        }

        // Generate CSV file
        $filename = sprintf(
            'gap_analysis_%s_%s.csv',
            $framework->getCode(),
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
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/framework/{id}/gaps/export/excel', name: 'app_compliance_export_gaps_excel', requirements: ['id' => '\d+'])]
    public function exportGapsExcel(Request $request, int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $gaps = $this->requirementRepository->findGapsByFramework($framework);
        $requirements = $this->requirementRepository->findByFramework($framework);
        $metRequirements = count($requirements) - count($gaps);

        // Analyze gaps
        $gapAnalysis = [];
        $severityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($gaps as $gap) {
            $analysis = $this->assessmentService->assessRequirement($gap);
            $priority = $gap->getPriority() ?? 'low';
            $severityCounts[$priority]++;

            $gapAnalysis[] = [
                'requirement' => $gap,
                'analysis' => $analysis,
            ];
        }

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        // Create spreadsheet
        $spreadsheet = $this->excelExportService->createSpreadsheet('Gap Analysis Report');

        // Tab 1: Summary
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Zusammenfassung');

        $complianceScore = count($requirements) > 0 ? round(($metRequirements / count($requirements)) * 100, 1) : 0;

        $metrics = [
            'Framework' => $framework->getName() . ' (' . $framework->getCode() . ')',
            'Gesamt Anforderungen' => count($requirements),
            'Erfüllte Anforderungen' => $metRequirements,
            'Identifizierte Gaps' => count($gaps),
            'Compliance Score' => $complianceScore . '%',
            'Export-Datum' => date('d.m.Y H:i'),
        ];

        $nextRow = $this->excelExportService->addSummarySection($summarySheet, $metrics, 1, 'Gap Analysis');

        // Severity breakdown
        $severityMetrics = [
            'Kritische Gaps' => $severityCounts['critical'],
            'Hohe Gaps' => $severityCounts['high'],
            'Mittlere Gaps' => $severityCounts['medium'],
            'Niedrige Gaps' => $severityCounts['low'],
        ];
        $this->excelExportService->addSummarySection($summarySheet, $severityMetrics, $nextRow, 'Severity Breakdown');
        $this->excelExportService->autoSizeColumns($summarySheet);

        // Tab 2: Gap Details
        $detailsSheet = $this->excelExportService->createSheet($spreadsheet, 'Gap Details');

        $headers = ['ID', 'Titel', 'Kategorie', 'Priority', 'Status', 'Erfüllungsgrad %', 'Gap Grund'];
        $this->excelExportService->addFormattedHeaderRow($detailsSheet, $headers, 1, true);

        $data = [];
        foreach ($gapAnalysis as $item) {
            $requirement = $item['requirement'];
            $analysis = $item['analysis'];

            $priorityMap = ['critical' => 'Kritisch', 'high' => 'Hoch', 'medium' => 'Mittel', 'low' => 'Niedrig'];
            $statusMap = [
                'not_applicable' => 'Nicht anwendbar',
                'not_implemented' => 'Nicht implementiert',
                'partially_implemented' => 'Teilweise',
                'implemented' => 'Implementiert',
                'not_assessed' => 'Nicht bewertet',
            ];

            $data[] = [
                $requirement->getRequirementId(),
                $requirement->getTitle(),
                $requirement->getCategory() ?? '-',
                $priorityMap[$requirement->getPriority() ?? 'low'] ?? 'Niedrig',
                $statusMap[$requirement->getStatus() ?? 'not_assessed'] ?? '-',
                $requirement->getFulfillmentPercentage() ?? 0,
                substr($analysis['gap_reason'] ?? 'Nicht erfüllt', 0, 100),
            ];
        }

        // Conditional formatting
        $conditionalFormatting = [
            3 => [ // Priority
                'Kritisch' => $this->excelExportService->getColor('critical'),
                'Hoch' => $this->excelExportService->getColor('high'),
                'Mittel' => $this->excelExportService->getColor('medium'),
                'Niedrig' => $this->excelExportService->getColor('low'),
            ],
            5 => [ // Fulfillment %
                '>=80' => $this->excelExportService->getColor('success'),
                '>=50' => $this->excelExportService->getColor('warning'),
                '<50' => $this->excelExportService->getColor('danger'),
            ],
        ];

        $this->excelExportService->addFormattedDataRows($detailsSheet, $data, 2, $conditionalFormatting);
        $this->excelExportService->autoSizeColumns($detailsSheet);

        // Generate
        $content = $this->excelExportService->generateExcel($spreadsheet);

        $filename = sprintf('gap_analysis_%s_%s.xlsx', $framework->getCode(), date('Y-m-d_His'));

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    #[Route('/framework/{id}/gaps/export/pdf', name: 'app_compliance_export_gaps_pdf', requirements: ['id' => '\d+'])]
    public function exportGapsPdf(Request $request, int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $gaps = $this->requirementRepository->findGapsByFramework($framework);
        $requirements = $this->requirementRepository->findByFramework($framework);
        $metRequirements = count($requirements) - count($gaps);

        // Get current tenant for fulfillment data
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('No tenant assigned to user. Please contact administrator.');
        }

        // Analyze gaps and get fulfillment data
        // For SUPER_ADMIN without tenant, skip fulfillment data
        $gapAnalysis = [];
        $gapFulfillments = [];
        $severityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($gaps as $gap) {
            $analysis = $this->assessmentService->assessRequirement($gap);
            $priority = $gap->getPriority() ?? 'low';
            $severityCounts[$priority]++;

            // Get tenant-specific fulfillment for this gap (only if tenant exists)
            if ($tenant) {
                $fulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $gap);
                $gapFulfillments[$gap->getId()] = $fulfillment;
            }

            $gapAnalysis[] = [
                'requirement' => $gap,
                'analysis' => $analysis,
                'fulfillment' => $fulfillment,
            ];
        }

        $complianceScore = count($requirements) > 0 ? round(($metRequirements / count($requirements)) * 100, 1) : 0;

        // Calculate priority-weighted gap analysis
        $priorityWeightedAnalysis = $this->mappingRepository->calculatePriorityWeightedGaps($framework, $gaps);

        // Perform root cause analysis for gaps
        $rootCauseAnalysis = $this->mappingRepository->analyzeGapRootCauses($gaps);

        // Calculate weighted compliance score (how well critical/high requirements are met)
        $totalRequirements = count($requirements);
        $weightedScore = 0;
        if ($totalRequirements > 0) {
            $priorityWeights = ['critical' => 4.0, 'high' => 2.0, 'medium' => 1.0, 'low' => 0.5];
            $totalPossibleWeight = 0;
            $achievedWeight = 0;

            // TODO: This needs to be refactored to use tenant-specific fulfillment data
            // For now, using basic calculation without fulfillment percentages
            foreach ($requirements as $req) {
                $priority = $req->getPriority() ?? 'medium';
                $weight = $priorityWeights[$priority] ?? 1.0;
                $totalPossibleWeight += $weight;
                // Simplified: assume 0% fulfillment for gaps, 100% for met requirements
                $isGap = in_array($req, $gaps, true);
                $achievedWeight += $isGap ? 0 : $weight;
            }

            $weightedScore = $totalPossibleWeight > 0
                ? round(($achievedWeight / $totalPossibleWeight) * 100, 1)
                : 0;
        }

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from generation date (Format: Year.Month.Day)
        $pdfGenerationDate = new \DateTime();
        $version = $pdfGenerationDate->format('Y.m.d');

        // Generate PDF
        $pdfContent = $this->pdfExportService->generatePdf('pdf/gap_analysis_report.html.twig', [
            'framework' => $framework,
            'gaps' => $gapAnalysis,
            'total_requirements' => count($requirements),
            'met_requirements' => $metRequirements,
            'total_gaps' => count($gaps),
            'compliance_score' => $complianceScore,
            'severity_counts' => $severityCounts,
            // Priority-weighted analysis
            'priority_weighted_analysis' => $priorityWeightedAnalysis,
            'weighted_compliance_score' => $weightedScore,
            'risk_score' => $priorityWeightedAnalysis['risk_score'],
            'uncovered_critical' => $priorityWeightedAnalysis['uncovered_critical'],
            'uncovered_high' => $priorityWeightedAnalysis['uncovered_high'],
            'priority_distribution' => $priorityWeightedAnalysis['priority_distribution'],
            'gap_recommendations' => $priorityWeightedAnalysis['recommendations'],
            // Root cause analysis
            'root_cause_analysis' => $rootCauseAnalysis,
            'root_causes' => $rootCauseAnalysis['root_causes'],
            'root_cause_summary' => $rootCauseAnalysis['summary'],
            'category_patterns' => $rootCauseAnalysis['category_patterns'],
            'root_cause_recommendations' => $rootCauseAnalysis['recommendations'],
            'pdf_generation_date' => $pdfGenerationDate,
            'version' => $version,
        ]);

        $filename = sprintf('gap_analysis_%s_%s.pdf', $framework->getCode(), date('Y-m-d_His'));

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', strlen($pdfContent));

        return $response;
    }

    #[Route('/cross-framework', name: 'app_compliance_cross_framework')]
    public function crossFrameworkMappings(): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $crossMappings = [];
        $coverageMatrix = [];

        // Generate cross-framework coverage matrix
        foreach ($frameworks as $sourceFramework) {
            foreach ($frameworks as $targetFramework) {
                if ($sourceFramework->getId() === $targetFramework->getId()) {
                    continue;
                }

                $coverage = $this->mappingRepository->calculateFrameworkCoverage(
                    $sourceFramework,
                    $targetFramework
                );

                $coverageMatrix[$sourceFramework->getCode()][$targetFramework->getCode()] = $coverage;

                // Get detailed mappings
                $mappings = $this->mappingRepository->findCrossFrameworkMappings(
                    $sourceFramework,
                    $targetFramework
                );

                if (!empty($mappings)) {
                    $crossMappings[] = [
                        'source' => $sourceFramework,
                        'target' => $targetFramework,
                        'mappings' => $mappings,
                        'coverage' => $coverage,
                    ];
                }
            }
        }

        return $this->render('compliance/cross_framework.html.twig', [
            'frameworks' => $frameworks,
            'cross_mappings' => $crossMappings,
            'coverage_matrix' => $coverageMatrix,
        ]);
    }

    #[Route('/transitive-compliance', name: 'app_compliance_transitive')]
    public function transitiveCompliance(): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $transitiveAnalysis = [];
        $mappingMatrix = [];
        $crossMappings = [];
        $coverageMatrix = [];
        $frameworkRelationships = [];
        $frameworksLeveragedSet = [];

        // Build mapping coverage matrix for template
        foreach ($frameworks as $sourceFramework) {
            foreach ($frameworks as $targetFramework) {
                if ($sourceFramework->getId() === $targetFramework->getId()) {
                    continue;
                }

                // Calculate coverage for matrix
                $coverage = $this->mappingRepository->calculateFrameworkCoverage(
                    $sourceFramework,
                    $targetFramework
                );

                $mappingMatrix[$sourceFramework->getId()][$targetFramework->getId()] = [
                    'coverage' => $coverage['coverage_percentage'] ?? 0,
                    'has_mapping' => ($coverage['coverage_percentage'] ?? 0) > 0
                ];

                // Build coverage matrix for cross-framework display
                $coverageMatrix[$sourceFramework->getCode()][$targetFramework->getCode()] = $coverage;

                // Transitive analysis
                $transitive = $this->mappingRepository->getTransitiveCompliance(
                    $sourceFramework,
                    $targetFramework
                );

                if ($transitive['requirements_helped'] > 0) {
                    $transitiveAnalysis[] = $transitive;
                }

                // Get detailed cross-framework mappings
                $mappings = $this->mappingRepository->findCrossFrameworkMappings(
                    $sourceFramework,
                    $targetFramework
                );

                if (!empty($mappings) && ($coverage['coverage_percentage'] ?? 0) > 0) {
                    $crossMappings[] = [
                        'source' => $sourceFramework,
                        'target' => $targetFramework,
                        'mappings' => $mappings,
                        'coverage' => $coverage,
                    ];

                    // Build framework relationships for KPI cards
                    $frameworkRelationships[] = (object)[
                        'id' => $sourceFramework->getId() . '_' . $targetFramework->getId(),
                        'sourceFramework' => $sourceFramework,
                        'targetFramework' => $targetFramework,
                        'mappedRequirements' => $coverage['covered_requirements'] ?? 0,
                        'coveragePercentage' => round($coverage['coverage_percentage'] ?? 0),
                    ];

                    // Track frameworks being leveraged
                    $frameworksLeveragedSet[$targetFramework->getId()] = true;
                }
            }
        }

        return $this->render('compliance/transitive_compliance.html.twig', [
            'frameworks' => $frameworks,
            'transitive_analysis' => $transitiveAnalysis,
            'mapping_matrix' => $mappingMatrix,
            'total_relationships' => count($transitiveAnalysis),
            'transitive_compliance' => array_sum(array_column($transitiveAnalysis, 'requirements_helped')),
            'leverage_opportunities' => [],
            'cross_mappings' => $crossMappings,
            'coverage_matrix' => $coverageMatrix,
            'framework_relationships' => $frameworkRelationships,
            'frameworks_leveraged' => count($frameworksLeveragedSet),
        ]);
    }

    #[Route('/compare', name: 'app_compliance_compare')]
    public function compareFrameworks(Request $request): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();

        $selectedFramework1 = null;
        $selectedFramework2 = null;
        $comparison = null;
        $framework1Requirements = 0;
        $framework2Requirements = 0;
        $commonRequirements = 0;
        $framework1Categories = [];
        $framework2Categories = [];

        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if ($framework1Id && $framework2Id) {
            $selectedFramework1 = $this->frameworkRepository->find($framework1Id);
            $selectedFramework2 = $this->frameworkRepository->find($framework2Id);

            if ($selectedFramework1 && $selectedFramework2) {
                $comparison = $this->assessmentService->compareFrameworks([$selectedFramework1, $selectedFramework2]);
                $framework1Requirements = count($selectedFramework1->getRequirements());
                $framework2Requirements = count($selectedFramework2->getRequirements());

                // Get unique categories from each framework
                $framework1Categories = array_unique(
                    array_filter(
                        array_map(fn($req) => $req->getCategory(), $selectedFramework1->getRequirements()->toArray())
                    )
                );
                $framework2Categories = array_unique(
                    array_filter(
                        array_map(fn($req) => $req->getCategory(), $selectedFramework2->getRequirements()->toArray())
                    )
                );

                // Build detailed comparison data
                $comparisonDetails = [];
                $mappedCount = 0;

                foreach ($selectedFramework1->getRequirements() as $req1) {
                    $mappedRequirement = null;
                    $matchQuality = null;
                    $isMapped = false;

                    // Find mappings where req1 is the source
                    $sourceMappings = $this->mappingRepository->findBy([
                        'sourceRequirement' => $req1
                    ]);

                    foreach ($sourceMappings as $mapping) {
                        if ($mapping->getTargetRequirement()->getFramework()->getId() === $selectedFramework2->getId()) {
                            $mappedRequirement = $mapping->getTargetRequirement();
                            $matchQuality = $mapping->getMappingPercentage();
                            $isMapped = true;
                            $mappedCount++;
                            break;
                        }
                    }

                    // Also check reverse mappings where req1 is the target
                    if (!$isMapped) {
                        $targetMappings = $this->mappingRepository->findBy([
                            'targetRequirement' => $req1
                        ]);

                        foreach ($targetMappings as $mapping) {
                            if ($mapping->getSourceRequirement()->getFramework()->getId() === $selectedFramework2->getId()) {
                                $mappedRequirement = $mapping->getSourceRequirement();
                                $matchQuality = $mapping->getMappingPercentage();
                                $isMapped = true;
                                $mappedCount++;
                                break;
                            }
                        }
                    }

                    $comparisonDetails[] = [
                        'framework1Requirement' => $req1,
                        'mapped' => $isMapped,
                        'framework2Requirement' => $mappedRequirement,
                        'matchQuality' => $matchQuality,
                    ];
                }

                $commonRequirements = $mappedCount;

                // Calculate unique requirements (not mapped)
                $framework1Unique = [];
                $framework2Unique = [];

                // Framework 1 unique requirements
                foreach ($comparisonDetails as $detail) {
                    if (!$detail['mapped']) {
                        $framework1Unique[] = $detail['framework1Requirement'];
                    }
                }

                // Framework 2 unique requirements
                $mappedFramework2Ids = [];
                foreach ($comparisonDetails as $detail) {
                    if ($detail['mapped'] && $detail['framework2Requirement']) {
                        $mappedFramework2Ids[] = $detail['framework2Requirement']->getId();
                    }
                }

                foreach ($selectedFramework2->getRequirements() as $req2) {
                    if (!in_array($req2->getId(), $mappedFramework2Ids)) {
                        $framework2Unique[] = $req2;
                    }
                }
            }
        }

        return $this->render('compliance/compare.html.twig', [
            'frameworks' => $frameworks,
            'selectedFramework1' => $selectedFramework1,
            'selectedFramework2' => $selectedFramework2,
            'comparison' => $comparison,
            'comparisonDetails' => $comparisonDetails ?? [],
            'framework1Requirements' => $framework1Requirements,
            'framework2Requirements' => $framework2Requirements,
            'commonRequirements' => $commonRequirements,
            'framework1UniqueRequirements' => max(0, $framework1Requirements - $commonRequirements),
            'framework2UniqueRequirements' => max(0, $framework2Requirements - $commonRequirements),
            'framework1Categories' => $framework1Categories,
            'framework2Categories' => $framework2Categories,
            'framework1Unique' => $framework1Unique ?? [],
            'framework2Unique' => $framework2Unique ?? [],
        ]);
    }

    #[Route('/framework/{id}/assess', name: 'app_compliance_assess', requirements: ['id' => '\d+'])]
    public function assessFramework(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        // Run assessment and update all requirement fulfillment percentages
        $assessmentResults = $this->assessmentService->assessFramework($framework);

        $this->addFlash('success', sprintf(
            'Assessment completed for %s. %d requirements assessed.',
            $framework->getName(),
            $assessmentResults['requirements_assessed']
        ));

        return $this->redirectToRoute('app_compliance_framework', ['id' => $id]);
    }

        /**
     * Redirect to admin framework management
     * Framework management is now centralized in the admin panel
     */
    #[Route('/frameworks/manage', name: 'app_compliance_manage_frameworks')]
    #[IsGranted('ROLE_ADMIN')]
    public function manageFrameworks(): Response
    {
        // Redirect to centralized admin framework management
        return $this->redirectToRoute('admin_compliance_index');
    }
    #[Route('/export/transitive', name: 'app_compliance_export_transitive')]
    public function exportTransitive(Request $request): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $transitiveAnalysis = [];
        $frameworkRelationships = [];

        // Build transitive analysis data (same as in transitiveCompliance method)
        foreach ($frameworks as $sourceFramework) {
            foreach ($frameworks as $targetFramework) {
                if ($sourceFramework->getId() === $targetFramework->getId()) {
                    continue;
                }

                // Calculate coverage
                $coverage = $this->mappingRepository->calculateFrameworkCoverage(
                    $sourceFramework,
                    $targetFramework
                );

                // Get transitive analysis
                $transitive = $this->mappingRepository->getTransitiveCompliance(
                    $sourceFramework,
                    $targetFramework
                );

                if ($transitive['requirements_helped'] > 0) {
                    $transitiveAnalysis[] = $transitive;
                }

                // Get detailed cross-framework mappings
                $mappings = $this->mappingRepository->findCrossFrameworkMappings(
                    $sourceFramework,
                    $targetFramework
                );

                if (!empty($mappings) && ($coverage['coverage_percentage'] ?? 0) > 0) {
                    $frameworkRelationships[] = [
                        'sourceFramework' => $sourceFramework,
                        'targetFramework' => $targetFramework,
                        'mappedRequirements' => $coverage['covered_requirements'] ?? 0,
                        'totalRequirements' => $coverage['total_requirements'] ?? 0,
                        'coveragePercentage' => round($coverage['coverage_percentage'] ?? 0, 2),
                    ];
                }
            }
        }

        // Close session to prevent blocking other requests during CSV generation
        $request->getSession()->save();

        // Create CSV content
        $csv = [];

        // CSV Header - Framework Relationships
        $csv[] = ['Framework-Beziehungen und Transitive Compliance'];
        $csv[] = [];
        $csv[] = [
            'Quell-Framework',
            'Ziel-Framework',
            'Gemappte Anforderungen',
            'Gesamt-Anforderungen',
            'Coverage (%)',
        ];

        // CSV Data - Framework Relationships
        foreach ($frameworkRelationships as $relationship) {
            $csv[] = [
                $relationship['sourceFramework']->getName() . ' (' . $relationship['sourceFramework']->getCode() . ')',
                $relationship['targetFramework']->getName() . ' (' . $relationship['targetFramework']->getCode() . ')',
                $relationship['mappedRequirements'],
                $relationship['totalRequirements'],
                $relationship['coveragePercentage'],
            ];
        }

        // Add summary section
        $csv[] = [];
        $csv[] = ['Zusammenfassung'];
        $csv[] = [];
        $csv[] = ['Metrik', 'Wert'];
        $csv[] = ['Anzahl aktiver Frameworks', count($frameworks)];
        $csv[] = ['Anzahl Framework-Beziehungen', count($frameworkRelationships)];
        $csv[] = ['Transitive Compliance Opportunities', count($transitiveAnalysis)];

        if (!empty($transitiveAnalysis)) {
            $totalRequirementsHelped = array_sum(array_column($transitiveAnalysis, 'requirements_helped'));
            $csv[] = ['Gesamt unterstützte Anforderungen', $totalRequirementsHelped];
        }

        // Generate CSV file
        $filename = sprintf(
            'transitive_compliance_export_%s.csv',
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

    #[Route('/export/transitive/excel', name: 'app_compliance_export_transitive_excel')]
    public function exportTransitiveExcel(Request $request): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $transitiveAnalysis = [];
        $frameworkRelationships = [];

        // Build data
        foreach ($frameworks as $sourceFramework) {
            foreach ($frameworks as $targetFramework) {
                if ($sourceFramework->getId() === $targetFramework->getId()) {
                    continue;
                }

                $coverage = $this->mappingRepository->calculateFrameworkCoverage($sourceFramework, $targetFramework);
                $transitive = $this->mappingRepository->getTransitiveCompliance($sourceFramework, $targetFramework);

                if ($transitive['requirements_helped'] > 0) {
                    $transitiveAnalysis[] = $transitive;
                }

                $mappings = $this->mappingRepository->findCrossFrameworkMappings($sourceFramework, $targetFramework);

                if (!empty($mappings) && ($coverage['coverage_percentage'] ?? 0) > 0) {
                    $frameworkRelationships[] = [
                        'source' => $sourceFramework,
                        'target' => $targetFramework,
                        'mapped' => $coverage['covered_requirements'] ?? 0,
                        'total' => $coverage['total_requirements'] ?? 0,
                        'coverage' => round($coverage['coverage_percentage'] ?? 0, 1),
                    ];
                }
            }
        }

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        // Create spreadsheet
        $spreadsheet = $this->excelExportService->createSpreadsheet('Transitive Compliance Report');

        // Tab 1: Summary
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Zusammenfassung');

        $totalHelped = !empty($transitiveAnalysis) ? array_sum(array_column($transitiveAnalysis, 'requirements_helped')) : 0;

        $metrics = [
            'Aktive Frameworks' => count($frameworks),
            'Framework-Beziehungen' => count($frameworkRelationships),
            'Transitive Opportunities' => count($transitiveAnalysis),
            'Unterstützte Anforderungen' => $totalHelped,
            'Export-Datum' => date('d.m.Y H:i'),
        ];

        $this->excelExportService->addSummarySection($summarySheet, $metrics, 1, 'Transitive Compliance');
        $this->excelExportService->autoSizeColumns($summarySheet);

        // Tab 2: Framework Relationships
        $relationshipsSheet = $this->excelExportService->createSheet($spreadsheet, 'Framework-Beziehungen');

        $headers = ['Quell-Framework', 'Ziel-Framework', 'Gemapped', 'Total', 'Coverage %'];
        $this->excelExportService->addFormattedHeaderRow($relationshipsSheet, $headers, 1, true);

        $data = [];
        foreach ($frameworkRelationships as $rel) {
            $data[] = [
                $rel['source']->getName() . ' (' . $rel['source']->getCode() . ')',
                $rel['target']->getName() . ' (' . $rel['target']->getCode() . ')',
                $rel['mapped'],
                $rel['total'],
                $rel['coverage'],
            ];
        }

        // Conditional formatting for coverage
        $conditionalFormatting = [
            4 => [ // Coverage %
                '>=80' => $this->excelExportService->getColor('success'),
                '>=50' => $this->excelExportService->getColor('warning'),
                '<50' => ['color' => $this->excelExportService->getColor('danger'), 'bold' => false],
            ],
        ];

        $this->excelExportService->addFormattedDataRows($relationshipsSheet, $data, 2, $conditionalFormatting);
        $this->excelExportService->autoSizeColumns($relationshipsSheet);

        // Generate
        $content = $this->excelExportService->generateExcel($spreadsheet);

        $filename = sprintf('transitive_compliance_%s.xlsx', date('Y-m-d_His'));

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    #[Route('/export/transitive/pdf', name: 'app_compliance_export_transitive_pdf')]
    public function exportTransitivePdf(Request $request): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $transitiveAnalysis = [];
        $frameworkRelationships = [];
        $totalHelped = 0;

        // Build data with impact scoring
        $quickWins = [];
        $highImpactRelationships = [];
        $totalImpactScore = 0;

        // Calculate mapping quality distribution across all framework relationships
        $qualityDistribution = [
            'excellent' => 0,  // 90-100%
            'good' => 0,       // 70-89%
            'medium' => 0,     // 50-69%
            'weak' => 0,       // <50%
        ];
        $qualitySum = 0;
        $qualityCount = 0;

        foreach ($frameworks as $sourceFramework) {
            foreach ($frameworks as $targetFramework) {
                if ($sourceFramework->getId() === $targetFramework->getId()) {
                    continue;
                }

                $coverage = $this->mappingRepository->calculateFrameworkCoverage($sourceFramework, $targetFramework);
                $transitive = $this->mappingRepository->getTransitiveCompliance($sourceFramework, $targetFramework);

                if ($transitive['requirements_helped'] > 0) {
                    $transitiveAnalysis[] = $transitive;
                    $totalHelped += $transitive['requirements_helped'];
                }

                $mappings = $this->mappingRepository->findCrossFrameworkMappings($sourceFramework, $targetFramework);

                // Calculate quality distribution for these mappings
                foreach ($mappings as $mapping) {
                    $quality = $mapping->getMappingPercentage();
                    if ($quality !== null) {
                        $qualitySum += $quality;
                        $qualityCount++;

                        if ($quality >= 90) {
                            $qualityDistribution['excellent']++;
                        } elseif ($quality >= 70) {
                            $qualityDistribution['good']++;
                        } elseif ($quality >= 50) {
                            $qualityDistribution['medium']++;
                        } else {
                            $qualityDistribution['weak']++;
                        }
                    }
                }

                if (!empty($mappings) && ($coverage['coverage_percentage'] ?? 0) > 0) {
                    $coveragePercentage = round($coverage['coverage_percentage'] ?? 0, 1);

                    // Calculate impact score and ROI
                    $impactAnalysis = $this->mappingRepository->calculateFrameworkImpactScore(
                        $sourceFramework,
                        $targetFramework,
                        $coveragePercentage
                    );

                    $relationship = [
                        'source' => $sourceFramework,
                        'target' => $targetFramework,
                        'mapped' => $coverage['covered_requirements'] ?? 0,
                        'total' => $coverage['total_requirements'] ?? 0,
                        'coverage' => $coveragePercentage,
                        'impact_score' => $impactAnalysis['impact_score'],
                        'roi' => $impactAnalysis['roi'],
                        'is_quick_win' => $impactAnalysis['is_quick_win'],
                        'effort_estimate' => $impactAnalysis['effort_estimate'],
                        'priority_multiplier' => $impactAnalysis['factors']['priority_multiplier'],
                    ];

                    $frameworkRelationships[] = $relationship;
                    $totalImpactScore += $impactAnalysis['impact_score'];

                    // Track quick wins
                    if ($impactAnalysis['is_quick_win']) {
                        $quickWins[] = $relationship;
                    }

                    // Track high impact relationships (top tier)
                    if ($impactAnalysis['impact_score'] >= 50) {
                        $highImpactRelationships[] = $relationship;
                    }
                }
            }
        }

        $avgMappingQuality = $qualityCount > 0 ? round($qualitySum / $qualityCount, 1) : 0;

        // Sort framework relationships by impact score (highest first)
        usort($frameworkRelationships, fn($a, $b) => $b['impact_score'] <=> $a['impact_score']);
        usort($quickWins, fn($a, $b) => $b['roi'] <=> $a['roi']);
        usort($highImpactRelationships, fn($a, $b) => $b['impact_score'] <=> $a['impact_score']);

        // Calculate average coverage and impact
        $avgCoverage = 0;
        $avgImpactScore = 0;
        if (!empty($frameworkRelationships)) {
            $totalCoverage = array_sum(array_column($frameworkRelationships, 'coverage'));
            $avgCoverage = $totalCoverage / count($frameworkRelationships);
            $avgImpactScore = $totalImpactScore / count($frameworkRelationships);
        }

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from generation date (Format: Year.Month.Day)
        $pdfGenerationDate = new \DateTime();
        $version = $pdfGenerationDate->format('Y.m.d');

        // Generate PDF
        $pdfContent = $this->pdfExportService->generatePdf('pdf/transitive_compliance_report.html.twig', [
            'frameworks' => $frameworks,
            'framework_relationships' => $frameworkRelationships,
            'transitive_analysis' => $transitiveAnalysis,
            'total_frameworks' => count($frameworks),
            'total_relationships' => count($frameworkRelationships),
            'transitive_count' => count($transitiveAnalysis),
            'total_helped' => $totalHelped,
            'avg_coverage' => $avgCoverage,
            'avg_impact_score' => round($avgImpactScore, 1),
            'total_impact_score' => round($totalImpactScore, 1),
            'quick_wins' => $quickWins,
            'high_impact_relationships' => $highImpactRelationships,
            'quick_win_count' => count($quickWins),
            'high_impact_count' => count($highImpactRelationships),
            // Mapping quality distribution
            'quality_distribution' => $qualityDistribution,
            'avg_mapping_quality' => $avgMappingQuality,
            'total_mappings' => $qualityCount,
            'pdf_generation_date' => $pdfGenerationDate,
            'version' => $version,
        ]);

        $filename = sprintf('transitive_compliance_report_%s.pdf', date('Y-m-d_His'));

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', strlen($pdfContent));

        return $response;
    }

    #[Route('/export/comparison', name: 'app_compliance_export_comparison')]
    public function exportComparison(Request $request): Response
    {
        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if (!$framework1Id || !$framework2Id) {
            $this->addFlash('error', 'Bitte wählen Sie zwei Frameworks zum Vergleich aus.');
            return $this->redirectToRoute('app_compliance_compare');
        }

        $framework1 = $this->frameworkRepository->find($framework1Id);
        $framework2 = $this->frameworkRepository->find($framework2Id);

        if (!$framework1 || !$framework2) {
            $this->addFlash('error', 'Ein oder beide Frameworks wurden nicht gefunden.');
            return $this->redirectToRoute('app_compliance_compare');
        }

        // Build detailed comparison data
        $comparisonDetails = [];

        foreach ($framework1->getRequirements() as $req1) {
            $mappedRequirement = null;
            $matchQuality = null;
            $isMapped = false;

            // Find mappings where req1 is the source
            $sourceMappings = $this->mappingRepository->findBy([
                'sourceRequirement' => $req1
            ]);

            foreach ($sourceMappings as $mapping) {
                if ($mapping->getTargetRequirement()->getFramework()->getId() === $framework2->getId()) {
                    $mappedRequirement = $mapping->getTargetRequirement();
                    $matchQuality = $mapping->getMappingPercentage();
                    $isMapped = true;
                    break;
                }
            }

            // Also check reverse mappings where req1 is the target
            if (!$isMapped) {
                $targetMappings = $this->mappingRepository->findBy([
                    'targetRequirement' => $req1
                ]);

                foreach ($targetMappings as $mapping) {
                    if ($mapping->getSourceRequirement()->getFramework()->getId() === $framework2->getId()) {
                        $mappedRequirement = $mapping->getSourceRequirement();
                        $matchQuality = $mapping->getMappingPercentage();
                        $isMapped = true;
                        break;
                    }
                }
            }

            $comparisonDetails[] = [
                'framework1Requirement' => $req1,
                'mapped' => $isMapped,
                'framework2Requirement' => $mappedRequirement,
                'matchQuality' => $matchQuality,
            ];
        }

        // Close session to prevent blocking other requests during CSV generation
        $request->getSession()->save();

        // Create CSV content
        $csv = [];

        // CSV Header
        $csv[] = [
            $framework1->getName() . ' - ID',
            $framework1->getName() . ' - Titel',
            $framework1->getName() . ' - Kategorie',
            'Mapping Status',
            'Match Qualität (%)',
            $framework2->getName() . ' - ID',
            $framework2->getName() . ' - Titel',
            $framework2->getName() . ' - Kategorie',
        ];

        // CSV Data
        foreach ($comparisonDetails as $detail) {
            $csv[] = [
                $detail['framework1Requirement']->getRequirementId(),
                $detail['framework1Requirement']->getTitle(),
                $detail['framework1Requirement']->getCategory() ?? '-',
                $detail['mapped'] ? 'Gemapped' : 'Nicht gemapped',
                $detail['matchQuality'] ?? '-',
                $detail['framework2Requirement'] ? $detail['framework2Requirement']->getRequirementId() : '-',
                $detail['framework2Requirement'] ? $detail['framework2Requirement']->getTitle() : '-',
                $detail['framework2Requirement'] ? ($detail['framework2Requirement']->getCategory() ?? '-') : '-',
            ];
        }

        // Generate CSV file
        $filename = sprintf(
            'framework_comparison_%s_vs_%s_%s.csv',
            $framework1->getCode(),
            $framework2->getCode(),
            date('Y-m-d')
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

    #[Route('/export/comparison/excel', name: 'app_compliance_export_comparison_excel')]
    public function exportComparisonExcel(Request $request): Response
    {
        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if (!$framework1Id || !$framework2Id) {
            $this->addFlash('error', 'Bitte wählen Sie zwei Frameworks zum Vergleich aus.');
            return $this->redirectToRoute('app_compliance_compare');
        }

        $framework1 = $this->frameworkRepository->find($framework1Id);
        $framework2 = $this->frameworkRepository->find($framework2Id);

        if (!$framework1 || !$framework2) {
            $this->addFlash('error', 'Ein oder beide Frameworks wurden nicht gefunden.');
            return $this->redirectToRoute('app_compliance_compare');
        }

        // Build detailed comparison data
        $comparisonDetails = [];
        $mappedCount = 0;

        foreach ($framework1->getRequirements() as $req1) {
            $mappedRequirement = null;
            $matchQuality = null;
            $isMapped = false;

            // Find mappings
            $sourceMappings = $this->mappingRepository->findBy(['sourceRequirement' => $req1]);
            foreach ($sourceMappings as $mapping) {
                if ($mapping->getTargetRequirement()->getFramework()->getId() === $framework2->getId()) {
                    $mappedRequirement = $mapping->getTargetRequirement();
                    $matchQuality = $mapping->getMappingPercentage();
                    $isMapped = true;
                    $mappedCount++;
                    break;
                }
            }

            if (!$isMapped) {
                $targetMappings = $this->mappingRepository->findBy(['targetRequirement' => $req1]);
                foreach ($targetMappings as $mapping) {
                    if ($mapping->getSourceRequirement()->getFramework()->getId() === $framework2->getId()) {
                        $mappedRequirement = $mapping->getSourceRequirement();
                        $matchQuality = $mapping->getMappingPercentage();
                        $isMapped = true;
                        $mappedCount++;
                        break;
                    }
                }
            }

            $comparisonDetails[] = [
                'framework1Requirement' => $req1,
                'mapped' => $isMapped,
                'framework2Requirement' => $mappedRequirement,
                'matchQuality' => $matchQuality,
            ];
        }

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        // Create spreadsheet
        $spreadsheet = $this->excelExportService->createSpreadsheet('Framework Comparison Report');

        // === TAB 1: Summary ===
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Zusammenfassung');

        $framework1Count = count($framework1->getRequirements());
        $framework2Count = count($framework2->getRequirements());
        $overlapPercentage = $framework1Count > 0 ? round(($mappedCount / $framework1Count) * 100, 1) : 0;

        $metrics = [
            'Framework 1' => $framework1->getName() . ' (' . $framework1->getCode() . ')',
            'Framework 2' => $framework2->getName() . ' (' . $framework2->getCode() . ')',
            'Framework 1 Anforderungen' => $framework1Count,
            'Framework 2 Anforderungen' => $framework2Count,
            'Gemappte Anforderungen' => $mappedCount,
            'Overlap Prozentsatz' => $overlapPercentage . '%',
            'Export-Datum' => date('d.m.Y H:i'),
        ];

        $this->excelExportService->addSummarySection($summarySheet, $metrics, 1, 'Framework Vergleich');
        $this->excelExportService->autoSizeColumns($summarySheet);

        // === TAB 2: Detailed Comparison ===
        $detailsSheet = $this->excelExportService->createSheet($spreadsheet, 'Detaillierter Vergleich');

        $headers = [
            $framework1->getName() . ' ID',
            $framework1->getName() . ' Titel',
            $framework1->getName() . ' Kategorie',
            'Mapping Status',
            'Match %',
            $framework2->getName() . ' ID',
            $framework2->getName() . ' Titel',
            $framework2->getName() . ' Kategorie',
        ];

        $this->excelExportService->addFormattedHeaderRow($detailsSheet, $headers, 1, true);

        $data = [];
        foreach ($comparisonDetails as $detail) {
            $data[] = [
                $detail['framework1Requirement']->getRequirementId(),
                $detail['framework1Requirement']->getTitle(),
                $detail['framework1Requirement']->getCategory() ?? '-',
                $detail['mapped'] ? 'Gemapped' : 'Nicht gemapped',
                $detail['matchQuality'] ?? '-',
                $detail['framework2Requirement'] ? $detail['framework2Requirement']->getRequirementId() : '-',
                $detail['framework2Requirement'] ? $detail['framework2Requirement']->getTitle() : '-',
                $detail['framework2Requirement'] ? ($detail['framework2Requirement']->getCategory() ?? '-') : '-',
            ];
        }

        // Conditional formatting for mapping status and match quality
        $conditionalFormatting = [
            3 => [ // Mapping Status
                'Gemapped' => $this->excelExportService->getColor('success'),
                'Nicht gemapped' => ['color' => $this->excelExportService->getColor('warning'), 'bold' => false],
            ],
            4 => [ // Match Quality
                '>=80' => $this->excelExportService->getColor('success'),
                '>=60' => $this->excelExportService->getColor('warning'),
                '<60' => $this->excelExportService->getColor('danger'),
            ],
        ];

        $this->excelExportService->addFormattedDataRows($detailsSheet, $data, 2, $conditionalFormatting);
        $this->excelExportService->autoSizeColumns($detailsSheet);

        // === TAB 3: Framework 1 Unique ===
        $framework1Unique = array_filter($comparisonDetails, fn($d) => !$d['mapped']);
        if (!empty($framework1Unique)) {
            $unique1Sheet = $this->excelExportService->createSheet($spreadsheet, 'Unique ' . substr($framework1->getCode(), 0, 10));

            $uniqueHeaders = ['ID', 'Titel', 'Kategorie', 'Beschreibung'];
            $this->excelExportService->addFormattedHeaderRow($unique1Sheet, $uniqueHeaders, 1, true);

            $uniqueData = [];
            foreach ($framework1Unique as $detail) {
                $req = $detail['framework1Requirement'];
                $uniqueData[] = [
                    $req->getRequirementId(),
                    $req->getTitle(),
                    $req->getCategory() ?? '-',
                    substr($req->getDescription() ?? '-', 0, 200), // Limit description length
                ];
            }

            $this->excelExportService->addFormattedDataRows($unique1Sheet, $uniqueData, 2);
            $this->excelExportService->autoSizeColumns($unique1Sheet);
        }

        // Generate Excel file
        $content = $this->excelExportService->generateExcel($spreadsheet);

        $filename = sprintf(
            'framework_comparison_%s_vs_%s_%s.xlsx',
            $framework1->getCode(),
            $framework2->getCode(),
            date('Y-m-d_His')
        );

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', strlen($content));

        return $response;
    }

    #[Route('/export/comparison/pdf', name: 'app_compliance_export_comparison_pdf')]
    public function exportComparisonPdf(Request $request): Response
    {
        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if (!$framework1Id || !$framework2Id) {
            $this->addFlash('error', 'Bitte wählen Sie zwei Frameworks zum Vergleich aus.');
            return $this->redirectToRoute('app_compliance_compare');
        }

        $framework1 = $this->frameworkRepository->find($framework1Id);
        $framework2 = $this->frameworkRepository->find($framework2Id);

        if (!$framework1 || !$framework2) {
            $this->addFlash('error', 'Ein oder beide Frameworks wurden nicht gefunden.');
            return $this->redirectToRoute('app_compliance_compare');
        }

        // Build detailed comparison data
        $comparisonDetails = [];
        $mappedCount = 0;
        $highQualityMappings = 0;

        foreach ($framework1->getRequirements() as $req1) {
            $mappedRequirement = null;
            $matchQuality = null;
            $isMapped = false;

            // Find mappings
            $sourceMappings = $this->mappingRepository->findBy(['sourceRequirement' => $req1]);
            foreach ($sourceMappings as $mapping) {
                if ($mapping->getTargetRequirement()->getFramework()->getId() === $framework2->getId()) {
                    $mappedRequirement = $mapping->getTargetRequirement();
                    $matchQuality = $mapping->getMappingPercentage();
                    $isMapped = true;
                    $mappedCount++;
                    if ($matchQuality >= 80) {
                        $highQualityMappings++;
                    }
                    break;
                }
            }

            if (!$isMapped) {
                $targetMappings = $this->mappingRepository->findBy(['targetRequirement' => $req1]);
                foreach ($targetMappings as $mapping) {
                    if ($mapping->getSourceRequirement()->getFramework()->getId() === $framework2->getId()) {
                        $mappedRequirement = $mapping->getSourceRequirement();
                        $matchQuality = $mapping->getMappingPercentage();
                        $isMapped = true;
                        $mappedCount++;
                        if ($matchQuality >= 80) {
                            $highQualityMappings++;
                        }
                        break;
                    }
                }
            }

            $comparisonDetails[] = [
                'framework1Requirement' => $req1,
                'mapped' => $isMapped,
                'framework2Requirement' => $mappedRequirement,
                'matchQuality' => $matchQuality,
            ];
        }

        // Calculate metrics
        $framework1Count = count($framework1->getRequirements());
        $framework2Count = count($framework2->getRequirements());
        $overlapPercentage = $framework1Count > 0 ? round(($mappedCount / $framework1Count) * 100, 1) : 0;
        $unmapped = $framework1Count - $mappedCount;

        // Find unique requirements
        $uniqueFramework1 = array_filter($comparisonDetails, fn($d) => !$d['mapped']);

        // Calculate bidirectional coverage
        $bidirectionalCoverage = $this->mappingRepository->calculateBidirectionalCoverage($framework1, $framework2);

        // Calculate category-specific coverage (both directions)
        $categoryCoverageF1toF2 = $this->mappingRepository->calculateCategoryCoverage($framework1, $framework2);
        $categoryCoverageF2toF1 = $this->mappingRepository->calculateCategoryCoverage($framework2, $framework1);

        // Calculate mapping quality distribution
        $qualityDistribution = [
            'excellent' => 0,  // 90-100%
            'good' => 0,       // 70-89%
            'medium' => 0,     // 50-69%
            'weak' => 0,       // <50%
        ];
        $qualitySum = 0;
        $qualityCount = 0;

        foreach ($comparisonDetails as $detail) {
            if ($detail['mapped'] && $detail['matchQuality'] !== null) {
                $quality = $detail['matchQuality'];
                $qualitySum += $quality;
                $qualityCount++;

                if ($quality >= 90) {
                    $qualityDistribution['excellent']++;
                } elseif ($quality >= 70) {
                    $qualityDistribution['good']++;
                } elseif ($quality >= 50) {
                    $qualityDistribution['medium']++;
                } else {
                    $qualityDistribution['weak']++;
                }
            }
        }

        $avgMappingQuality = $qualityCount > 0 ? round($qualitySum / $qualityCount, 1) : 0;

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from generation date (Format: Year.Month.Day)
        $pdfGenerationDate = new \DateTime();
        $version = $pdfGenerationDate->format('Y.m.d');

        // Generate PDF
        $pdfContent = $this->pdfExportService->generatePdf('pdf/framework_comparison_report.html.twig', [
            'framework1' => $framework1,
            'framework2' => $framework2,
            'comparison_details' => $comparisonDetails,
            'framework1_count' => $framework1Count,
            'framework2_count' => $framework2Count,
            'mapped_count' => $mappedCount,
            'overlap_percentage' => $overlapPercentage,
            'high_quality_mappings' => $highQualityMappings,
            'unmapped' => $unmapped,
            'unique_framework1' => $uniqueFramework1,
            // New bidirectional coverage metrics
            'bidirectional_coverage' => $bidirectionalCoverage,
            'coverage_f1_to_f2' => $bidirectionalCoverage['framework1_to_framework2']['coverage_percentage'],
            'coverage_f2_to_f1' => $bidirectionalCoverage['framework2_to_framework1']['coverage_percentage'],
            'bidirectional_overlap' => $bidirectionalCoverage['bidirectional_overlap'],
            'symmetric_coverage' => $bidirectionalCoverage['symmetric_coverage'],
            // Category coverage
            'category_coverage_f1_to_f2' => $categoryCoverageF1toF2,
            'category_coverage_f2_to_f1' => $categoryCoverageF2toF1,
            // Mapping quality distribution
            'quality_distribution' => $qualityDistribution,
            'avg_mapping_quality' => $avgMappingQuality,
            'pdf_generation_date' => $pdfGenerationDate,
            'version' => $version,
        ]);

        $filename = sprintf(
            'framework_comparison_%s_vs_%s_%s.pdf',
            $framework1->getCode(),
            $framework2->getCode(),
            date('Y-m-d_His')
        );

        $response = new Response($pdfContent);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', strlen($pdfContent));

        return $response;
    }

    #[Route('/frameworks/create-comparison-mappings', name: 'app_compliance_create_comparison_mappings', methods: ['POST'])]
    public function createComparisonMappings(Request $request): JsonResponse
    {
        // Validate CSRF token
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('create_mappings', $token))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid CSRF token'
            ], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $framework1Id = $data['framework1_id'] ?? null;
            $framework2Id = $data['framework2_id'] ?? null;

            if (!$framework1Id || !$framework2Id) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Beide Framework IDs müssen angegeben werden!'
                ], 400);
            }

            if ($framework1Id === $framework2Id) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Die beiden Frameworks müssen unterschiedlich sein!'
                ], 400);
            }

            $em = $this->frameworkRepository->getEntityManager();

            // Load frameworks
            $framework1 = $this->frameworkRepository->find($framework1Id);
            $framework2 = $this->frameworkRepository->find($framework2Id);

            if (!$framework1 || !$framework2) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Ein oder beide Frameworks wurden nicht gefunden!'
                ], 404);
            }

            // Check if ISO 27001 exists (needed for transitive mappings)
            $iso27001 = $this->frameworkRepository->findOneBy(['code' => 'ISO27001']);

            // Load existing mappings to avoid duplicates (incremental approach)
            $existingMappings = $this->mappingRepository->findAll();
            $existingPairs = [];
            foreach ($existingMappings as $mapping) {
                $sourceId = $mapping->getSourceRequirement()->getId();
                $targetId = $mapping->getTargetRequirement()->getId();
                $existingPairs[$sourceId . '-' . $targetId] = true;
            }

            $mappingsCreated = 0;
            $mappingsSkipped = 0;
            $createdPairs = [];

            // Get requirements for both frameworks
            $requirements1 = $this->requirementRepository->findBy(['framework' => $framework1]);
            $requirements2 = $this->requirementRepository->findBy(['framework' => $framework2]);

            // Strategy 1: Direct mapping via ISO controls if available
            if ($iso27001) {
                // Build a map of ISO control IDs to requirements for both frameworks
                $framework1IsoMap = [];
                $framework2IsoMap = [];

                foreach ($requirements1 as $req) {
                    $dataSourceMapping = $req->getDataSourceMapping();
                    if (!empty($dataSourceMapping['iso_controls'])) {
                        $isoControls = is_array($dataSourceMapping['iso_controls'])
                            ? $dataSourceMapping['iso_controls']
                            : [$dataSourceMapping['iso_controls']];

                        foreach ($isoControls as $controlId) {
                            $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $controlId);
                            if (!isset($framework1IsoMap[$normalizedId])) {
                                $framework1IsoMap[$normalizedId] = [];
                            }
                            $framework1IsoMap[$normalizedId][] = $req;
                        }
                    }
                }

                foreach ($requirements2 as $req) {
                    $dataSourceMapping = $req->getDataSourceMapping();
                    if (!empty($dataSourceMapping['iso_controls'])) {
                        $isoControls = is_array($dataSourceMapping['iso_controls'])
                            ? $dataSourceMapping['iso_controls']
                            : [$dataSourceMapping['iso_controls']];

                        foreach ($isoControls as $controlId) {
                            $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $controlId);
                            if (!isset($framework2IsoMap[$normalizedId])) {
                                $framework2IsoMap[$normalizedId] = [];
                            }
                            $framework2IsoMap[$normalizedId][] = $req;
                        }
                    }
                }

                // Create mappings for requirements sharing the same ISO control
                foreach ($framework1IsoMap as $isoControl => $reqs1) {
                    if (isset($framework2IsoMap[$isoControl])) {
                        $reqs2 = $framework2IsoMap[$isoControl];

                        foreach ($reqs1 as $req1) {
                            foreach ($reqs2 as $req2) {
                                $pairKey = $req1->getId() . '-' . $req2->getId();
                                $reversePairKey = $req2->getId() . '-' . $req1->getId();

                                if (!isset($createdPairs[$pairKey]) && !isset($createdPairs[$reversePairKey])
                                    && !isset($existingPairs[$pairKey]) && !isset($existingPairs[$reversePairKey])) {

                                    // Forward mapping
                                    $mapping = new ComplianceMapping();
                                    $mapping->setSourceRequirement($req1)
                                        ->setTargetRequirement($req2)
                                        ->setMappingPercentage(80)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Mapped via shared ISO 27001 control %s',
                                            $isoControl
                                        ));

                                    $em->persist($mapping);
                                    $mappingsCreated++;
                                    $createdPairs[$pairKey] = true;

                                    // Reverse mapping
                                    $reverseMapping = new ComplianceMapping();
                                    $reverseMapping->setSourceRequirement($req2)
                                        ->setTargetRequirement($req1)
                                        ->setMappingPercentage(80)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Mapped via shared ISO 27001 control %s',
                                            $isoControl
                                        ));

                                    $em->persist($reverseMapping);
                                    $mappingsCreated++;
                                    $createdPairs[$reversePairKey] = true;
                                } elseif (isset($existingPairs[$pairKey]) || isset($existingPairs[$reversePairKey])) {
                                    $mappingsSkipped += 2;
                                }
                            }
                        }
                    }
                }

                // Strategy 2: Direct mapping when one framework IS ISO 27001
                $isFramework1Iso = $framework1->getCode() === 'ISO27001';
                $isFramework2Iso = $framework2->getCode() === 'ISO27001';

                if ($isFramework1Iso || $isFramework2Iso) {
                    // Determine which is ISO and which has iso_controls
                    $isoFramework = $isFramework1Iso ? $framework1 : $framework2;
                    $otherFramework = $isFramework1Iso ? $framework2 : $framework1;
                    $isoRequirements = $isFramework1Iso ? $requirements1 : $requirements2;
                    $otherRequirements = $isFramework1Iso ? $requirements2 : $requirements1;

                    // Build map of other framework's requirements by ISO control
                    $otherByIsoControl = [];
                    foreach ($otherRequirements as $req) {
                        $dataSourceMapping = $req->getDataSourceMapping();
                        if (!empty($dataSourceMapping['iso_controls'])) {
                            $isoControls = is_array($dataSourceMapping['iso_controls'])
                                ? $dataSourceMapping['iso_controls']
                                : [$dataSourceMapping['iso_controls']];

                            foreach ($isoControls as $controlId) {
                                $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $controlId);
                                if (!isset($otherByIsoControl[$normalizedId])) {
                                    $otherByIsoControl[$normalizedId] = [];
                                }
                                $otherByIsoControl[$normalizedId][] = $req;
                            }
                        }
                    }

                    // Map ISO requirements directly to other framework requirements
                    foreach ($isoRequirements as $isoReq) {
                        $isoControlId = $isoReq->getRequirementId(); // e.g., 'A.5.1'

                        if (isset($otherByIsoControl[$isoControlId])) {
                            $otherReqs = $otherByIsoControl[$isoControlId];

                            foreach ($otherReqs as $otherReq) {
                                $pairKey = $isoReq->getId() . '-' . $otherReq->getId();
                                $reversePairKey = $otherReq->getId() . '-' . $isoReq->getId();

                                if (!isset($createdPairs[$pairKey]) && !isset($createdPairs[$reversePairKey])
                                    && !isset($existingPairs[$pairKey]) && !isset($existingPairs[$reversePairKey])) {

                                    // Forward mapping: ISO → Other
                                    $mapping = new ComplianceMapping();
                                    $mapping->setSourceRequirement($isoReq)
                                        ->setTargetRequirement($otherReq)
                                        ->setMappingPercentage(90)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Direct mapping: ISO 27001 %s to %s requirement',
                                            $isoControlId,
                                            $otherFramework->getName()
                                        ));

                                    $em->persist($mapping);
                                    $mappingsCreated++;
                                    $createdPairs[$pairKey] = true;

                                    // Reverse mapping: Other → ISO
                                    $reverseMapping = new ComplianceMapping();
                                    $reverseMapping->setSourceRequirement($otherReq)
                                        ->setTargetRequirement($isoReq)
                                        ->setMappingPercentage(90)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Direct mapping: %s requirement to ISO 27001 %s',
                                            $otherFramework->getName(),
                                            $isoControlId
                                        ));

                                    $em->persist($reverseMapping);
                                    $mappingsCreated++;
                                    $createdPairs[$reversePairKey] = true;
                                } elseif (isset($existingPairs[$pairKey]) || isset($existingPairs[$reversePairKey])) {
                                    $mappingsSkipped += 2;
                                }
                            }
                        }
                    }
                }
            }

            $em->flush();

            $message = sprintf(
                'Erfolgreich %d neue Mappings zwischen %s und %s erstellt!',
                $mappingsCreated,
                $framework1->getName(),
                $framework2->getName()
            );
            if ($mappingsSkipped > 0) {
                $message .= sprintf(' (%d bereits vorhanden, übersprungen)', $mappingsSkipped);
            }

            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'mappings_created' => $mappingsCreated,
                'mappings_skipped' => $mappingsSkipped,
                'framework1' => $framework1->getName(),
                'framework2' => $framework2->getName(),
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Erstellen der Mappings: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/frameworks/create-mappings', name: 'app_compliance_create_mappings', methods: ['POST'])]
    public function createCrossFrameworkMappings(Request $request): JsonResponse
    {
        // Validate CSRF token
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('create_mappings', $token))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid CSRF token'
            ], 403);
        }

        try {
            // Get batch parameters for chunking
            $data = json_decode($request->getContent(), true) ?? [];
            $currentBatch = $data['batch'] ?? 0;
            $batchSize = $data['batch_size'] ?? 50; // Process 50 mappings per batch

            $em = $this->frameworkRepository->getEntityManager();

            // Check if ISO 27001 exists
            $iso27001 = $this->frameworkRepository->findOneBy(['code' => 'ISO27001']);
            if (!$iso27001) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'ISO 27001 Framework muss zuerst geladen werden!'
                ]);
            }

            // Get all frameworks
            $frameworks = $this->frameworkRepository->findAll();
            if (count($frameworks) < 2) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Mindestens 2 Frameworks müssen geladen sein!'
                ]);
            }

            // Load existing mappings to avoid duplicates (incremental approach)
            $existingMappings = $this->mappingRepository->findAll();
            $existingPairs = [];
            foreach ($existingMappings as $mapping) {
                $sourceId = $mapping->getSourceRequirement()->getId();
                $targetId = $mapping->getTargetRequirement()->getId();
                $existingPairs[$sourceId . '-' . $targetId] = true;
            }

            $mappingsCreated = 0;
            $mappingsSkipped = 0;
            $createdPairs = []; // Track created mapping pairs to avoid duplicates
            $potentialMappings = []; // Collect all potential mappings first

            // 1. Collect potential mappings FROM other frameworks TO ISO 27001
            foreach ($frameworks as $framework) {
                if ($framework->getCode() === 'ISO27001') {
                    continue;
                }

                $requirements = $this->requirementRepository->findBy(['framework' => $framework]);

                foreach ($requirements as $requirement) {
                    $dataSourceMapping = $requirement->getDataSourceMapping();
                    if (empty($dataSourceMapping) || empty($dataSourceMapping['iso_controls'])) {
                        continue;
                    }

                    $isoControls = $dataSourceMapping['iso_controls'];
                    if (!is_array($isoControls)) {
                        $isoControls = [$isoControls];
                    }

                    foreach ($isoControls as $controlId) {
                        $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $controlId);

                        $isoRequirement = $this->requirementRepository->findOneBy([
                            'framework' => $iso27001,
                            'requirementId' => $normalizedId
                        ]);

                        if ($isoRequirement) {
                            $pairKey = $requirement->getId() . '-' . $isoRequirement->getId();
                            $reversePairKey = $isoRequirement->getId() . '-' . $requirement->getId();

                            if (!isset($existingPairs[$pairKey])) {
                                $potentialMappings[] = [
                                    'type' => 'forward',
                                    'source' => $requirement,
                                    'target' => $isoRequirement,
                                    'pairKey' => $pairKey,
                                    'framework' => $framework,
                                    'controlId' => $normalizedId
                                ];
                            }

                            if (!isset($existingPairs[$reversePairKey])) {
                                $potentialMappings[] = [
                                    'type' => 'reverse',
                                    'source' => $isoRequirement,
                                    'target' => $requirement,
                                    'pairKey' => $reversePairKey,
                                    'framework' => $framework,
                                    'controlId' => $normalizedId
                                ];
                            }
                        }
                    }
                }
            }

            // 2. Collect transitive mappings between non-ISO frameworks
            // If Framework A → ISO Control X and Framework B → ISO Control X, then A ↔ B
            $isoRequirements = $this->requirementRepository->findBy(['framework' => $iso27001]);

            foreach ($isoRequirements as $isoReq) {
                // Find all frameworks that map to this ISO requirement
                $mappedToThisISO = [];

                foreach ($frameworks as $framework) {
                    if ($framework->getCode() === 'ISO27001') {
                        continue;
                    }

                    $requirements = $this->requirementRepository->findBy(['framework' => $framework]);

                    foreach ($requirements as $req) {
                        $dataSourceMapping = $req->getDataSourceMapping();
                        if (empty($dataSourceMapping) || empty($dataSourceMapping['iso_controls'])) {
                            continue;
                        }

                        $isoControls = $dataSourceMapping['iso_controls'];
                        if (!is_array($isoControls)) {
                            $isoControls = [$isoControls];
                        }

                        foreach ($isoControls as $controlId) {
                            $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $controlId);

                            if ($normalizedId === $isoReq->getRequirementId()) {
                                $mappedToThisISO[] = $req;
                            }
                        }
                    }
                }

                // Collect cross-mappings between all requirements that map to same ISO control
                for ($i = 0; $i < count($mappedToThisISO); $i++) {
                    for ($j = $i + 1; $j < count($mappedToThisISO); $j++) {
                        $req1 = $mappedToThisISO[$i];
                        $req2 = $mappedToThisISO[$j];

                        $pairKey = $req1->getId() . '-' . $req2->getId();
                        $reversePairKey = $req2->getId() . '-' . $req1->getId();

                        if (!isset($existingPairs[$pairKey]) && !isset($existingPairs[$reversePairKey])) {
                            $potentialMappings[] = [
                                'type' => 'transitive_forward',
                                'source' => $req1,
                                'target' => $req2,
                                'pairKey' => $pairKey,
                                'isoControl' => $isoReq->getRequirementId()
                            ];

                            $potentialMappings[] = [
                                'type' => 'transitive_reverse',
                                'source' => $req2,
                                'target' => $req1,
                                'pairKey' => $reversePairKey,
                                'isoControl' => $isoReq->getRequirementId()
                            ];
                        }
                    }
                }
            }

            // 3. Process mappings in batches to avoid timeouts
            $totalPotentialMappings = count($potentialMappings);
            $startIndex = $currentBatch * $batchSize;
            $endIndex = min($startIndex + $batchSize, $totalPotentialMappings);
            $hasMore = $endIndex < $totalPotentialMappings;

            // Process only the current batch
            for ($i = $startIndex; $i < $endIndex; $i++) {
                $mappingData = $potentialMappings[$i];

                // Skip if already created in this session
                if (isset($createdPairs[$mappingData['pairKey']])) {
                    $mappingsSkipped++;
                    continue;
                }

                $mapping = new ComplianceMapping();
                $mapping->setSourceRequirement($mappingData['source'])
                    ->setTargetRequirement($mappingData['target'])
                    ->setBidirectional(true);

                // Set type-specific properties
                if (str_starts_with($mappingData['type'], 'transitive')) {
                    $mapping->setMappingPercentage(75)
                        ->setMappingType('partial')
                        ->setConfidence('medium')
                        ->setMappingRationale(sprintf(
                            'Transitive mapping via ISO 27001 %s',
                            $mappingData['isoControl']
                        ));
                } else {
                    $mapping->setMappingPercentage(85)
                        ->setMappingType('partial')
                        ->setConfidence('high')
                        ->setMappingRationale(sprintf(
                            '%s requirement mapped to ISO 27001 %s',
                            $mappingData['framework']->getCode(),
                            $mappingData['controlId']
                        ));
                }

                $em->persist($mapping);
                $mappingsCreated++;
                $createdPairs[$mappingData['pairKey']] = true;
            }

            $em->flush();
            $em->clear(); // Clear entity manager to free memory

            // Debug info
            $frameworkCounts = [];
            foreach ($frameworks as $fw) {
                $reqCount = count($this->requirementRepository->findBy(['framework' => $fw]));
                $frameworkCounts[$fw->getCode()] = $reqCount;
            }

            $processedSoFar = min($endIndex, $totalPotentialMappings);
            $remaining = max(0, $totalPotentialMappings - $processedSoFar);
            $progressPercent = $totalPotentialMappings > 0
                ? round(($processedSoFar / $totalPotentialMappings) * 100, 1)
                : 100;

            $message = sprintf(
                'Batch %d: %d Mappings erstellt',
                $currentBatch + 1,
                $mappingsCreated
            );
            if ($hasMore) {
                $message .= sprintf(' (%d%% - %d/%d verarbeitet, %d verbleibend)',
                    $progressPercent,
                    $processedSoFar,
                    $totalPotentialMappings,
                    $remaining
                );
            } else {
                $message .= ' - Alle Mappings erstellt!';
            }

            // Additional debug info to help diagnose mapping issues
            $debugInfo = [
                'frameworks_loaded' => count($frameworks),
                'framework_details' => $frameworkCounts,
                'batch_info' => [
                    'current_batch' => $currentBatch,
                    'batch_size' => $batchSize,
                    'total_potential_mappings' => $totalPotentialMappings,
                    'processed_so_far' => $processedSoFar,
                    'remaining' => $remaining,
                    'progress_percent' => $progressPercent,
                    'has_more' => $hasMore,
                    'next_batch' => $hasMore ? $currentBatch + 1 : null,
                ],
            ];

            // Check if TISAX exists and has ISO controls
            $tisax = $this->frameworkRepository->findOneBy(['code' => 'TISAX']);
            if ($tisax) {
                $tisaxReqs = $this->requirementRepository->findBy(['framework' => $tisax]);
                $tisaxWithIsoControls = 0;
                $sampleIsoControls = [];

                foreach ($tisaxReqs as $req) {
                    $dataSourceMapping = $req->getDataSourceMapping();
                    if (!empty($dataSourceMapping['iso_controls'])) {
                        $tisaxWithIsoControls++;
                        if (count($sampleIsoControls) < 5) {
                            $sampleIsoControls[] = [
                                'requirement_id' => $req->getRequirementId(),
                                'iso_controls' => $dataSourceMapping['iso_controls']
                            ];
                        }
                    }
                }

                $debugInfo['tisax'] = [
                    'total_requirements' => count($tisaxReqs),
                    'with_iso_controls' => $tisaxWithIsoControls,
                    'sample_iso_controls' => $sampleIsoControls,
                ];
            }

            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'mappings_created' => $mappingsCreated,
                'mappings_skipped' => $mappingsSkipped,
                'mappings_total' => $mappingsCreated + $mappingsSkipped,
                'has_more' => $hasMore,
                'next_batch' => $hasMore ? $currentBatch + 1 : null,
                'progress' => [
                    'total' => $totalPotentialMappings,
                    'processed' => $processedSoFar,
                    'remaining' => $remaining,
                    'percent' => $progressPercent,
                ],
                'debug' => $debugInfo
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Erstellen der Mappings: ' . $e->getMessage()
            ], 500);
        }
    }
}
