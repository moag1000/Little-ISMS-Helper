<?php

declare(strict_types=1);

namespace App\Service\Risk;

use App\Entity\Risk;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Risk\RiskMatrixThresholds;
use App\Service\ExcelExportService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use App\Twig\AppDateFormatExtension;
use App\Util\CsvSanitizer;
use DateTime;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the risk-register exports (CSV, Excel, PDF) behind the
 * /risk/export* actions of {@see \App\Controller\RiskController}.
 *
 * Extracted from the controller (god-class ratchet, Gate 25): the controller
 * keeps routing, access control and the HTTP response; this service owns the
 * register filter and the document layouts.
 */
class RiskRegisterExporter
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ExcelExportService $excelExportService,
        private readonly PdfExportService $pdfExportService,
        private readonly TenantContext $tenantContext,
        private readonly AppDateFormatExtension $dateFormat,
    ) {
    }

    /**
     * Applies the register list filters (level / status / treatment / owner).
     * Empty values are ignored, as on the register list page.
     *
     * @param array<Risk> $risks
     * @return list<Risk>
     */
    public function filter(array $risks, mixed $level, mixed $status, mixed $treatment, mixed $owner): array
    {
        if ($level) {
            // SSoT — App\Risk\RiskMatrixThresholds (ISO 27001 Cl. 6.1.2 b).
            $risks = array_filter(
                $risks,
                static fn(Risk $risk): bool => RiskMatrixThresholds::classify($risk->getRiskScore(), $risk->getTenant()) === $level,
            );
        }
        if ($status) {
            $risks = array_filter($risks, static fn(Risk $risk): bool => $risk->getStatus()?->value === $status);
        }
        if ($treatment) {
            $risks = array_filter(
                $risks,
                static fn(Risk $risk): bool => $risk->getTreatmentStrategy()?->value === $treatment,
            );
        }
        if ($owner) {
            $risks = array_filter($risks, static fn(Risk $risk): bool => $risk->getRiskOwner() instanceof User
                && stripos($risk->getRiskOwner()->getFullName(), (string) $owner) !== false);
        }

        return array_values($risks);
    }

    /**
     * {@see filter()} as conditions on the register list query (S31) — the list pages in SQL. Level bands are
     * each risk's organisation's ({@see RiskMatrixThresholds}); `$tenants` are the organisations the list
     * shows (null: those of the rows).
     *
     * @param list<\App\Entity\Tenant>|null $tenants
     */
    public function applyFilters(\App\Util\ListQuery $list, mixed $level, mixed $status, mixed $treatment, mixed $owner, ?array $tenants): void
    {
        $alias = $list->alias();
        if (is_string($level) && $level !== '') {
            $list->where($this->bandCondition($alias, $level, $tenants ?? $list->rowTenants()));
        }
        $list->equals("$alias.status", is_string($status) ? RiskStatus::tryFrom($status) ?? $status : null)
            ->equals("$alias.treatmentStrategy", is_string($treatment) ? \App\Enum\TreatmentStrategy::tryFrom($treatment) ?? $treatment : null);
        if (is_string($owner) && $owner !== '') {
            $list->leftJoin("$alias.riskOwner", 'rro')->contains(["CONCAT(rro.firstName, ' ', rro.lastName)"], $owner);
        }
    }

    /**
     * Risks in band `$level` as DQL: per organisation its own edges; risks without organisation use the defaults.
     * Only integers from the band table and tenant ids go into the string.
     *
     * @param list<\App\Entity\Tenant> $tenants
     */
    public function bandCondition(string $alias, string $level, array $tenants): string
    {
        $score = "(COALESCE($alias.probability, 0) * COALESCE($alias.impact, 0))";
        $range = static function (?\App\Entity\Tenant $tenant) use ($score, $level): string {
            $edges = ['critical' => RiskMatrixThresholds::criticalMin($tenant), 'high' => RiskMatrixThresholds::highMin($tenant), 'medium' => RiskMatrixThresholds::mediumMin($tenant)];

            return match ($level) {
                'critical' => sprintf('%s >= %d', $score, $edges['critical']),
                'high' => sprintf('%1$s >= %2$d AND %1$s < %3$d', $score, $edges['high'], $edges['critical']),
                'medium' => sprintf('%1$s >= %2$d AND %1$s < %3$d', $score, $edges['medium'], $edges['high']),
                'low' => sprintf('%s < %d', $score, $edges['medium']),
                default => '1 = 0',
            };
        };
        $or = [sprintf('(%s.tenant IS NULL AND %s)', $alias, $range(null))];
        foreach ($tenants as $tenant) {
            if ($tenant->getId() !== null) {
                $or[] = sprintf('(IDENTITY(%s.tenant) = %d AND %s)', $alias, $tenant->getId(), $range($tenant));
            }
        }

        return '(' . implode(' OR ', $or) . ')';
    }

    /**
     * Semicolon-separated, formula-sanitised CSV with UTF-8 BOM (Excel) and
     * locale-aware headers / labels from the `risk` domain.
     *
     * @param list<Risk> $risks
     */
    public function buildCsv(array $risks): string
    {
        $t = fn(string $key): string => $this->translator->trans('risk.export.' . $key, [], 'risk');
        // Band edges are the risk's organisation's (Risikokriterien), see RiskMatrixThresholds.
        $level = static fn(int $score, Risk $risk): string => $t('level.' . RiskMatrixThresholds::classify($score, $risk->getTenant()));

        $rows = [array_map($t, [
            'header.id', 'header.title', 'header.description', 'header.threat', 'header.vulnerability',
            'header.asset', 'header.probability', 'header.impact', 'header.risk_score', 'header.risk_level',
            'header.residual_probability', 'header.residual_impact', 'header.residual_score',
            'header.residual_level', 'header.treatment_strategy', 'header.status', 'header.risk_owner',
            'header.created_at', 'header.review_date',
        ])];

        foreach ($risks as $risk) {
            $riskScore = $risk->getRiskScore();
            $residualScore = $risk->getResidualRiskLevel();
            $rows[] = [
                $risk->getId(),
                $risk->getTitle(),
                $risk->getDescription(),
                $risk->getThreat() ?? '-',
                $risk->getVulnerability() ?? '-',
                $risk->getAsset() ? $risk->getAsset()->getName() : '-',
                $risk->getProbability(),
                $risk->getImpact(),
                $riskScore,
                $level($riskScore, $risk),
                $risk->getResidualProbability(),
                $risk->getResidualImpact(),
                $residualScore,
                $level($residualScore, $risk),
                $this->translatedOrRaw('risk.export.treatment.', $risk->getTreatmentStrategy()->value ?? ''),
                $this->translatedOrRaw('risk.export.status.', $risk->getStatus()->value ?? ''),
                $risk->getRiskOwner() ? $risk->getRiskOwner()->getFullName() : '-',
                $risk->getCreatedAt() ? $risk->getCreatedAt()->format('Y-m-d H:i') : '-',
                $risk->getReviewDate() ? $risk->getReviewDate()->format('Y-m-d') : '-',
            ];
        }

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            // Semicolon delimiter for Excel compatibility.
            fputcsv($handle, array_map([CsvSanitizer::class, 'sanitize'], $row), ';', escape: '\\');
        }
        rewind($handle);
        $csv = "\xEF\xBB\xBF" . stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Three-tab Excel report: summary, all risks, critical & high risks.
     *
     * @param list<Risk> $risks
     */
    public function buildExcel(array $risks): string
    {
        $excel = $this->excelExportService;
        $counts = $this->levelCounts($risks);
        $t = fn(string $key): string => $this->translator->trans('risk.export.' . $key, [], 'risk');
        $spreadsheet = $excel->createSpreadsheet($t('excel.workbook_title'));

        // === TAB 1: Summary ===
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle($t('excel.summary_sheet'));
        $nextRow = $excel->addSummarySection($worksheet, [
            $t('excel.total') => $counts['total'],
            $t('excel.critical') => $counts['critical'],
            $t('excel.high') => $counts['high'],
            $t('excel.medium') => $counts['medium'],
            $t('excel.low') => $counts['low'],
            $t('excel.export_date') => $this->dateFormat->formatDateTime(new DateTime()),
            $t('excel.methodology') => $this->methodologyLabel() ?? '-',
        ], 1, $t('excel.summary_title'));

        $statusMetrics = [];
        foreach ([
            RiskStatus::Identified,
            RiskStatus::Assessed,
            RiskStatus::Treated,
            RiskStatus::Monitored,
            RiskStatus::Closed,
            RiskStatus::Accepted,
        ] as $case) {
            $statusMetrics[$t('status.' . $case->value)] = count(array_filter(
                $risks,
                static fn(Risk $r): bool => $r->getStatus() === $case,
            ));
        }
        $excel->addSummarySection($worksheet, $statusMetrics, $nextRow, $t('excel.status_title'));
        $excel->autoSizeColumns($worksheet);

        // === TAB 2: All Risks / TAB 3: Critical & High Risks ===
        $headers = array_map($t, [
            'header.id', 'header.title', 'header.asset', 'excel.header.probability', 'excel.header.impact',
            'excel.header.score', 'excel.header.level', 'excel.header.residual_probability',
            'excel.header.residual_impact', 'excel.header.residual_score', 'excel.header.residual_level',
            'excel.header.strategy', 'header.status', 'excel.header.owner', 'excel.header.created',
        ]);
        $levelColors = [
            $t('level.critical') => $excel->getColor('critical'),
            $t('level.high') => $excel->getColor('high'),
            $t('level.medium') => $excel->getColor('medium'),
            $t('level.low') => $excel->getColor('low'),
        ];
        // Conditional formatting for risk level (index 6) and residual level (index 10).
        $conditionalFormatting = [6 => $levelColors, 10 => $levelColors];

        $sheets = [$t('excel.all_sheet') => $risks];
        $criticalHigh = array_filter($risks, static fn(Risk $r): bool => $r->getRiskScore() >= RiskMatrixThresholds::highMin($r->getTenant()));
        if ($criticalHigh !== []) {
            $sheets[$t('excel.critical_high_sheet')] = $criticalHigh;
        }
        foreach ($sheets as $title => $sheetRisks) {
            $sheet = $excel->createSheet($spreadsheet, $title);
            $excel->addFormattedHeaderRow($sheet, $headers, 1, true);
            $excel->addFormattedDataRows(
                $sheet,
                array_map($this->excelRow(...), array_values($sheetRisks)),
                2,
                $conditionalFormatting,
            );
            $excel->autoSizeColumns($sheet);
        }

        return $excel->generateExcel($spreadsheet);
    }

    /**
     * PDF risk report (template pdf/risk_report.html.twig).
     *
     * @param list<Risk> $risks
     */
    public function buildPdf(array $risks, ?string $filterInfo): string
    {
        $counts = $this->levelCounts($risks);

        $statusBreakdown = [];
        foreach ([
            'identified' => RiskStatus::Identified,
            'assessed' => RiskStatus::Assessed,
            'in_treatment' => RiskStatus::InTreatment,
            'treated' => RiskStatus::Treated,
            'mitigated' => RiskStatus::Mitigated,
            'monitored' => RiskStatus::Monitored,
            'closed' => RiskStatus::Closed,
            'accepted' => RiskStatus::Accepted,
            'open' => RiskStatus::Open,
        ] as $key => $case) {
            $count = count(array_filter($risks, static fn(Risk $r): bool => $r->getStatus() === $case));
            if ($count > 0) {
                $statusBreakdown[$key] = $count;
            }
        }

        return $this->pdfExportService->generatePdf('pdf/risk_report.html.twig', [
            'risks' => $risks,
            'total_risks' => $counts['total'],
            'critical_risks' => $counts['critical'],
            'high_risks' => $counts['high'],
            'medium_risks' => $counts['medium'],
            'low_risks' => $counts['low'],
            'status_breakdown' => $statusBreakdown,
            'filter_info' => $filterInfo,
            'risk_methodology' => $this->methodologyLabel(),
            'pdf_generation_date' => new DateTime(),
        ]);
    }

    /**
     * @param list<Risk> $risks
     * @return array{total: int, critical: int, high: int, medium: int, low: int}
     */
    private function levelCounts(array $risks): array
    {
        $counts = ['total' => count($risks), 'critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($risks as $risk) {
            $score = $risk->getRiskScore();
            $counts[RiskMatrixThresholds::classify($score, $risk->getTenant())]++;
        }

        return $counts;
    }

    /**
     * @return list<mixed>
     */
    private function excelRow(Risk $risk): array
    {
        $level = fn(int $score): string => $this->translator->trans(
            'risk.export.level.' . RiskMatrixThresholds::classify($score, $risk->getTenant()),
            [],
            'risk',
        );
        $riskScore = $risk->getRiskScore();
        $residualScore = $risk->getResidualRiskLevel();

        return [
            $risk->getId(),
            $risk->getTitle(),
            $risk->getAsset() ? $risk->getAsset()->getName() : '-',
            $risk->getProbability(),
            $risk->getImpact(),
            $riskScore,
            $level($riskScore),
            $risk->getResidualProbability(),
            $risk->getResidualImpact(),
            $residualScore,
            $level($residualScore),
            $risk->getTreatmentStrategy() === null
                ? null
                : $this->translatedOrRaw('risk.export.treatment.', $risk->getTreatmentStrategy()->value),
            $risk->getStatus() === null
                ? null
                : $this->translatedOrRaw('risk.export.status.', $risk->getStatus()->value),
            $risk->getRiskOwner() ? $risk->getRiskOwner()->getFullName() : '-',
            $risk->getCreatedAt() ? $this->dateFormat->formatDate($risk->getCreatedAt()) : '-',
        ];
    }

    /**
     * Risk-assessment methodology documented in the tenant compliance settings
     * (ISO 27001 Cl. 6.1.2 requires a defined method), or null when none is set.
     */
    private function methodologyLabel(): ?string
    {
        $methodology = $this->tenantContext->getCurrentTenant()?->getRiskMethodology();
        if ($methodology === null || $methodology === '') {
            return null;
        }

        return $this->translatedOrRaw('risk.export.methodology.', $methodology);
    }

    /** Translated label, or the raw value when no translation exists. */
    private function translatedOrRaw(string $prefix, string $value): string
    {
        $translated = $this->translator->trans($prefix . $value, [], 'risk');

        return $translated !== $prefix . $value ? $translated : $value;
    }
}
