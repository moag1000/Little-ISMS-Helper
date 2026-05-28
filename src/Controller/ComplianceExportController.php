<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\LocalizedFlashTrait;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use DateTime;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceMappingRepository;
use App\Service\ComplianceAssessmentService;
use App\Service\ComplianceMappingService;
use App\Service\ComplianceRequirementFulfillmentService;
use App\Service\ExcelExportService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Util\CsvSanitizer;

/**
 * ComplianceExportController
 *
 * Handles all compliance export operations (CSV, Excel, PDF) for:
 * - Data-reuse insights exports
 * - Gap analysis exports
 * - Transitive compliance exports
 * - Framework comparison exports
 *
 * Extracted from ComplianceController god-class (was 2629 LOC).
 */
class ComplianceExportController extends AbstractController
{
    use LocalizedFlashTrait;

    public function __construct(
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly ComplianceMappingRepository $complianceMappingRepository,
        private readonly ComplianceAssessmentService $complianceAssessmentService,
        private readonly ComplianceMappingService $complianceMappingService,
        private readonly ExcelExportService $excelExportService,
        private readonly PdfExportService $pdfExportService,
        private readonly ComplianceRequirementFulfillmentService $complianceRequirementFulfillmentService,
        private readonly TenantContext $tenantContext,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    protected function getFlashDomain(): string
    {
        return 'compliance';
    }

    protected function getTranslator(): TranslatorInterface
    {
        if ($this->translator === null) {
            throw new \RuntimeException('TranslatorInterface not injected — flash methods unavailable.');
        }
        return $this->translator;
    }

    // -------------------------------------------------------------------------
    // Data-Reuse Exports
    // -------------------------------------------------------------------------

    #[Route('/compliance/framework/{id}/data-reuse/export', name: 'app_compliance_export_reuse', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportDataReuse(Request $request, int $id): Response
    {
        $framework = $this->complianceFrameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $requirements = $this->complianceRequirementRepository->findApplicableByFramework($framework);
        $dataReuseAnalysis = [];
        $totalTimeSavings = 0;

        foreach ($requirements as $requirement) {
            $analysis = $this->complianceMappingService->getDataReuseAnalysis($requirement);
            $reuseValue = $this->complianceMappingService->calculateDataReuseValue($requirement);

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
        foreach ($dataReuseAnalysis as $dataReuseAnalysi) {
            $requirement = $dataReuseAnalysi['requirement'];
            $value = $dataReuseAnalysi['value'];
            $analysis = $dataReuseAnalysi['analysis'];

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
                empty($analysis['reusable_data']) ? 0 : count($analysis['reusable_data']),
                $reusableDataSources === [] ? '-' : implode(', ', $reusableDataSources),
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
            fputcsv($handle, array_map([CsvSanitizer::class, 'sanitize'], $row), ';', escape: '\\');
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/compliance/framework/{id}/data-reuse/export/excel', name: 'app_compliance_export_reuse_excel', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportDataReuseExcel(Request $request, int $id): Response
    {
        $framework = $this->complianceFrameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $requirements = $this->complianceRequirementRepository->findApplicableByFramework($framework);
        $dataReuseAnalysis = [];
        $totalTimeSavings = 0;

        foreach ($requirements as $requirement) {
            $analysis = $this->complianceMappingService->getDataReuseAnalysis($requirement);
            $reuseValue = $this->complianceMappingService->calculateDataReuseValue($requirement);

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
        $te = $this->getTranslator();

        // Tab 1: Summary
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle($te->trans('export.section.summary', [], 'compliance'));

        $metrics = [
            $te->trans('export.column.framework', [], 'compliance') => $framework->getName() . ' (' . $framework->getCode() . ')',
            $te->trans('export.column.analyzed_requirements', [], 'compliance') => count($dataReuseAnalysis),
            $te->trans('export.column.time_savings_hours', [], 'compliance') => round($totalTimeSavings, 1),
            $te->trans('export.column.time_savings_days', [], 'compliance') => round($totalTimeSavings / 8, 1),
            $te->trans('export.column.export_date', [], 'compliance') => date('d.m.Y H:i'),
        ];

        $this->excelExportService->addSummarySection($worksheet, $metrics, 1, 'Data Reuse Insights');
        $this->excelExportService->autoSizeColumns($worksheet);

        // Tab 2: Details
        $detailsSheet = $this->excelExportService->createSheet($spreadsheet, $te->trans('export.sheet.reuse_details', [], 'compliance'));

        $headers = ['ID', $te->trans('export.column.title', [], 'compliance'), $te->trans('export.column.category', [], 'compliance'), $te->trans('export.column.reusable_data', [], 'compliance'), $te->trans('export.column.sources', [], 'compliance'), $te->trans('export.column.time_savings_h', [], 'compliance'), 'Reuse %', $te->trans('export.column.confidence', [], 'compliance')];
        $this->excelExportService->addFormattedHeaderRow($detailsSheet, $headers, 1, true);

        $data = [];
        foreach ($dataReuseAnalysis as $dataReuseAnalysi) {
            $requirement = $dataReuseAnalysi['requirement'];
            $value = $dataReuseAnalysi['value'];
            $analysis = $dataReuseAnalysi['analysis'];

            $reusableDataSources = [];
            if (!empty($analysis['reusable_data'])) {
                foreach ($analysis['reusable_data'] as $reuseItem) {
                    $reusableDataSources[] = $reuseItem['source'] ?? 'Unknown';
                }
            }

            $data[] = [
                $requirement->getRequirementId(),
                $requirement->getTitle(),
                $requirement->getCategory() ?? '-',
                empty($analysis['reusable_data']) ? 0 : count($analysis['reusable_data']),
                $reusableDataSources === [] ? '-' : implode(', ', array_slice($reusableDataSources, 0, 3)),
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

    #[Route('/compliance/framework/{id}/data-reuse/export/pdf', name: 'app_compliance_export_reuse_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportDataReusePdf(Request $request, int $id): Response
    {
        $framework = $this->complianceFrameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $requirements = $this->complianceRequirementRepository->findApplicableByFramework($framework);
        $dataReuseAnalysis = [];
        $totalTimeSavings = 0;
        $totalReusePercentage = 0;

        foreach ($requirements as $requirement) {
            $analysis = $this->complianceMappingService->getDataReuseAnalysis($requirement);
            $reuseValue = $this->complianceMappingService->calculateDataReuseValue($requirement);

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
        $pdfGenerationDate = new DateTime();
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
        $response->headers->set('Content-Length', (string) strlen($pdfContent));

        return $response;
    }

    // -------------------------------------------------------------------------
    // Gap Analysis Exports
    // -------------------------------------------------------------------------

    #[Route('/compliance/framework/{id}/gaps/export', name: 'app_compliance_export_gaps', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportGaps(Request $request, int $id): Response
    {
        $framework = $this->complianceFrameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $gaps = $this->complianceRequirementRepository->findGapsByFramework($framework, 75, $tenant);
        $requirements = $this->complianceRequirementRepository->findByFramework($framework);
        $metRequirements = count($requirements) - count($gaps);

        // Analyze each gap
        $gapAnalysis = [];
        foreach ($gaps as $gap) {
            $analysis = $this->complianceAssessmentService->assessRequirement($gap);
            $gapAnalysis[] = [
                'requirement' => $gap,
                'analysis' => $analysis,
            ];
        }

        // Close session to prevent blocking other requests during CSV generation
        $request->getSession()->save();

        // Create CSV content
        $csv = [];
        $tg = $this->getTranslator();

        // Priority and status maps for data rows
        $priorityMap = [
            'critical' => $tg->trans('export.label.critical', [], 'compliance'),
            'high'     => $tg->trans('export.label.high', [], 'compliance'),
            'medium'   => $tg->trans('export.label.medium', [], 'compliance'),
            'low'      => $tg->trans('export.label.low', [], 'compliance'),
        ];
        $statusMap = [
            'not_applicable'       => $tg->trans('export.status.not_applicable', [], 'compliance'),
            'not_implemented'      => $tg->trans('export.status.not_implemented', [], 'compliance'),
            'partially_implemented'=> $tg->trans('export.status.partially_implemented', [], 'compliance'),
            'implemented'          => $tg->trans('export.status.implemented', [], 'compliance'),
            'not_assessed'         => $tg->trans('export.status.not_assessed', [], 'compliance'),
        ];

        // CSV Header - Title
        $csv[] = ['Gap Analysis - ' . $framework->getName()];
        $csv[] = [];

        // Summary section
        $csv[] = [$tg->trans('export.section.summary', [], 'compliance')];
        $csv[] = [$tg->trans('export.column.framework', [], 'compliance'), $framework->getName() . ' (' . $framework->getCode() . ')'];
        $csv[] = [$tg->trans('export.column.total_requirements', [], 'compliance'), count($requirements)];
        $csv[] = [$tg->trans('export.column.met_requirements', [], 'compliance'), $metRequirements];
        $csv[] = [$tg->trans('export.column.identified_gaps', [], 'compliance'), count($gaps)];
        $complianceScore = count($requirements) > 0 ? round(($metRequirements / count($requirements)) * 100, 2) : 0;
        $csv[] = [$tg->trans('export.column.compliance_score_pct', [], 'compliance'), $complianceScore];
        $csv[] = [];

        // Gap severity breakdown
        $criticalCount = 0;
        $highCount = 0;
        $mediumCount = 0;
        $lowCount = 0;

        foreach ($gapAnalysis as $item) {
            $priority = $item['requirement']->getPriority() ?? 'low';
            match ($priority) {
                'critical' => $criticalCount++,
                'high' => $highCount++,
                'medium' => $mediumCount++,
                default => $lowCount++,
            };
        }

        $csv[] = [$tg->trans('export.section.gaps_by_severity', [], 'compliance')];
        $csv[] = [$tg->trans('export.label.critical', [], 'compliance'), $criticalCount];
        $csv[] = [$tg->trans('export.label.high', [], 'compliance'), $highCount];
        $csv[] = [$tg->trans('export.label.medium', [], 'compliance'), $mediumCount];
        $csv[] = [$tg->trans('export.label.low', [], 'compliance'), $lowCount];
        $csv[] = [];

        // CSV Header - Gaps
        $csv[] = [
            $tg->trans('export.column.requirement_id', [], 'compliance'),
            $tg->trans('export.column.title', [], 'compliance'),
            $tg->trans('export.column.category', [], 'compliance'),
            $tg->trans('export.column.description', [], 'compliance'),
            $tg->trans('export.column.priority_severity', [], 'compliance'),
            $tg->trans('export.column.status', [], 'compliance'),
            $tg->trans('export.column.fulfillment_pct', [], 'compliance'),
            $tg->trans('export.column.gap_reason', [], 'compliance'),
        ];

        // CSV Data - Gaps
        foreach ($gapAnalysis as $gapAnalysi) {
            $requirement = $gapAnalysi['requirement'];
            $analysis = $gapAnalysi['analysis'];

            $csv[] = [
                $requirement->getRequirementId(),
                $requirement->getTitle(),
                $requirement->getCategory() ?? '-',
                $requirement->getDescription() ?? '-',
                $priorityMap[$requirement->getPriority() ?? 'low'] ?? $tg->trans('export.label.low', [], 'compliance'),
                $statusMap[$requirement->getStatus() ?? 'not_assessed'] ?? $tg->trans('export.status.not_assessed', [], 'compliance'),
                $requirement->getFulfillmentPercentage() ?? 0,
                $analysis['gap_reason'] ?? $tg->trans('export.label.not_met', [], 'compliance'),
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
            fputcsv($handle, array_map([CsvSanitizer::class, 'sanitize'], $row), ';', escape: '\\');
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/compliance/framework/{id}/gaps/export/excel', name: 'app_compliance_export_gaps_excel', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportGapsExcel(Request $request, int $id): Response
    {
        $framework = $this->complianceFrameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $gaps = $this->complianceRequirementRepository->findGapsByFramework($framework, 75, $tenant);
        $requirements = $this->complianceRequirementRepository->findByFramework($framework);
        $metRequirements = count($requirements) - count($gaps);

        // Analyze gaps
        $gapAnalysis = [];
        $severityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($gaps as $gap) {
            $analysis = $this->complianceAssessmentService->assessRequirement($gap);
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
        $tge = $this->getTranslator();

        // Priority and status maps (outside loop for performance)
        $priorityMapExcel = [
            'critical' => $tge->trans('export.label.critical', [], 'compliance'),
            'high'     => $tge->trans('export.label.high', [], 'compliance'),
            'medium'   => $tge->trans('export.label.medium', [], 'compliance'),
            'low'      => $tge->trans('export.label.low', [], 'compliance'),
        ];
        $statusMapExcel = [
            'not_applicable'        => $tge->trans('export.status.not_applicable', [], 'compliance'),
            'not_implemented'       => $tge->trans('export.status.not_implemented', [], 'compliance'),
            'partially_implemented' => $tge->trans('export.status.partially_implemented_short', [], 'compliance'),
            'implemented'           => $tge->trans('export.status.implemented', [], 'compliance'),
            'not_assessed'          => $tge->trans('export.status.not_assessed', [], 'compliance'),
        ];

        // Tab 1: Summary
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle($tge->trans('export.section.summary', [], 'compliance'));

        $complianceScore = count($requirements) > 0 ? round(($metRequirements / count($requirements)) * 100, 1) : 0;

        $metrics = [
            $tge->trans('export.column.framework', [], 'compliance') => $framework->getName() . ' (' . $framework->getCode() . ')',
            $tge->trans('export.column.total_requirements', [], 'compliance') => count($requirements),
            $tge->trans('export.column.met_requirements', [], 'compliance') => $metRequirements,
            $tge->trans('export.column.identified_gaps', [], 'compliance') => count($gaps),
            $tge->trans('export.column.compliance_score', [], 'compliance') => $complianceScore . '%',
            $tge->trans('export.column.export_date', [], 'compliance') => date('d.m.Y H:i'),
        ];

        $nextRow = $this->excelExportService->addSummarySection($worksheet, $metrics, 1, 'Gap Analysis');

        // Severity breakdown
        $severityMetrics = [
            $tge->trans('export.label.critical_gaps', [], 'compliance') => $severityCounts['critical'],
            $tge->trans('export.label.high_gaps', [], 'compliance') => $severityCounts['high'],
            $tge->trans('export.label.medium_gaps', [], 'compliance') => $severityCounts['medium'],
            $tge->trans('export.label.low_gaps', [], 'compliance') => $severityCounts['low'],
        ];
        $this->excelExportService->addSummarySection($worksheet, $severityMetrics, $nextRow, 'Severity Breakdown');
        $this->excelExportService->autoSizeColumns($worksheet);

        // Tab 2: Gap Details
        $detailsSheet = $this->excelExportService->createSheet($spreadsheet, $tge->trans('export.sheet.gap_details', [], 'compliance'));

        $headers = ['ID', $tge->trans('export.column.title', [], 'compliance'), $tge->trans('export.column.category', [], 'compliance'), $tge->trans('export.column.priority_severity', [], 'compliance'), $tge->trans('export.column.status', [], 'compliance'), $tge->trans('export.column.fulfillment_pct', [], 'compliance'), $tge->trans('export.column.gap_reason', [], 'compliance')];
        $this->excelExportService->addFormattedHeaderRow($detailsSheet, $headers, 1, true);

        $data = [];
        foreach ($gapAnalysis as $gapAnalysi) {
            $requirement = $gapAnalysi['requirement'];
            $analysis = $gapAnalysi['analysis'];

            $data[] = [
                $requirement->getRequirementId(),
                $requirement->getTitle(),
                $requirement->getCategory() ?? '-',
                $priorityMapExcel[$requirement->getPriority() ?? 'low'] ?? $tge->trans('export.label.low', [], 'compliance'),
                $statusMapExcel[$requirement->getStatus() ?? 'not_assessed'] ?? '-',
                $requirement->getFulfillmentPercentage() ?? 0,
                substr($analysis['gap_reason'] ?? $tge->trans('export.label.not_met', [], 'compliance'), 0, 100),
            ];
        }

        // Conditional formatting
        $conditionalFormatting = [
            3 => [ // Priority
                $tge->trans('export.label.critical', [], 'compliance') => $this->excelExportService->getColor('critical'),
                $tge->trans('export.label.high', [], 'compliance') => $this->excelExportService->getColor('high'),
                $tge->trans('export.label.medium', [], 'compliance') => $this->excelExportService->getColor('medium'),
                $tge->trans('export.label.low', [], 'compliance') => $this->excelExportService->getColor('low'),
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

    #[Route('/compliance/framework/{id}/gaps/export/pdf', name: 'app_compliance_export_gaps_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportGapsPdf(Request $request, int $id): Response
    {
        $framework = $this->complianceFrameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        // Get current tenant for fulfillment data
        $tenant = $this->tenantContext->getCurrentTenant();
        $gaps = $this->complianceRequirementRepository->findGapsByFramework($framework, 75, $tenant);
        $requirements = $this->complianceRequirementRepository->findByFramework($framework);
        $metRequirements = count($requirements) - count($gaps);
        if (!$tenant && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('No tenant assigned to user. Please contact administrator.');
        }

        // Analyze gaps and get fulfillment data
        // For SUPER_ADMIN without tenant, skip fulfillment data
        $gapAnalysis = [];
        $gapFulfillments = [];
        $severityCounts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];

        foreach ($gaps as $gap) {
            $analysis = $this->complianceAssessmentService->assessRequirement($gap);
            $priority = $gap->getPriority() ?? 'low';
            $severityCounts[$priority]++;

            // Get tenant-specific fulfillment for this gap (only if tenant exists)
            $fulfillment = null;
            if ($tenant instanceof Tenant) {
                $fulfillment = $this->complianceRequirementFulfillmentService->getOrCreateFulfillment($tenant, $gap);
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
        $priorityWeightedAnalysis = $this->complianceMappingRepository->calculatePriorityWeightedGaps($framework, $gaps);

        // Perform root cause analysis for gaps
        $rootCauseAnalysis = $this->complianceMappingRepository->analyzeGapRootCauses($gaps);

        // Calculate weighted compliance score (how well critical/high requirements are met)
        $totalRequirements = count($requirements);
        $weightedScore = 0;
        if ($totalRequirements > 0) {
            $priorityWeights = ['critical' => 4.0, 'high' => 2.0, 'medium' => 1.0, 'low' => 0.5];
            $totalPossibleWeight = 0;
            $achievedWeight = 0;

            // BACKLOG: Refactor to use tenant-specific fulfillment percentages.
            // Currently assumes 0% fulfillment for gap requirements and 100% for met ones.
            // A proper implementation would query ComplianceRequirementFulfillment records
            // per tenant and use their actual fulfillmentPercentage values in the weighting.
            // This requires a ComplianceRequirementFulfillmentRepository with tenant scoping.
            foreach ($requirements as $requirement) {
                $priority = $requirement->getPriority() ?? 'medium';
                $weight = $priorityWeights[$priority] ?? 1.0;
                $totalPossibleWeight += $weight;
                // Simplified: assume 0% fulfillment for gaps, 100% for met requirements
                $isGap = in_array($requirement, $gaps, true);
                $achievedWeight += $isGap ? 0 : $weight;
            }

            $weightedScore = $totalPossibleWeight > 0
                ? round(($achievedWeight / $totalPossibleWeight) * 100, 1)
                : 0;
        }

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from generation date (Format: Year.Month.Day)
        $pdfGenerationDate = new DateTime();
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
        $response->headers->set('Content-Length', (string) strlen($pdfContent));

        return $response;
    }

    // -------------------------------------------------------------------------
    // Transitive Compliance Exports
    // -------------------------------------------------------------------------

    #[Route('/compliance/export/transitive', name: 'app_compliance_export_transitive', methods: ['GET'])]
    public function exportTransitive(Request $request): Response
    {
        $frameworks = $this->complianceFrameworkRepository->findActiveFrameworks();
        $transitiveAnalysis = [];
        $frameworkRelationships = [];

        // Build transitive analysis data (same as in transitiveCompliance method)
        foreach ($frameworks as $framework) {
            foreach ($frameworks as $targetFramework) {
                if ($framework->id === $targetFramework->id) {
                    continue;
                }

                // Calculate coverage
                $coverage = $this->complianceMappingRepository->calculateFrameworkCoverage(
                    $framework,
                    $targetFramework
                );

                // Get transitive analysis
                $transitive = $this->complianceMappingRepository->getTransitiveCompliance(
                    $framework,
                    $targetFramework
                );

                if (($transitive['requirements_helped'] ?? 0) > 0) {
                    $transitiveAnalysis[] = $transitive;
                }

                // Get detailed cross-framework mappings
                $mappings = $this->complianceMappingRepository->findCrossFrameworkMappings(
                    $framework,
                    $targetFramework
                );

                if ($mappings !== [] && ($coverage['coverage_percentage'] ?? 0) > 0) {
                    $frameworkRelationships[] = [
                        'sourceFramework' => $framework,
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
        $tt = $this->getTranslator();

        // CSV Header - Framework Relationships
        $csv[] = [$tt->trans('export.section.framework_relationships', [], 'compliance')];
        $csv[] = [];
        $csv[] = [
            $tt->trans('export.column.source_framework', [], 'compliance'),
            $tt->trans('export.column.target_framework', [], 'compliance'),
            $tt->trans('export.column.mapped_requirements', [], 'compliance'),
            $tt->trans('export.column.total_requirements', [], 'compliance'),
            'Coverage (%)',
        ];

        // CSV Data - Framework Relationships
        foreach ($frameworkRelationships as $frameworkRelationship) {
            $csv[] = [
                $frameworkRelationship['sourceFramework']->getName() . ' (' . $frameworkRelationship['sourceFramework']->getCode() . ')',
                $frameworkRelationship['targetFramework']->getName() . ' (' . $frameworkRelationship['targetFramework']->getCode() . ')',
                $frameworkRelationship['mappedRequirements'],
                $frameworkRelationship['totalRequirements'],
                $frameworkRelationship['coveragePercentage'],
            ];
        }

        // Add summary section
        $csv[] = [];
        $csv[] = [$tt->trans('export.section.summary', [], 'compliance')];
        $csv[] = [];
        $csv[] = [$tt->trans('export.column.metric', [], 'compliance'), $tt->trans('export.column.value', [], 'compliance')];
        $csv[] = [$tt->trans('export.column.active_frameworks', [], 'compliance'), count($frameworks)];
        $csv[] = [$tt->trans('export.column.framework_relations', [], 'compliance'), count($frameworkRelationships)];
        $csv[] = [$tt->trans('export.column.transitive_opportunities', [], 'compliance'), count($transitiveAnalysis)];

        if ($transitiveAnalysis !== []) {
            $totalRequirementsHelped = array_sum(array_column($transitiveAnalysis, 'requirements_helped'));
            $csv[] = [$tt->trans('export.column.total_supported_requirements', [], 'compliance'), $totalRequirementsHelped];
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
            fputcsv($handle, array_map([CsvSanitizer::class, 'sanitize'], $row), ';', escape: '\\'); // Use semicolon as delimiter for Excel compatibility
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/compliance/export/transitive/excel', name: 'app_compliance_export_transitive_excel', methods: ['GET'])]
    public function exportTransitiveExcel(Request $request): Response
    {
        $frameworks = $this->complianceFrameworkRepository->findActiveFrameworks();
        $transitiveAnalysis = [];
        $frameworkRelationships = [];

        // Build data
        foreach ($frameworks as $framework) {
            foreach ($frameworks as $targetFramework) {
                if ($framework->id === $targetFramework->id) {
                    continue;
                }

                $coverage = $this->complianceMappingRepository->calculateFrameworkCoverage($framework, $targetFramework);
                $transitive = $this->complianceMappingRepository->getTransitiveCompliance($framework, $targetFramework);

                if (($transitive['requirements_helped'] ?? 0) > 0) {
                    $transitiveAnalysis[] = $transitive;
                }

                $mappings = $this->complianceMappingRepository->findCrossFrameworkMappings($framework, $targetFramework);

                if ($mappings !== [] && ($coverage['coverage_percentage'] ?? 0) > 0) {
                    $frameworkRelationships[] = [
                        'source' => $framework,
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
        $tx = $this->getTranslator();

        // Tab 1: Summary
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle($tx->trans('export.section.summary', [], 'compliance'));

        $totalHelped = $transitiveAnalysis === [] ? 0 : array_sum(array_column($transitiveAnalysis, 'requirements_helped'));

        $metrics = [
            $tx->trans('export.column.active_frameworks', [], 'compliance') => count($frameworks),
            $tx->trans('export.column.framework_relations', [], 'compliance') => count($frameworkRelationships),
            $tx->trans('export.column.transitive_opportunities', [], 'compliance') => count($transitiveAnalysis),
            $tx->trans('export.column.supported_requirements', [], 'compliance') => $totalHelped,
            $tx->trans('export.column.export_date', [], 'compliance') => date('d.m.Y H:i'),
        ];

        $this->excelExportService->addSummarySection($worksheet, $metrics, 1, 'Transitive Compliance');
        $this->excelExportService->autoSizeColumns($worksheet);

        // Tab 2: Framework Relationships
        $relationshipsSheet = $this->excelExportService->createSheet($spreadsheet, $tx->trans('export.sheet.framework_relations', [], 'compliance'));

        $headers = [$tx->trans('export.column.source_framework', [], 'compliance'), $tx->trans('export.column.target_framework', [], 'compliance'), $tx->trans('export.label.mapped', [], 'compliance'), 'Total', 'Coverage %'];
        $this->excelExportService->addFormattedHeaderRow($relationshipsSheet, $headers, 1, true);

        $data = [];
        foreach ($frameworkRelationships as $frameworkRelationship) {
            $data[] = [
                $frameworkRelationship['source']->getName() . ' (' . $frameworkRelationship['source']->getCode() . ')',
                $frameworkRelationship['target']->getName() . ' (' . $frameworkRelationship['target']->getCode() . ')',
                $frameworkRelationship['mapped'],
                $frameworkRelationship['total'],
                $frameworkRelationship['coverage'],
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

    #[Route('/compliance/export/transitive/pdf', name: 'app_compliance_export_transitive_pdf', methods: ['GET'])]
    public function exportTransitivePdf(Request $request): Response
    {
        $frameworks = $this->complianceFrameworkRepository->findActiveFrameworks();
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

        foreach ($frameworks as $framework) {
            foreach ($frameworks as $targetFramework) {
                if ($framework->id === $targetFramework->id) {
                    continue;
                }

                $coverage = $this->complianceMappingRepository->calculateFrameworkCoverage($framework, $targetFramework);
                $transitive = $this->complianceMappingRepository->getTransitiveCompliance($framework, $targetFramework);

                if ($transitive['requirements_helped'] > 0) {
                    $transitiveAnalysis[] = $transitive;
                    $totalHelped += $transitive['requirements_helped'];
                }

                $mappings = $this->complianceMappingRepository->findCrossFrameworkMappings($framework, $targetFramework);

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

                if ($mappings !== [] && ($coverage['coverage_percentage'] ?? 0) > 0) {
                    $coveragePercentage = round($coverage['coverage_percentage'] ?? 0, 1);

                    // Calculate impact score and ROI
                    $impactAnalysis = $this->complianceMappingRepository->calculateFrameworkImpactScore(
                        $framework,
                        $targetFramework,
                        $coveragePercentage
                    );

                    $relationship = [
                        'source' => $framework,
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
        usort($frameworkRelationships, fn(array $a, array $b): int => $b['impact_score'] <=> $a['impact_score']);
        usort($quickWins, fn(array $a, array $b): int => $b['roi'] <=> $a['roi']);
        usort($highImpactRelationships, fn(array $a, array $b): int => $b['impact_score'] <=> $a['impact_score']);

        // Calculate average coverage and impact
        $avgCoverage = 0;
        $avgImpactScore = 0;
        if ($frameworkRelationships !== []) {
            $totalCoverage = array_sum(array_column($frameworkRelationships, 'coverage'));
            $avgCoverage = $totalCoverage / count($frameworkRelationships);
            $avgImpactScore = $totalImpactScore / count($frameworkRelationships);
        }

        // Close session to prevent blocking other requests during PDF generation
        $request->getSession()->save();

        // Generate version from generation date (Format: Year.Month.Day)
        $pdfGenerationDate = new DateTime();
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
        $response->headers->set('Content-Length', (string) strlen($pdfContent));

        return $response;
    }

    // -------------------------------------------------------------------------
    // Framework Comparison Exports
    // -------------------------------------------------------------------------

    #[Route('/compliance/export/comparison', name: 'app_compliance_export_comparison', methods: ['GET'])]
    public function exportComparison(Request $request): Response
    {
        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if (!$framework1Id || !$framework2Id) {
            $this->flashError('compliance.flash.error.select_two_frameworks');
            return $this->redirectToRoute('app_compliance_compare');
        }

        $framework1 = $this->complianceFrameworkRepository->find($framework1Id);
        $framework2 = $this->complianceFrameworkRepository->find($framework2Id);

        if (!$framework1 || !$framework2) {
            $this->flashError('compliance.flash.error.framework_not_found');
            return $this->redirectToRoute('app_compliance_compare');
        }

        // Build detailed comparison data
        $comparisonDetails = [];

        foreach ($framework1->requirements as $requirement) {
            $mappedRequirement = null;
            $matchQuality = null;
            $isMapped = false;

            // Find mappings where req1 is the source
            $sourceMappings = $this->complianceMappingRepository->findBy([
                'sourceRequirement' => $requirement
            ]);

            foreach ($sourceMappings as $sourceMapping) {
                if ($sourceMapping->getTargetRequirement()->getFramework()->id === $framework2->id) {
                    $mappedRequirement = $sourceMapping->getTargetRequirement();
                    $matchQuality = $sourceMapping->getMappingPercentage();
                    $isMapped = true;
                    break;
                }
            }

            // Also check reverse mappings where req1 is the target
            if (!$isMapped) {
                $targetMappings = $this->complianceMappingRepository->findBy([
                    'targetRequirement' => $requirement
                ]);

                foreach ($targetMappings as $targetMapping) {
                    if ($targetMapping->getSourceRequirement()->getFramework()->id === $framework2->id) {
                        $mappedRequirement = $targetMapping->getSourceRequirement();
                        $matchQuality = $targetMapping->getMappingPercentage();
                        $isMapped = true;
                        break;
                    }
                }
            }

            $comparisonDetails[] = [
                'framework1Requirement' => $requirement,
                'mapped' => $isMapped,
                'framework2Requirement' => $mappedRequirement,
                'matchQuality' => $matchQuality,
            ];
        }

        // Close session to prevent blocking other requests during CSV generation
        $request->getSession()->save();

        // Create CSV content
        $csv = [];
        $tc = $this->getTranslator();
        $mappedLabel = $tc->trans('export.label.mapped', [], 'compliance');
        $notMappedLabel = $tc->trans('export.label.not_mapped', [], 'compliance');

        // CSV Header
        $csv[] = [
            $framework1->getName() . ' - ID',
            $framework1->getName() . ' - ' . $tc->trans('export.column.title', [], 'compliance'),
            $framework1->getName() . ' - ' . $tc->trans('export.column.category', [], 'compliance'),
            $tc->trans('export.column.mapping_status', [], 'compliance'),
            $tc->trans('export.column.match_quality_pct', [], 'compliance'),
            $framework2->getName() . ' - ID',
            $framework2->getName() . ' - ' . $tc->trans('export.column.title', [], 'compliance'),
            $framework2->getName() . ' - ' . $tc->trans('export.column.category', [], 'compliance'),
        ];

        // CSV Data
        foreach ($comparisonDetails as $comparisonDetail) {
            $csv[] = [
                $comparisonDetail['framework1Requirement']->getRequirementId(),
                $comparisonDetail['framework1Requirement']->getTitle(),
                $comparisonDetail['framework1Requirement']->getCategory() ?? '-',
                $comparisonDetail['mapped'] ? $mappedLabel : $notMappedLabel,
                $comparisonDetail['matchQuality'] ?? '-',
                $comparisonDetail['framework2Requirement'] instanceof ComplianceRequirement ? $comparisonDetail['framework2Requirement']->getRequirementId() : '-',
                $comparisonDetail['framework2Requirement'] instanceof ComplianceRequirement ? $comparisonDetail['framework2Requirement']->getTitle() : '-',
                $comparisonDetail['framework2Requirement'] instanceof ComplianceRequirement ? ($comparisonDetail['framework2Requirement']->getCategory() ?? '-') : '-',
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
            fputcsv($handle, array_map([CsvSanitizer::class, 'sanitize'], $row), ';', escape: '\\'); // Use semicolon as delimiter for Excel compatibility
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/compliance/export/comparison/excel', name: 'app_compliance_export_comparison_excel', methods: ['GET'])]
    public function exportComparisonExcel(Request $request): Response
    {
        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if (!$framework1Id || !$framework2Id) {
            $this->flashError('compliance.flash.error.select_two_frameworks');
            return $this->redirectToRoute('app_compliance_compare');
        }

        $framework1 = $this->complianceFrameworkRepository->find($framework1Id);
        $framework2 = $this->complianceFrameworkRepository->find($framework2Id);

        if (!$framework1 || !$framework2) {
            $this->flashError('compliance.flash.error.framework_not_found');
            return $this->redirectToRoute('app_compliance_compare');
        }

        // Build detailed comparison data
        $comparisonDetails = [];
        $mappedCount = 0;

        foreach ($framework1->requirements as $requirement) {
            $mappedRequirement = null;
            $matchQuality = null;
            $isMapped = false;

            // Find mappings
            $sourceMappings = $this->complianceMappingRepository->findBy(['sourceRequirement' => $requirement]);
            foreach ($sourceMappings as $sourceMapping) {
                if ($sourceMapping->getTargetRequirement()->getFramework()->id === $framework2->id) {
                    $mappedRequirement = $sourceMapping->getTargetRequirement();
                    $matchQuality = $sourceMapping->getMappingPercentage();
                    $isMapped = true;
                    $mappedCount++;
                    break;
                }
            }

            if (!$isMapped) {
                $targetMappings = $this->complianceMappingRepository->findBy(['targetRequirement' => $requirement]);
                foreach ($targetMappings as $targetMapping) {
                    if ($targetMapping->getSourceRequirement()->getFramework()->id === $framework2->id) {
                        $mappedRequirement = $targetMapping->getSourceRequirement();
                        $matchQuality = $targetMapping->getMappingPercentage();
                        $isMapped = true;
                        $mappedCount++;
                        break;
                    }
                }
            }

            $comparisonDetails[] = [
                'framework1Requirement' => $requirement,
                'mapped' => $isMapped,
                'framework2Requirement' => $mappedRequirement,
                'matchQuality' => $matchQuality,
            ];
        }

        // Close session to prevent blocking other requests during Excel generation
        $request->getSession()->save();

        // Create spreadsheet
        $spreadsheet = $this->excelExportService->createSpreadsheet('Framework Comparison Report');
        $tce = $this->getTranslator();
        $mappedLabelExcel = $tce->trans('export.label.mapped', [], 'compliance');
        $notMappedLabelExcel = $tce->trans('export.label.not_mapped', [], 'compliance');

        // === TAB 1: Summary ===
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle($tce->trans('export.section.summary', [], 'compliance'));

        $framework1Count = count($framework1->requirements);
        $framework2Count = count($framework2->requirements);
        $overlapPercentage = $framework1Count > 0 ? round(($mappedCount / $framework1Count) * 100, 1) : 0;

        $metrics = [
            'Framework 1' => $framework1->getName() . ' (' . $framework1->getCode() . ')',
            'Framework 2' => $framework2->getName() . ' (' . $framework2->getCode() . ')',
            $tce->trans('export.column.framework1_requirements', [], 'compliance') => $framework1Count,
            $tce->trans('export.column.framework2_requirements', [], 'compliance') => $framework2Count,
            $tce->trans('export.column.mapped_requirements', [], 'compliance') => $mappedCount,
            $tce->trans('export.column.overlap_percentage', [], 'compliance') => $overlapPercentage . '%',
            $tce->trans('export.column.export_date', [], 'compliance') => date('d.m.Y H:i'),
        ];

        $this->excelExportService->addSummarySection($worksheet, $metrics, 1, $tce->trans('export.section.framework_comparison', [], 'compliance'));
        $this->excelExportService->autoSizeColumns($worksheet);

        // === TAB 2: Detailed Comparison ===
        $detailsSheet = $this->excelExportService->createSheet($spreadsheet, $tce->trans('export.sheet.detailed_comparison', [], 'compliance'));

        $headers = [
            $framework1->getName() . ' ID',
            $framework1->getName() . ' ' . $tce->trans('export.column.title', [], 'compliance'),
            $framework1->getName() . ' ' . $tce->trans('export.column.category', [], 'compliance'),
            $tce->trans('export.column.mapping_status', [], 'compliance'),
            $tce->trans('export.column.match_quality_pct', [], 'compliance'),
            $framework2->getName() . ' ID',
            $framework2->getName() . ' ' . $tce->trans('export.column.title', [], 'compliance'),
            $framework2->getName() . ' ' . $tce->trans('export.column.category', [], 'compliance'),
        ];

        $this->excelExportService->addFormattedHeaderRow($detailsSheet, $headers, 1, true);

        $data = [];
        foreach ($comparisonDetails as $detail) {
            $data[] = [
                $detail['framework1Requirement']->getRequirementId(),
                $detail['framework1Requirement']->getTitle(),
                $detail['framework1Requirement']->getCategory() ?? '-',
                $detail['mapped'] ? $mappedLabelExcel : $notMappedLabelExcel,
                $detail['matchQuality'] ?? '-',
                $detail['framework2Requirement'] instanceof ComplianceRequirement ? $detail['framework2Requirement']->getRequirementId() : '-',
                $detail['framework2Requirement'] instanceof ComplianceRequirement ? $detail['framework2Requirement']->getTitle() : '-',
                $detail['framework2Requirement'] instanceof ComplianceRequirement ? ($detail['framework2Requirement']->getCategory() ?? '-') : '-',
            ];
        }

        // Conditional formatting for mapping status and match quality
        $conditionalFormatting = [
            3 => [ // Mapping Status
                $mappedLabelExcel => $this->excelExportService->getColor('success'),
                $notMappedLabelExcel => ['color' => $this->excelExportService->getColor('warning'), 'bold' => false],
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
        $framework1Unique = array_filter($comparisonDetails, fn(array $d): bool => !$d['mapped']);
        if ($framework1Unique !== []) {
            $unique1Sheet = $this->excelExportService->createSheet($spreadsheet, 'Unique ' . substr((string) $framework1->getCode(), 0, 10));

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
        $response->headers->set('Content-Length', (string) strlen($content));

        return $response;
    }

    #[Route('/compliance/export/comparison/pdf', name: 'app_compliance_export_comparison_pdf', methods: ['GET'])]
    public function exportComparisonPdf(Request $request): Response
    {
        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if (!$framework1Id || !$framework2Id) {
            $this->flashError('compliance.flash.error.select_two_frameworks');
            return $this->redirectToRoute('app_compliance_compare');
        }

        $framework1 = $this->complianceFrameworkRepository->find($framework1Id);
        $framework2 = $this->complianceFrameworkRepository->find($framework2Id);

        if (!$framework1 || !$framework2) {
            $this->flashError('compliance.flash.error.framework_not_found');
            return $this->redirectToRoute('app_compliance_compare');
        }

        // Build detailed comparison data
        $comparisonDetails = [];
        $mappedCount = 0;
        $highQualityMappings = 0;

        foreach ($framework1->requirements as $requirement) {
            $mappedRequirement = null;
            $matchQuality = null;
            $isMapped = false;

            // Find mappings
            $sourceMappings = $this->complianceMappingRepository->findBy(['sourceRequirement' => $requirement]);
            foreach ($sourceMappings as $sourceMapping) {
                if ($sourceMapping->getTargetRequirement()->getFramework()->id === $framework2->id) {
                    $mappedRequirement = $sourceMapping->getTargetRequirement();
                    $matchQuality = $sourceMapping->getMappingPercentage();
                    $isMapped = true;
                    $mappedCount++;
                    if ($matchQuality >= 80) {
                        $highQualityMappings++;
                    }
                    break;
                }
            }

            if (!$isMapped) {
                $targetMappings = $this->complianceMappingRepository->findBy(['targetRequirement' => $requirement]);
                foreach ($targetMappings as $targetMapping) {
                    if ($targetMapping->getSourceRequirement()->getFramework()->id === $framework2->id) {
                        $mappedRequirement = $targetMapping->getSourceRequirement();
                        $matchQuality = $targetMapping->getMappingPercentage();
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
                'framework1Requirement' => $requirement,
                'mapped' => $isMapped,
                'framework2Requirement' => $mappedRequirement,
                'matchQuality' => $matchQuality,
            ];
        }

        // Calculate metrics
        $framework1Count = count($framework1->requirements);
        $framework2Count = count($framework2->requirements);
        $overlapPercentage = $framework1Count > 0 ? round(($mappedCount / $framework1Count) * 100, 1) : 0;
        $unmapped = $framework1Count - $mappedCount;

        // Find unique requirements
        $uniqueFramework1 = array_filter($comparisonDetails, fn(array $d): bool => !$d['mapped']);

        // Calculate bidirectional coverage
        $bidirectionalCoverage = $this->complianceMappingRepository->calculateBidirectionalCoverage($framework1, $framework2);

        // Calculate category-specific coverage (both directions)
        $categoryCoverageF1toF2 = $this->complianceMappingRepository->calculateCategoryCoverage($framework1, $framework2);
        $categoryCoverageF2toF1 = $this->complianceMappingRepository->calculateCategoryCoverage($framework2, $framework1);

        // Calculate mapping quality distribution
        $qualityDistribution = [
            'excellent' => 0,  // 90-100%
            'good' => 0,       // 70-89%
            'medium' => 0,     // 50-69%
            'weak' => 0,       // <50%
        ];
        $qualitySum = 0;
        $qualityCount = 0;

        foreach ($comparisonDetails as $comparisonDetail) {
            if ($comparisonDetail['mapped'] && $comparisonDetail['matchQuality'] !== null) {
                $quality = $comparisonDetail['matchQuality'];
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
        $pdfGenerationDate = new DateTime();
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
        $response->headers->set('Content-Length', (string) strlen($pdfContent));

        return $response;
    }
}
