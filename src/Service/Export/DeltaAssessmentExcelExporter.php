<?php

declare(strict_types=1);

namespace App\Service\Export;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\FulfillmentInheritanceLogRepository;
use App\Service\ExcelExportService;
use App\Service\GapEffortCalculator;
use App\Service\InheritanceMetricsService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Delta-Assessment Excel Exporter (CM-2).
 *
 * Produces a single management-review-ready workbook describing how an already
 * implemented baseline framework (e.g. ISO 27001) pre-fills a newly activated
 * target framework (e.g. NIS2). Three sheets:
 *
 *   - Summary        — headline KPIs + pre-fill rate call-out
 *   - Detailed Delta — row per target requirement with inheritance origin,
 *                      fulfillment %, gap-days, and recommended next action
 *   - Mapping Inventory — raw baseline→target mappings used as the basis for
 *                         inheritance (with versioning + confidence metadata)
 *
 * Reuses existing services:
 *   - InheritanceMetricsService (CM-1/CM-5) for the inheritance rate + FTE saved
 *   - GapEffortCalculator (WS-6) for remaining-effort per requirement
 *   - ExcelExportService for BOM-safe / formula-injection-safe cell writes
 *
 * @see docs/CM_JUNIOR_RESPONSE.md CM-2
 */
final class DeltaAssessmentExcelExporter
{
    public const string PRE_FILL_TARGET_PERCENT = '60';

    public const string ACTION_NO = 'no_action';
    public const string ACTION_REVIEW_PENDING = 'review_pending';
    public const string ACTION_NEW_ASSESSMENT = 'new_assessment';
    public const string ACTION_GAP_CLOSE = 'gap_close';

    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly FulfillmentInheritanceLogRepository $logRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ExcelExportService $excelExportService,
        private readonly InheritanceMetricsService $inheritanceMetricsService,
        private readonly GapEffortCalculator $gapEffortCalculator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Build the delta-assessment workbook.
     *
     * @param Tenant                  $tenant            Scoping tenant (strict).
     * @param ComplianceFramework     $targetFramework   Newly activated framework (e.g. NIS2).
     * @param ComplianceFramework|null $baselineFramework Existing baseline (e.g. ISO 27001) or null if none.
     */
    public function export(
        Tenant $tenant,
        ComplianceFramework $targetFramework,
        ?ComplianceFramework $baselineFramework = null,
    ): Spreadsheet {
        $spreadsheet = $this->excelExportService->createSpreadsheet(sprintf(
            'Delta Assessment %s',
            (string) $targetFramework->getCode(),
        ));

        $targetRequirements = $this->requirementRepository->findBy(
            ['complianceFramework' => $targetFramework],
            ['requirementId' => 'ASC'],
        );

        $fulfillments = $this->indexFulfillmentsByRequirementId(
            $this->fulfillmentRepository->findByFrameworkAndTenant($targetFramework, $tenant),
        );

        $logsByFulfillment = $this->indexLogsByFulfillmentId($tenant, $targetFramework);

        $effortRows = $this->indexEffortRows(
            $this->gapEffortCalculator->calculate($tenant, $targetFramework),
        );

        $mappingsByTargetId = $baselineFramework !== null
            ? $this->indexMappingsByTargetRequirement(
                $this->mappingRepository->findCrossFrameworkMappings($baselineFramework, $targetFramework),
            )
            : [];

        $this->buildSummarySheet(
            $spreadsheet,
            $tenant,
            $targetFramework,
            $baselineFramework,
            $targetRequirements,
            $fulfillments,
            $logsByFulfillment,
        );

        $this->buildDetailedDeltaSheet(
            $spreadsheet,
            $targetRequirements,
            $fulfillments,
            $logsByFulfillment,
            $mappingsByTargetId,
            $effortRows,
        );

        $this->buildMappingInventorySheet(
            $spreadsheet,
            $baselineFramework !== null
                ? $this->mappingRepository->findCrossFrameworkMappings($baselineFramework, $targetFramework)
                : [],
        );

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    // ─── Sheet builders ──────────────────────────────────────────────────────

    /**
     * @param list<ComplianceRequirement>                           $targetRequirements
     * @param array<string, ComplianceRequirementFulfillment>       $fulfillments          Keyed by requirement-ID string.
     * @param array<int, list<FulfillmentInheritanceLog>>           $logsByFulfillment     Keyed by fulfillment-ID.
     */
    private function buildSummarySheet(
        Spreadsheet $spreadsheet,
        Tenant $tenant,
        ComplianceFramework $targetFramework,
        ?ComplianceFramework $baselineFramework,
        array $targetRequirements,
        array $fulfillments,
        array $logsByFulfillment,
    ): void {
        $sheet = $spreadsheet->getActiveSheet();
        // Use sanitized title via reusable helper — PhpSpreadsheet enforces 31-char cap.
        $sheet->setTitle($this->sanitizeSheetName($this->t('compliance_wizard.delta.sheet.summary')));

        $total = count($targetRequirements);
        $directFulfilled = 0;
        $inheritedCount = 0;
        $gapCount = 0;
        $sumFulfillment = 0;

        foreach ($targetRequirements as $requirement) {
            $reqId = (string) $requirement->getRequirementId();
            $fulfillment = $fulfillments[$reqId] ?? null;
            $pct = $fulfillment?->getFulfillmentPercentage() ?? 0;
            $sumFulfillment += $pct;

            if ($pct >= 100) {
                $directFulfilled++;
            }
            if ($pct < 50) {
                $gapCount++;
            }
            $fulfillmentId = $fulfillment?->getId();
            if (
                $fulfillmentId !== null
                && isset($logsByFulfillment[$fulfillmentId])
                && $logsByFulfillment[$fulfillmentId] !== []
            ) {
                $inheritedCount++;
            }
        }

        $preFillPct = $total > 0 ? (int) round(($inheritedCount / $total) * 100) : 0;
        $avgFulfillment = $total > 0 ? (int) round($sumFulfillment / $total) : 0;
        $fteSaved = $this->inheritanceMetricsService->fteSavedForFramework($tenant, $targetFramework);

        $metrics = [
            $this->t('compliance_wizard.delta.summary.tenant') => (string) $tenant->getName(),
            $this->t('compliance_wizard.delta.summary.target') => $this->frameworkLabel($targetFramework),
            $this->t('compliance_wizard.delta.summary.baseline') => $baselineFramework !== null
                ? $this->frameworkLabel($baselineFramework)
                : '—',
            $this->t('compliance_wizard.delta.summary.reporting_date') => (new \DateTimeImmutable())->format('Y-m-d'),
            $this->t('compliance_wizard.delta.summary.total') => (string) $total,
            $this->t('compliance_wizard.delta.summary.inherited') => sprintf('%d (%d%%)', $inheritedCount, $preFillPct),
            $this->t('compliance_wizard.delta.summary.direct') => (string) $directFulfilled,
            $this->t('compliance_wizard.delta.summary.gap') => (string) $gapCount,
            $this->t('compliance_wizard.delta.summary.avg_fulfillment') => $avgFulfillment . '%',
            $this->t('compliance_wizard.delta.summary.fte_saved') => number_format($fteSaved, 1, '.', '') . ' FTE-days',
        ];

        $nextRow = $this->excelExportService->addSummarySection(
            $sheet,
            $metrics,
            1,
            $this->t('compliance_wizard.delta.title'),
        );

        // Pre-fill call-out block ─────────────────────────────────────────────
        $targetPct = (int) self::PRE_FILL_TARGET_PERCENT;
        $meetsTarget = $preFillPct >= $targetPct;
        $calloutLabel = $this->translator->trans(
            'compliance_wizard.delta.summary.pre_fill_rate',
            ['%target%' => $targetPct, '%actual%' => $preFillPct],
            'compliance_wizard',
        );
        $calloutValue = $meetsTarget
            ? $this->t('compliance_wizard.delta.summary.pre_fill_rate_ok')
            : $this->t('compliance_wizard.delta.summary.pre_fill_rate_miss');

        $sheet->setCellValue('A' . $nextRow, $calloutLabel);
        $sheet->setCellValue('B' . $nextRow, $calloutValue);
        $sheet->getStyle('A' . $nextRow . ':B' . $nextRow)->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => $meetsTarget
                    ? $this->excelExportService->getColor('success')
                    : $this->excelExportService->getColor('warning')],
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN],
            ],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
    }

    /**
     * @param list<ComplianceRequirement>                     $targetRequirements
     * @param array<string, ComplianceRequirementFulfillment> $fulfillments
     * @param array<int, list<FulfillmentInheritanceLog>>     $logsByFulfillment
     * @param array<int, list<ComplianceMapping>>             $mappingsByTargetId
     * @param array<int, array<string, mixed>>                $effortRows Keyed by requirement-id (int).
     */
    private function buildDetailedDeltaSheet(
        Spreadsheet $spreadsheet,
        array $targetRequirements,
        array $fulfillments,
        array $logsByFulfillment,
        array $mappingsByTargetId,
        array $effortRows,
    ): void {
        $sheet = $this->excelExportService->createSheet(
            $spreadsheet,
            $this->t('compliance_wizard.delta.sheet.detail'),
        );

        $headers = [
            $this->t('compliance_wizard.delta.detail.col.requirement_id'),
            $this->t('compliance_wizard.delta.detail.col.title'),
            $this->t('compliance_wizard.delta.detail.col.category'),
            $this->t('compliance_wizard.delta.detail.col.priority'),
            $this->t('compliance_wizard.delta.detail.col.fulfillment_pct'),
            $this->t('compliance_wizard.delta.detail.col.status'),
            $this->t('compliance_wizard.delta.detail.col.source_mappings'),
            $this->t('compliance_wizard.delta.detail.col.inheritance_state'),
            $this->t('compliance_wizard.delta.detail.col.applicability_justification'),
            $this->t('compliance_wizard.delta.detail.col.gap_estimation'),
            $this->t('compliance_wizard.delta.detail.col.action_required'),
        ];
        $this->excelExportService->addFormattedHeaderRow($sheet, $headers, 1, true);

        $rows = [];
        foreach ($targetRequirements as $requirement) {
            $requirementId = (string) $requirement->getRequirementId();
            $requirementPk = (int) $requirement->getId();
            $fulfillment = $fulfillments[$requirementId] ?? null;
            $pct = $fulfillment?->getFulfillmentPercentage() ?? 0;

            $mappingsForRow = $mappingsByTargetId[$requirementPk] ?? [];
            $sourceMappingsText = $this->formatSourceMappings($mappingsForRow);

            $inheritanceState = $this->deriveInheritanceState(
                $fulfillment,
                $logsByFulfillment,
            );

            $gapDays = $this->calculateGapDays($requirementPk, $effortRows);
            $action = $this->deriveAction($pct, $inheritanceState);

            $rows[] = [
                $requirementId,
                (string) $requirement->getTitle(),
                (string) ($requirement->getCategory() ?? ''),
                (string) ($requirement->getPriority() ?? ''),
                $pct,
                $fulfillment?->getStatus() ?? 'not_started',
                $sourceMappingsText,
                $inheritanceState ?? '',
                (string) ($fulfillment?->getApplicabilityJustification() ?? ''),
                $gapDays,
                $this->t('compliance_wizard.delta.action.' . $action),
            ];
        }

        $this->excelExportService->addFormattedDataRows(
            $sheet,
            $rows,
            2,
            [
                // Column E (index 4) = Fulfillment % — traffic-light background
                4 => [
                    '>=75' => ['color' => $this->excelExportService->getColor('success'), 'bold' => false],
                    '>=50' => ['color' => $this->excelExportService->getColor('warning'), 'bold' => false],
                    '<50'  => ['color' => $this->excelExportService->getColor('danger'), 'bold' => false],
                ],
            ],
        );

        $this->excelExportService->autoSizeColumns($sheet);
    }

    /**
     * @param list<ComplianceMapping> $mappings
     */
    private function buildMappingInventorySheet(
        Spreadsheet $spreadsheet,
        array $mappings,
    ): void {
        $sheet = $this->excelExportService->createSheet(
            $spreadsheet,
            $this->t('compliance_wizard.delta.sheet.mapping'),
        );

        $headers = [
            $this->t('compliance_wizard.delta.mapping.col.source_requirement'),
            $this->t('compliance_wizard.delta.mapping.col.target_requirement'),
            $this->t('compliance_wizard.delta.mapping.col.mapping_percentage'),
            $this->t('compliance_wizard.delta.mapping.col.confidence'),
            $this->t('compliance_wizard.delta.mapping.col.bidirectional'),
            $this->t('compliance_wizard.delta.mapping.col.source'),
            $this->t('compliance_wizard.delta.mapping.col.valid_from'),
            $this->t('compliance_wizard.delta.mapping.col.valid_until'),
        ];
        $this->excelExportService->addFormattedHeaderRow($sheet, $headers, 1, true);

        $rows = [];
        foreach ($mappings as $mapping) {
            $rows[] = [
                $this->requirementLabel($mapping->getSourceRequirement()),
                $this->requirementLabel($mapping->getTargetRequirement()),
                $mapping->getMappingPercentage(),
                $mapping->getConfidence(),
                $mapping->isBidirectional() ? 'Y' : 'N',
                $mapping->getSource(),
                $mapping->getValidFrom()?->format('Y-m-d') ?? '',
                $mapping->getValidUntil()?->format('Y-m-d') ?? '',
            ];
        }

        $this->excelExportService->addFormattedDataRows($sheet, $rows, 2);
        $this->excelExportService->autoSizeColumns($sheet);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param list<ComplianceRequirementFulfillment> $fulfillments
     * @return array<string, ComplianceRequirementFulfillment>
     */
    private function indexFulfillmentsByRequirementId(array $fulfillments): array
    {
        $indexed = [];
        foreach ($fulfillments as $fulfillment) {
            $requirementId = $fulfillment->getRequirement()?->getRequirementId();
            if ($requirementId !== null) {
                $indexed[(string) $requirementId] = $fulfillment;
            }
        }
        return $indexed;
    }

    /**
     * @return array<int, list<FulfillmentInheritanceLog>>
     */
    private function indexLogsByFulfillmentId(Tenant $tenant, ComplianceFramework $framework): array
    {
        $logs = $this->logRepository->findForQueue($tenant, $framework);
        $indexed = [];
        foreach ($logs as $log) {
            $fulfillmentId = $log->getFulfillment()?->getId();
            if ($fulfillmentId === null) {
                continue;
            }
            $indexed[$fulfillmentId][] = $log;
        }
        return $indexed;
    }

    /**
     * @param list<ComplianceMapping> $mappings
     * @return array<int, list<ComplianceMapping>>
     */
    private function indexMappingsByTargetRequirement(array $mappings): array
    {
        $indexed = [];
        foreach ($mappings as $mapping) {
            $targetId = $mapping->getTargetRequirement()?->getId();
            if ($targetId === null) {
                continue;
            }
            $indexed[$targetId][] = $mapping;
        }
        return $indexed;
    }

    /**
     * @param list<array<string, mixed>> $rows Raw GapEffortCalculator rows.
     * @return array<int, array<string, mixed>>
     */
    private function indexEffortRows(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $requirement = $row['requirement'] ?? null;
            if (!$requirement instanceof ComplianceRequirement) {
                continue;
            }
            $pk = $requirement->getId();
            if ($pk === null) {
                continue;
            }
            $indexed[$pk] = $row;
        }
        return $indexed;
    }

    /**
     * Format the source-mappings cell as `CODE REQ_ID (PCT%), ...`.
     *
     * @param list<ComplianceMapping> $mappings
     */
    private function formatSourceMappings(array $mappings): string
    {
        if ($mappings === []) {
            return '';
        }

        $parts = [];
        foreach ($mappings as $mapping) {
            $source = $mapping->getSourceRequirement();
            if ($source === null) {
                continue;
            }
            $parts[] = sprintf(
                '%s (%d%%)',
                $this->requirementLabel($source),
                $mapping->getMappingPercentage(),
            );
        }

        return implode(', ', $parts);
    }

    /**
     * Pick the "dominant" inheritance state for a fulfillment: CONFIRMED wins
     * over OVERRIDDEN over PENDING over anything else; null if there are no logs.
     *
     * @param array<int, list<FulfillmentInheritanceLog>> $logsByFulfillment
     */
    private function deriveInheritanceState(
        ?ComplianceRequirementFulfillment $fulfillment,
        array $logsByFulfillment,
    ): ?string {
        if ($fulfillment === null) {
            return null;
        }
        $id = $fulfillment->getId();
        if ($id === null || !isset($logsByFulfillment[$id])) {
            return null;
        }

        $priority = [
            FulfillmentInheritanceLog::STATUS_CONFIRMED => 4,
            FulfillmentInheritanceLog::STATUS_OVERRIDDEN => 3,
            FulfillmentInheritanceLog::STATUS_PENDING_REVIEW => 2,
            FulfillmentInheritanceLog::STATUS_SOURCE_UPDATED => 2,
            FulfillmentInheritanceLog::STATUS_IMPLEMENTED => 1,
            FulfillmentInheritanceLog::STATUS_REJECTED => 0,
        ];

        $bestStatus = null;
        $bestScore = -1;
        foreach ($logsByFulfillment[$id] as $log) {
            $status = $log->getReviewStatus();
            $score = $priority[$status] ?? 0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestStatus = $status;
            }
        }

        if ($bestStatus === FulfillmentInheritanceLog::STATUS_PENDING_REVIEW
            || $bestStatus === FulfillmentInheritanceLog::STATUS_SOURCE_UPDATED
        ) {
            return 'inherited_pending_review';
        }

        return $bestStatus;
    }

    /**
     * @param array<int, array<string, mixed>> $effortRows
     */
    private function calculateGapDays(int $requirementPk, array $effortRows): float
    {
        $row = $effortRows[$requirementPk] ?? null;
        if ($row === null) {
            return 0.0;
        }
        return round((float) ($row['remaining_effort_days'] ?? 0.0), 2);
    }

    private function deriveAction(int $fulfillmentPct, ?string $inheritanceState): string
    {
        if ($inheritanceState === 'inherited_pending_review') {
            return self::ACTION_REVIEW_PENDING;
        }
        if ($fulfillmentPct >= 100) {
            return self::ACTION_NO;
        }
        if ($fulfillmentPct >= 50) {
            return self::ACTION_GAP_CLOSE;
        }
        return self::ACTION_NEW_ASSESSMENT;
    }

    private function frameworkLabel(ComplianceFramework $framework): string
    {
        $code = $framework->getCode();
        $version = $framework->getVersion();
        return trim(sprintf(
            '%s%s',
            (string) $code,
            $version !== null && $version !== '' ? ' ' . $version : '',
        ));
    }

    private function requirementLabel(?ComplianceRequirement $requirement): string
    {
        if ($requirement === null) {
            return '';
        }
        $code = $requirement->getFramework()?->getCode();
        return trim(sprintf(
            '%s %s',
            (string) ($code ?? ''),
            (string) $requirement->getRequirementId(),
        ));
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'compliance_wizard');
    }

    /**
     * Excel workbook sheet titles are limited to 31 chars and a set of forbidden characters.
     * Mirrors ExcelExportService::sanitizeSheetName (private) so the exporter can safely
     * title the summary sheet returned by getActiveSheet().
     */
    private function sanitizeSheetName(string $name): string
    {
        $cleaned = preg_replace('/[\[\]\:\*\?\/\\\\]/', '', $name) ?? $name;
        return substr($cleaned, 0, 31);
    }
}
