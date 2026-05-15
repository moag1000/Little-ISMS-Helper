<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Rollup;

use App\Entity\Tenant;
use App\Entity\TenantBranding;
use App\Repository\TenantBrandingRepository;
use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment as TwigEnvironment;

/**
 * CISO-Executive Reporting (Task #130) — One-Pager PDF for board
 * meetings.
 *
 * Renders a single-page A4-portrait summary covering:
 *   - Konzern name + stichtag (cut-off date)
 *   - Konzern compliance score (mean across subsidiaries)
 *   - Acknowledgement coverage
 *   - Outstanding actions count + drift count
 *   - Top-3 risks (highest-severity outstanding actions)
 *   - Top-3 subsidiaries with the largest compliance gap
 *   - 4-quarter trend mini chart (inline SVG)
 *   - TenantBranding letterhead (logo + colors + footer html), shared
 *     plumbing with {@see \App\Service\PolicyWizard\Export\PolicyPdfExporter}.
 *
 * The €/ALE column is currently null (skeleton — coupling point
 * documented in {@see KonzernTrendCalculator::calculateQuarterlyTrend}).
 */
final class KonzernOnePagerPdfService
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly KonzernRollupAggregator $rollupAggregator,
        private readonly KonzernTrendCalculator $trendCalculator,
        private readonly ?TenantBrandingRepository $brandingRepository = null,
    ) {
    }

    /**
     * Render the One-Pager as HTML — useful for preview routes and for
     * the test-suite that does not want to round-trip through dompdf.
     */
    public function renderOnePager(
        Tenant $konzernRoot,
        ?DateTimeImmutable $asOfDate = null,
    ): string {
        $asOf = $asOfDate ?? new DateTimeImmutable();
        $context = $this->buildContext($konzernRoot, $asOf);

        return $this->twig->render(
            'policy_wizard/konzern_rollup/_one_pager_pdf.html.twig',
            $context,
        );
    }

    /**
     * Render the One-Pager as a PDF binary (string).
     */
    public function exportPdf(
        Tenant $konzernRoot,
        ?DateTimeImmutable $asOfDate = null,
    ): string {
        $html = $this->renderOnePager($konzernRoot, $asOfDate);
        return $this->renderPdf($html);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(Tenant $konzernRoot, DateTimeImmutable $asOf): array
    {
        $branding = $this->resolveBranding($konzernRoot);
        $rollup = $this->rollupAggregator->aggregateForKonzern($konzernRoot);
        $trend = $this->trendCalculator->calculateQuarterlyTrend(
            konzernRoot: $konzernRoot,
            quartersBack: 4,
            asOfDate: $asOf,
        );

        // Top-3 risks = highest-severity outstanding actions, in order
        // (the aggregator already sorts danger > warning > info).
        $topRisks = array_slice($rollup->outstandingActions, 0, 3);

        // Top-3 compliance-gap subsidiaries = lowest score first.
        $worstCompliance = $rollup->complianceScore;
        usort($worstCompliance, static function (array $a, array $b): int {
            return ((float) $a['score_percentage']) <=> ((float) $b['score_percentage']);
        });
        $topGaps = array_slice($worstCompliance, 0, 3);

        $konzernScoreMean = $this->mean(array_map(
            static fn (array $row): float => (float) ($row['score_percentage'] ?? 0.0),
            $rollup->complianceScore,
        ));
        $ackCoverageMean = $this->mean(array_map(
            static fn (array $row): float => (float) ($row['coverage_percentage'] ?? 0.0),
            $rollup->acknowledgmentCoverage,
        ));

        $sparkline = $this->buildSparkline(
            $trend->konzernAverage['compliance_scores'] ?? [],
            $trend->quarters,
        );

        return [
            'konzern'             => $konzernRoot,
            'as_of'               => $asOf->format('Y-m-d'),
            'subsidiary_count'    => $rollup->subsidiaryCount,
            'konzern_score_mean'  => round($konzernScoreMean, 1),
            'ack_coverage_mean'   => round($ackCoverageMean, 1),
            'outstanding_count'   => count($rollup->outstandingActions),
            'drift_count'         => count($rollup->settingsDriftRows),
            'top_risks'           => $topRisks,
            'top_compliance_gaps' => $topGaps,
            'trend'               => $trend,
            'sparkline_svg'       => $sparkline,
            'estimated_ale_eur'   => $trend->estimatedAleEur, // SKELETON — null OK
            'branding'            => $branding,
            'primary'             => $branding?->getPrimaryColor() ?? '#0d6efd',
            'secondary'           => $branding?->getSecondaryColor() ?? '#6c757d',
            'font_family'         => $branding?->getFontFamily() ?? 'Inter',
            'logo_path'           => $branding?->getLogoPath(),
            'header_html'         => $branding?->getHeaderHtml(),
            'footer_html'         => $branding?->getFooterHtml(),
            'app_version'         => $this->resolveAppVersion(),
        ];
    }

    private function resolveBranding(Tenant $tenant): ?TenantBranding
    {
        if ($this->brandingRepository === null) {
            return null;
        }
        return $this->brandingRepository->findOneByTenant($tenant);
    }

    /**
     * Inline-SVG sparkline: tiny, dependency-free, prints great in
     * dompdf (no JS needed). Returns an empty string when there is
     * no data so the template can hide the panel.
     *
     * @param list<int|float> $values
     * @param list<string> $labels
     */
    private function buildSparkline(array $values, array $labels): string
    {
        $values = array_values(array_map('floatval', $values));
        if (count($values) < 2) {
            return '';
        }
        $width = 280;
        $height = 60;
        $padX = 6;
        $padY = 6;

        $min = min($values);
        $max = max($values);
        if ($max - $min < 0.0001) {
            $max = $min + 1;
        }
        $stepX = ($width - 2 * $padX) / (count($values) - 1);

        $points = [];
        foreach ($values as $i => $v) {
            $x = $padX + $stepX * $i;
            $y = $height - $padY - (($v - $min) / ($max - $min)) * ($height - 2 * $padY);
            $points[] = sprintf('%.1f,%.1f', $x, $y);
        }

        $polyline = htmlspecialchars(implode(' ', $points), ENT_QUOTES, 'UTF-8');
        $lastX = (int) ($padX + $stepX * (count($values) - 1));
        $lastY = (int) ($height - $padY - (($values[count($values) - 1] - $min) / ($max - $min)) * ($height - 2 * $padY));

        $firstLabel = htmlspecialchars($labels[0] ?? '', ENT_QUOTES, 'UTF-8');
        $lastLabel = htmlspecialchars($labels[count($labels) - 1] ?? '', ENT_QUOTES, 'UTF-8');

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="{$width}" height="{$height}" viewBox="0 0 {$width} {$height}">
    <polyline fill="none" stroke="#0d6efd" stroke-width="2" points="{$polyline}"/>
    <circle cx="{$lastX}" cy="{$lastY}" r="3" fill="#0d6efd"/>
    <text x="2" y="{$height}" font-size="8" fill="#666">{$firstLabel}</text>
    <text x="{$width}" y="{$height}" font-size="8" fill="#666" text-anchor="end">{$lastLabel}</text>
</svg>
SVG;
    }

    /**
     * @param list<float> $values
     */
    private function mean(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        return array_sum($values) / count($values);
    }

    private function renderPdf(string $html): string
    {
        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultPaperSize', 'A4');
        $options->set('defaultFont', 'Helvetica');
        $options->set('isFontSubsettingEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function resolveAppVersion(): string
    {
        $candidate = dirname(__DIR__, 4) . '/composer.json';
        if (!is_file($candidate)) {
            return 'dev';
        }
        $raw = @file_get_contents($candidate);
        if (!is_string($raw)) {
            return 'dev';
        }
        $data = json_decode($raw, true);
        if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
            return $data['version'];
        }
        return 'dev';
    }
}
