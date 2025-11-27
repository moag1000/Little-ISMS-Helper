<?php

namespace App\Service;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Service\AssetService;
use App\Service\RiskService;
use Symfony\Bundle\SecurityBundle\Security;

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
        private readonly ControlRepository $controlRepository,
        private readonly Security $security,
        private readonly ?AssetService $assetService = null,
        private readonly ?RiskService $riskService = null
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
        // Get current tenant from authenticated user
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Basic counts - show ALL accessible entities (own + inherited + subsidiaries)
        if ($tenant) {
            // Get all accessible assets (own + inherited from parent + from subsidiaries)
            $allAccessibleAssets = $this->getAllAccessibleAssets($tenant);
            $activeAssets = array_filter($allAccessibleAssets, fn($asset): bool => $asset->getStatus() === 'active');
            $assetCount = count($activeAssets);

            // Get all accessible risks
            $allAccessibleRisks = $this->getAllAccessibleRisks($tenant);
            $riskCount = count($allAccessibleRisks);

            // Get all accessible incidents
            $allAccessibleIncidents = $this->getAllAccessibleIncidents($tenant);
            $openIncidents = array_filter($allAccessibleIncidents, fn($incident): bool => $incident->getStatus() === 'open');
            $openIncidentCount = count($openIncidents);
        } else {
            // Fallback for users without tenant (admin view)
            $activeAssets = $this->assetRepository->findActiveAssets();
            $assetCount = count($activeAssets);
            $allAccessibleRisks = $this->riskRepository->findAll();
            $riskCount = count($allAccessibleRisks);
            $openIncidentCount = count($this->incidentRepository->findOpenIncidents());
        }

        // Control statistics (controls are global, not tenant-specific)
        $applicableControls = $this->controlRepository->findApplicableControls();
        $implementedControls = $this->countImplementedControls($applicableControls);
        $compliancePercentage = $this->calculateCompliancePercentage(
            $implementedControls,
            count($applicableControls)
        );

        // Critical/High items - using all accessible data
        $criticalAssetCount = $tenant
            ? $this->countCriticalAssetsAccessible($tenant)
            : $this->countCriticalAssets();
        $highRiskCount = $tenant
            ? $this->countHighRisksAccessible($tenant)
            : $this->countHighRisks();

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
            fn(Asset $asset): bool => $asset->getConfidentialityValue() >= 4
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
            fn(Risk $risk): bool => $risk->getInherentRiskLevel() >= 12
        ));
    }

    /**
     * Get all accessible assets for a tenant (own + inherited + subsidiaries)
     *
     * @param Tenant $tenant The tenant
     * @return array All accessible assets
     */
    private function getAllAccessibleAssets(Tenant $tenant): array
    {
        $allAssets = [];

        // Own assets
        $ownAssets = $this->assetRepository->findByTenant($tenant);
        foreach ($ownAssets as $asset) {
            $allAssets[$asset->getId()] = $asset;
        }

        // Inherited from parent (if AssetService available)
        if ($this->assetService instanceof AssetService) {
            $inheritedAssets = $this->assetService->getAssetsForTenant($tenant);
            foreach ($inheritedAssets as $inheritedAsset) {
                $allAssets[$inheritedAsset->getId()] = $inheritedAsset;
            }
        }

        // From subsidiaries
        $subsidiaryAssets = $this->assetRepository->findByTenantIncludingSubsidiaries($tenant);
        foreach ($subsidiaryAssets as $subsidiaryAsset) {
            $allAssets[$subsidiaryAsset->getId()] = $subsidiaryAsset;
        }

        return array_values($allAssets);
    }

    /**
     * Get all accessible risks for a tenant (own + inherited + subsidiaries)
     *
     * @param Tenant $tenant The tenant
     * @return array All accessible risks
     */
    private function getAllAccessibleRisks(Tenant $tenant): array
    {
        $allRisks = [];

        // Own risks
        $ownRisks = $this->riskRepository->findByTenant($tenant);
        foreach ($ownRisks as $risk) {
            $allRisks[$risk->getId()] = $risk;
        }

        // Inherited from parent (if RiskService available)
        if ($this->riskService instanceof RiskService) {
            $inheritedRisks = $this->riskService->getRisksForTenant($tenant);
            foreach ($inheritedRisks as $inheritedRisk) {
                $allRisks[$inheritedRisk->getId()] = $inheritedRisk;
            }
        }

        // From subsidiaries
        $subsidiaryRisks = $this->riskRepository->findByTenantIncludingSubsidiaries($tenant);
        foreach ($subsidiaryRisks as $subsidiaryRisk) {
            $allRisks[$subsidiaryRisk->getId()] = $subsidiaryRisk;
        }

        return array_values($allRisks);
    }

    /**
     * Get all accessible incidents for a tenant (own + inherited + subsidiaries)
     *
     * @param Tenant $tenant The tenant
     * @return array All accessible incidents
     */
    private function getAllAccessibleIncidents(Tenant $tenant): array
    {
        $allIncidents = [];

        // Own incidents
        $ownIncidents = $this->incidentRepository->findByTenant($tenant);
        foreach ($ownIncidents as $incident) {
            $allIncidents[$incident->getId()] = $incident;
        }

        // Inherited from parent
        if ($tenant->getParent()) {
            $parentIncidents = $this->incidentRepository->findByTenantIncludingParent($tenant, $tenant->getParent());
            foreach ($parentIncidents as $parentIncident) {
                $allIncidents[$parentIncident->getId()] = $parentIncident;
            }
        }

        // From subsidiaries
        $subsidiaryIncidents = $this->incidentRepository->findByTenantIncludingSubsidiaries($tenant);
        foreach ($subsidiaryIncidents as $subsidiaryIncident) {
            $allIncidents[$subsidiaryIncident->getId()] = $subsidiaryIncident;
        }

        return array_values($allIncidents);
    }

    /**
     * Count critical assets from all accessible assets
     *
     * @param Tenant $tenant The tenant
     * @return int Number of critical assets
     */
    private function countCriticalAssetsAccessible(Tenant $tenant): int
    {
        $allAssets = $this->getAllAccessibleAssets($tenant);
        $activeAssets = array_filter($allAssets, fn($asset): bool => $asset->getStatus() === 'active');

        return count(array_filter(
            $activeAssets,
            fn($asset): bool => $asset->getConfidentialityValue() >= 4
        ));
    }

    /**
     * Count high-risk items from all accessible risks
     *
     * @param Tenant $tenant The tenant
     * @return int Number of high risks
     */
    private function countHighRisksAccessible(Tenant $tenant): int
    {
        $allRisks = $this->getAllAccessibleRisks($tenant);

        return count(array_filter(
            $allRisks,
            fn($risk): bool => $risk->getInherentRiskLevel() >= 12
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
            fn($control): bool => $control->getImplementationStatus() === 'implemented'
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
