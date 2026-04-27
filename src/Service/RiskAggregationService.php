<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\RiskRepository;

/**
 * Risk Aggregation Service (ISO 31000 Section 6.4.4)
 *
 * Provides portfolio-level risk views for aggregated risk analysis.
 * Supports risk portfolio summaries, correlated risk identification,
 * and heatmap data generation for visualization.
 *
 * Features:
 * - Portfolio-level risk aggregation with multi-dimensional breakdown
 * - Correlated risk detection (risks sharing the same asset)
 * - Heatmap data generation for 5x5 risk matrix visualization
 * - Trend analysis based on risk status distribution
 * - Tenant-aware queries via TenantContext
 */
class RiskAggregationService
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly AssetRepository $assetRepository,
        private readonly TenantContext $tenantContext,
        private readonly RiskMatrixService $riskMatrixService,
    ) {}

    /**
     * Get aggregated risk portfolio view per ISO 31000 Section 6.4.4
     *
     * Returns a comprehensive risk overview including breakdowns by level,
     * category, treatment strategy, and status, plus top risks and trend analysis.
     *
     * @param Tenant|null $tenant Tenant to scope to; uses TenantContext if null
     * @return array{
     *     total_risks: int,
     *     by_level: array{critical: int, high: int, medium: int, low: int},
     *     by_category: array<string, int>,
     *     by_treatment: array{accept: int, mitigate: int, transfer: int, avoid: int},
     *     by_status: array<string, int>,
     *     top_risks: Risk[],
     *     average_risk_score: float,
     *     risk_trend: string,
     * }
     */
    public function getRiskPortfolio(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenantContext->getCurrentTenant();

        $risks = $tenant instanceof Tenant
            ? $this->riskRepository->findByTenant($tenant)
            : $this->riskRepository->findAll();

        $byLevel = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $byCategory = [];
        $byTreatment = ['accept' => 0, 'mitigate' => 0, 'transfer' => 0, 'avoid' => 0];
        $byStatus = [];
        $totalScore = 0;

        foreach ($risks as $risk) {
            // By level (using RiskMatrixService for consistent classification)
            $level = $this->riskMatrixService->calculateRiskLevel(
                $risk->getProbability() ?? 1,
                $risk->getImpact() ?? 1,
            );
            if (isset($byLevel[$level])) {
                $byLevel[$level]++;
            }

            // By category
            $category = $risk->getCategory() ?? 'uncategorized';
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;

            // By treatment strategy
            $treatment = $risk->getTreatmentStrategy()?->value ?? 'mitigate';
            if (isset($byTreatment[$treatment])) {
                $byTreatment[$treatment]++;
            }

            // By status
            $status = $risk->getStatus()?->value ?? 'identified';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            // Accumulate score for average
            $totalScore += $risk->getRiskScore();
        }

        $totalRisks = count($risks);
        $averageScore = $totalRisks > 0 ? round($totalScore / $totalRisks, 2) : 0.0;

        // Top 10 risks by score (descending)
        $sortedRisks = $risks;
        usort($sortedRisks, static fn (Risk $a, Risk $b): int => $b->getRiskScore() <=> $a->getRiskScore());
        $topRisks = array_slice($sortedRisks, 0, 10);

        // Risk trend analysis based on status distribution
        $riskTrend = $this->calculateRiskTrend($risks, $byLevel);

        return [
            'total_risks' => $totalRisks,
            'by_level' => $byLevel,
            'by_category' => $byCategory,
            'by_treatment' => $byTreatment,
            'by_status' => $byStatus,
            'top_risks' => $topRisks,
            'average_risk_score' => $averageScore,
            'risk_trend' => $riskTrend,
        ];
    }

    /**
     * Find correlated risks that share the same asset
     *
     * Identifies risk concentration on single assets, which is critical for
     * understanding cascading failure scenarios per ISO 31000 Section 6.4.4.
     *
     * @param Tenant|null $tenant Tenant to scope to; uses TenantContext if null
     * @return array<int, array{asset: \App\Entity\Asset, risks: Risk[], combined_score: int}>
     */
    public function getCorrelatedRisks(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenantContext->getCurrentTenant();

        $risks = $tenant instanceof Tenant
            ? $this->riskRepository->findByTenant($tenant)
            : $this->riskRepository->findAll();

        // Group risks by asset
        $assetGroups = [];
        foreach ($risks as $risk) {
            $asset = $risk->getAsset();
            if ($asset === null) {
                continue;
            }

            $assetId = $asset->getId();
            if (!isset($assetGroups[$assetId])) {
                $assetGroups[$assetId] = [
                    'asset' => $asset,
                    'risks' => [],
                    'combined_score' => 0,
                ];
            }

            $assetGroups[$assetId]['risks'][] = $risk;
            $assetGroups[$assetId]['combined_score'] += $risk->getRiskScore();
        }

        // Filter to only assets with multiple risks (actual correlation)
        $correlated = array_filter(
            $assetGroups,
            static fn (array $group): bool => count($group['risks']) > 1,
        );

        // Sort by combined_score descending
        usort($correlated, static fn (array $a, array $b): int => $b['combined_score'] <=> $a['combined_score']);

        return array_values($correlated);
    }

    /**
     * Get risk heatmap data for portfolio-level visualization
     *
     * Returns a 5x5 matrix with risk counts per cell, suitable for
     * rendering a heatmap chart (probability on Y axis, impact on X axis).
     *
     * @param Tenant|null $tenant Tenant to scope to; uses TenantContext if null
     * @return array{matrix: array<int, array<int, int>>, total: int}
     */
    public function getRiskHeatmapData(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenantContext->getCurrentTenant();

        $risks = $tenant instanceof Tenant
            ? $this->riskRepository->findByTenant($tenant)
            : $this->riskRepository->findAll();

        // Initialize 5x5 matrix with zeros
        $matrix = [];
        for ($probability = 1; $probability <= 5; $probability++) {
            for ($impact = 1; $impact <= 5; $impact++) {
                $matrix[$probability][$impact] = 0;
            }
        }

        // Populate matrix with risk counts
        foreach ($risks as $risk) {
            $probability = max(1, min(5, $risk->getProbability() ?? 1));
            $impact = max(1, min(5, $risk->getImpact() ?? 1));
            $matrix[$probability][$impact]++;
        }

        return [
            'matrix' => $matrix,
            'total' => count($risks),
        ];
    }

    /**
     * Calculate risk trend based on current risk distribution
     *
     * Analyzes the proportion of high/critical risks vs treated/closed risks
     * to determine whether the overall risk posture is improving or deteriorating.
     *
     * @param Risk[] $risks All risks to analyze
     * @param array{critical: int, high: int, medium: int, low: int} $byLevel Risk counts by level
     * @return string 'increasing'|'decreasing'|'stable'
     */
    private function calculateRiskTrend(array $risks, array $byLevel): string
    {
        $totalRisks = count($risks);
        if ($totalRisks === 0) {
            return 'stable';
        }

        // Count risks in active treatment or closed states
        $treatedOrClosed = 0;
        $identifiedOrAssessed = 0;
        foreach ($risks as $risk) {
            $status = $risk->getStatus();
            if (in_array($status, ['treated', 'monitored', 'closed', 'accepted'], true)) {
                $treatedOrClosed++;
            } elseif (in_array($status, ['identified', 'assessed'], true)) {
                $identifiedOrAssessed++;
            }
        }

        // Calculate high-risk ratio
        $highRiskCount = $byLevel['critical'] + $byLevel['high'];
        $highRiskRatio = $highRiskCount / $totalRisks;

        // If majority of risks are treated/closed and low high-risk ratio -> decreasing
        if ($treatedOrClosed > $identifiedOrAssessed && $highRiskRatio < 0.3) {
            return 'decreasing';
        }

        // If majority are untreated and high-risk ratio is significant -> increasing
        if ($identifiedOrAssessed > $treatedOrClosed && $highRiskRatio > 0.5) {
            return 'increasing';
        }

        return 'stable';
    }
}
