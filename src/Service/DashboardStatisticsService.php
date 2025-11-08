<?php

namespace App\Service;

use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;

/**
 * Dashboard Statistics Service
 *
 * Centralizes dashboard metrics calculation and business logic.
 * Follows Symfony best practice: keep controllers thin, move logic to services.
 *
 * Responsibilities:
 * - Calculate KPI metrics
 * - Compute statistics for dashboard widgets
 * - Filter and count critical/high-risk items
 * - Calculate compliance percentages
 *
 * Benefits:
 * - Testable business logic
 * - Reusable across different controllers
 * - Cleaner controller code
 * - Single source of truth for metrics
 */
class DashboardStatisticsService
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly ControlRepository $controlRepository
    ) {
    }

    /**
     * Get all dashboard statistics
     *
     * @return array{
     *     assetCount: int,
     *     riskCount: int,
     *     openIncidentCount: int,
     *     compliancePercentage: int,
     *     assets_total: int,
     *     assets_critical: int,
     *     risks_total: int,
     *     risks_high: int,
     *     controls_total: int,
     *     controls_implemented: int,
     *     incidents_open: int
     * }
     */
    public function getDashboardStatistics(): array
    {
        // Basic counts
        $assetCount = count($this->assetRepository->findActiveAssets());
        $riskCount = count($this->riskRepository->findAll());
        $openIncidentCount = count($this->incidentRepository->findOpenIncidents());

        // Control statistics
        $applicableControls = $this->controlRepository->findApplicableControls();
        $implementedControls = $this->countImplementedControls($applicableControls);
        $compliancePercentage = $this->calculateCompliancePercentage(
            $implementedControls,
            count($applicableControls)
        );

        // Critical/High items
        $criticalAssetCount = $this->countCriticalAssets();
        $highRiskCount = $this->countHighRisks();

        return [
            // Basic KPIs
            'assetCount' => $assetCount,
            'riskCount' => $riskCount,
            'openIncidentCount' => $openIncidentCount,
            'compliancePercentage' => $compliancePercentage,

            // Detailed statistics
            'assets_total' => $assetCount,
            'assets_critical' => $criticalAssetCount,
            'risks_total' => $riskCount,
            'risks_high' => $highRiskCount,
            'controls_total' => count($applicableControls),
            'controls_implemented' => $implementedControls,
            'incidents_open' => $openIncidentCount,
        ];
    }

    /**
     * Count critical assets (confidentiality value >= 4)
     *
     * @return int Number of critical assets
     */
    private function countCriticalAssets(): int
    {
        $activeAssets = $this->assetRepository->findActiveAssets();

        return count(array_filter(
            $activeAssets,
            fn($asset) => $asset->getConfidentialityValue() >= 4
        ));
    }

    /**
     * Count high-risk items (inherent risk level >= 12)
     *
     * @return int Number of high risks
     */
    private function countHighRisks(): int
    {
        $allRisks = $this->riskRepository->findAll();

        return count(array_filter(
            $allRisks,
            fn($risk) => $risk->getInherentRiskLevel() >= 12
        ));
    }

    /**
     * Count implemented controls from applicable controls
     *
     * @param array $applicableControls List of applicable controls
     * @return int Number of implemented controls
     */
    private function countImplementedControls(array $applicableControls): int
    {
        return count(array_filter(
            $applicableControls,
            fn($control) => $control->getImplementationStatus() === 'implemented'
        ));
    }

    /**
     * Calculate compliance percentage
     *
     * @param int $implementedCount Number of implemented controls
     * @param int $totalCount Total number of applicable controls
     * @return int Compliance percentage (0-100)
     */
    private function calculateCompliancePercentage(int $implementedCount, int $totalCount): int
    {
        if ($totalCount === 0) {
            return 0;
        }

        return (int) round(($implementedCount / $totalCount) * 100);
    }
}
