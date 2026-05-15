<?php

declare(strict_types=1);

namespace App\Service\Fte;

use App\Entity\Tenant;
use App\Repository\Fte\FteTrackingMetricRepository;
use App\Service\PdfExportService;
use DateInterval;
use DateTimeImmutable;
use Twig\Environment;

/**
 * F11 FTE-Tracking — board-ready monthly report generator.
 *
 * Produces three output formats from the same structured data:
 *   - PDF   → via DomPDF (PdfExportService)
 *   - HTML  → Twig-rendered board_report.html.twig
 *   - CSV   → hand-built RFC 4180 CSV (no library dependency)
 */
class BoardReportGenerator
{
    public function __construct(
        private readonly FteTrackingMetricRepository $metricRepo,
        private readonly PdfExportService $pdfExportService,
        private readonly Environment $twig,
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
     *     monthly_trend: array<string, int>
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
        $lines = [];
        $lines[] = $this->csvRow(['FTE Savings Report', $data['month_label'], 'Generated: ' . $data['generated_at']->format('Y-m-d H:i')]);
        $lines[] = $this->csvRow(['Tenant', $data['tenant']->getName() ?? 'N/A']);
        $lines[] = $this->csvRow([]);
        $lines[] = $this->csvRow(['Total Savings (minutes)', $data['totals']['savings_minutes']]);
        $lines[] = $this->csvRow(['Total Savings (hours)', $data['totals']['savings_hours']]);
        $lines[] = $this->csvRow([]);
        $lines[] = $this->csvRow(['Source', 'Savings (minutes)']);

        foreach ($data['by_source'] as $source => $minutes) {
            $lines[] = $this->csvRow([$source, $minutes]);
        }

        $lines[] = $this->csvRow([]);
        $lines[] = $this->csvRow(['Month', 'Savings (minutes)']);

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
            static fn ($cell): string => '"' . str_replace('"', '""', (string) $cell) . '"',
            $cells
        );

        return implode(',', $escaped);
    }
}
