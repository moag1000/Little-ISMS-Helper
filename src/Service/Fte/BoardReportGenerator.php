<?php

declare(strict_types=1);

namespace App\Service\Fte;

use App\Entity\Tenant;
use App\Repository\Fte\FteTrackingMetricRepository;
use App\Service\ModuleConfigurationService;
use App\Service\PdfExportService;
use App\Service\RiskQuantSummaryInterface;
use App\Twig\AppDateFormatExtension;
use DateInterval;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * FTE-Tracking — board-ready monthly report generator.
 *
 * Produces three output formats from the same structured data:
 *   - PDF   → via DomPDF (PdfExportService)
 *   - HTML  → Twig-rendered board_report.html.twig
 *   - CSV   → hand-built RFC 4180 CSV (no library dependency)
 *
 * When module 'risk_quant' is active, the report data includes
 * aggregate ALE (Annual Loss Expectancy) across all tenant risks.
 */
final class BoardReportGenerator
{
    public function __construct(
        private readonly FteTrackingMetricRepository $metricRepo,
        private readonly PdfExportService $pdfExportService,
        private readonly Environment $twig,
        private readonly RiskQuantSummaryInterface $riskQuantSummary,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly TranslatorInterface $translator,
        private readonly AppDateFormatExtension $dateFormat,
    ) {
    }

    /**
     * Build structured report data for a given calendar month.
     *
     * @return array{
     *     tenant: Tenant,
     *     month: DateTimeImmutable,
     *     month_label: string,
     *     generated_at: DateTimeImmutable,
     *     totals: array{savings_minutes: int, savings_hours: float},
     *     by_source: array<string, int>,
     *     monthly_trend: array<string, int>,
     *     risk_quant_active: bool,
     *     risk_quant: array{total_ale_eur: int, quantified_risk_count: int}
     * }
     */
    public function generateMonthly(Tenant $tenant, DateTimeImmutable $month): array
    {
        // Rolling 30-day window for the aggregate
        $monthStart = $month->modify('first day of this month midnight');
        $monthEnd = $month->modify('last day of this month 23:59:59');

        // Days in month for window
        $daysInMonth = (int) $monthStart->format('t');
        $window = new DateInterval("P{$daysInMonth}D");

        // Temporarily use a fixed-window aggregate based on month boundaries
        $totalSavings = $this->metricRepo->getSavingsAggregate($tenant, $window);
        $bySource = $this->metricRepo->getSavingsBySource($tenant);
        $trend = $this->metricRepo->getMonthlyTrend($tenant, 12);

        // Aggregate ALE when risk_quant module is active
        $riskQuantActive = $this->moduleConfiguration->isModuleActive('risk_quant');
        $riskQuantSummary = $riskQuantActive
            ? $this->riskQuantSummary->getRiskQuantSummary($tenant)
            : ['total_ale_eur' => 0, 'quantified_risk_count' => 0];

        return [
            'tenant' => $tenant,
            'month' => $month,
            'month_label' => $month->format('F Y'),
            'generated_at' => new DateTimeImmutable(),
            'totals' => [
                'savings_minutes' => $totalSavings,
                'savings_hours' => round($totalSavings / 60, 1),
            ],
            'by_source' => $bySource,
            'monthly_trend' => $trend,
            'risk_quant_active' => $riskQuantActive,
            'risk_quant' => $riskQuantSummary,
        ];
    }

    /**
     * Render report as PDF binary string.
     */
    public function renderAsPdf(array $data): string
    {
        return $this->pdfExportService->generatePdf(
            'dashboard/fte_tracking/board_report.html.twig',
            $data,
            ['watermark' => 'BOARD REPORT']
        );
    }

    /**
     * Render report as Twig HTML string.
     */
    public function renderAsHtml(array $data): string
    {
        return $this->twig->render('dashboard/fte_tracking/board_report.html.twig', $data);
    }

    /**
     * Render report as RFC 4180 CSV string.
     */
    public function renderAsCsv(array $data): string
    {
        $t = fn (string $key, array $params = []): string => $this->translator->trans(
            'fte_tracking.csv.' . $key,
            $params,
            'fte_tracking',
        );
        $lines = [];
        $lines[] = $this->csvRow([
            $t('title'),
            $data['month_label'],
            $t('generated', ['%date%' => $this->dateFormat->formatDateTime($data['generated_at'])]),
        ]);
        $lines[] = $this->csvRow([$t('tenant'), $data['tenant']->getName() ?? $t('tenant_unknown')]);
        $lines[] = $this->csvRow([]);
        $lines[] = $this->csvRow([$t('total_minutes'), $data['totals']['savings_minutes']]);
        $lines[] = $this->csvRow([$t('total_hours'), $data['totals']['savings_hours']]);

        // Include ALE aggregate when module is active
        if (!empty($data['risk_quant_active'])) {
            $lines[] = $this->csvRow([]);
            $lines[] = $this->csvRow([$t('ale_title'), '']);
            $lines[] = $this->csvRow([$t('ale_total'), $data['risk_quant']['total_ale_eur']]);
            $lines[] = $this->csvRow([$t('ale_count'), $data['risk_quant']['quantified_risk_count']]);
        }

        $lines[] = $this->csvRow([]);
        $lines[] = $this->csvRow([$t('source'), $t('minutes')]);

        foreach ($data['by_source'] as $source => $minutes) {
            $lines[] = $this->csvRow([$source, $minutes]);
        }

        $lines[] = $this->csvRow([]);
        $lines[] = $this->csvRow([$t('month'), $t('minutes')]);

        foreach ($data['monthly_trend'] as $monthKey => $minutes) {
            $lines[] = $this->csvRow([$monthKey, $minutes]);
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param array<int|float|string> $cells
     */
    private function csvRow(array $cells): string
    {
        $escaped = array_map(
            static fn ($cell): string => '"' . str_replace('"', '""', (string) \App\Util\CsvSanitizer::sanitize((string) $cell)) . '"',
            $cells
        );

        return implode(',', $escaped);
    }
}
