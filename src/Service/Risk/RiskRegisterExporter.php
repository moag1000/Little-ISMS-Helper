<?php

declare(strict_types=1);

namespace App\Service\Risk;

use App\Entity\Risk;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Risk\RiskMatrixThresholds;
use App\Service\ExcelExportService;
use App\Service\PdfExportService;
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
    /** Risk score at or above which a risk counts as critical / high / medium. */
    private const int CRITICAL = 15;
    private const int HIGH = 8;
    private const int MEDIUM = 4;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ExcelExportService $excelExportService,
        private readonly PdfExportService $pdfExportService,
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
                static fn(Risk $risk): bool => RiskMatrixThresholds::classify($risk->getRiskScore()) === $level,
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
     * Semicolon-separated, formula-sanitised CSV with UTF-8 BOM (Excel) and
     * locale-aware headers / labels from the `risk` domain.
     *
     * @param list<Risk> $risks
     */
    public function buildCsv(array $risks): string
    {
        $t = fn(string $key): string => $this->translator->trans('risk.export.' . $key, [], 'risk');
        $level = static fn(int $score): string => match (true) {
            $score >= self::CRITICAL => $t('level.critical'),
            $score >= self::HIGH => $t('level.high'),
            $score >= self::MEDIUM => $t('level.medium'),
            default => $t('level.low'),
        };

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
                $level($riskScore),
                $risk->getResidualProbability(),
                $risk->getResidualImpact(),
                $residualScore,
                $level($residualScore),
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
        $spreadsheet = $excel->createSpreadsheet('Risk Management Report');

        // === TAB 1: Summary ===
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->setTitle('Zusammenfassung');
        $nextRow = $excel->addSummarySection($worksheet, [
            'Gesamt Risiken' => $counts['total'],
            'Kritische Risiken' => $counts['critical'],
            'Hohe Risiken' => $counts['high'],
            'Mittlere Risiken' => $counts['medium'],
            'Niedrige Risiken' => $counts['low'],
            'Export-Datum' => date('d.m.Y H:i'),
        ], 1, 'Risk Management Übersicht');

        $statusMetrics = [];
        foreach ([
            'Identifiziert' => RiskStatus::Identified,
            'Bewertet' => RiskStatus::Assessed,
            'Behandelt' => RiskStatus::Treated,
            'Überwacht' => RiskStatus::Monitored,
            'Geschlossen' => RiskStatus::Closed,
            'Akzeptiert' => RiskStatus::Accepted,
        ] as $label => $case) {
            $statusMetrics[$label] = count(array_filter($risks, static fn(Risk $r): bool => $r->getStatus() === $case));
        }
        $excel->addSummarySection($worksheet, $statusMetrics, $nextRow, 'Status-Verteilung');
        $excel->autoSizeColumns($worksheet);

        // === TAB 2: All Risks / TAB 3: Critical & High Risks ===
        $headers = [
            'ID', 'Titel', 'Asset', 'Wkt.', 'Ausw.', 'Score', 'Level',
            'Rest-Wkt.', 'Rest-Ausw.', 'Rest-Score', 'Rest-Level',
            'Strategie', 'Status', 'Owner', 'Erstellt',
        ];
        $levelColors = [
            'Kritisch' => $excel->getColor('critical'),
            'Hoch' => $excel->getColor('high'),
            'Mittel' => $excel->getColor('medium'),
            'Niedrig' => $excel->getColor('low'),
        ];
        // Conditional formatting for risk level (index 6) and residual level (index 10).
        $conditionalFormatting = [6 => $levelColors, 10 => $levelColors];

        $sheets = ['Alle Risiken' => $risks];
        $criticalHigh = array_filter($risks, static fn(Risk $r): bool => $r->getRiskScore() >= self::HIGH);
        if ($criticalHigh !== []) {
            $sheets['Kritische & Hohe Risiken'] = $criticalHigh;
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
            $counts[match (true) {
                $score >= self::CRITICAL => 'critical',
                $score >= self::HIGH => 'high',
                $score >= self::MEDIUM => 'medium',
                default => 'low',
            }]++;
        }

        return $counts;
    }

    /**
     * @return list<mixed>
     */
    private function excelRow(Risk $risk): array
    {
        $level = static fn(int $score): string => match (true) {
            $score >= self::CRITICAL => 'Kritisch',
            $score >= self::HIGH => 'Hoch',
            $score >= self::MEDIUM => 'Mittel',
            default => 'Niedrig',
        };
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
            match ($risk->getTreatmentStrategy()) {
                TreatmentStrategy::Accept => 'Akzeptieren',
                TreatmentStrategy::Mitigate => 'Mindern',
                TreatmentStrategy::Transfer => 'Übertragen',
                TreatmentStrategy::Avoid => 'Vermeiden',
                null => null,
            },
            match ($risk->getStatus()) {
                RiskStatus::Identified => 'Identifiziert',
                RiskStatus::Assessed => 'Bewertet',
                RiskStatus::InTreatment => 'In Behandlung',
                RiskStatus::Treated => 'Behandelt',
                RiskStatus::Mitigated => 'Mitigiert',
                RiskStatus::Monitored => 'Überwacht',
                RiskStatus::Closed => 'Geschlossen',
                RiskStatus::Accepted => 'Akzeptiert',
                RiskStatus::Open => 'Offen',
                null => null,
            },
            $risk->getRiskOwner() ? $risk->getRiskOwner()->getFullName() : '-',
            $risk->getCreatedAt() ? $risk->getCreatedAt()->format('d.m.Y') : '-',
        ];
    }

    /** Translated label, or the raw value when no translation exists. */
    private function translatedOrRaw(string $prefix, string $value): string
    {
        $translated = $this->translator->trans($prefix . $value, [], 'risk');

        return $translated !== $prefix . $value ? $translated : $value;
    }
}
