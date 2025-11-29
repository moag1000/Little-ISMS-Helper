<?php

namespace App\Service;

use App\Entity\CorporateGovernance;
use App\Enum\GovernanceModel;
use App\Entity\Asset;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use App\Repository\CorporateGovernanceRepository;

/**
 * Asset Service - Business logic for Asset Management with Corporate Structure awareness
 */
class AssetService
{
    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly ?CorporateStructureService $corporateStructureService = null,
        private readonly ?CorporateGovernanceRepository $corporateGovernanceRepository = null
    ) {}

    /**
     * Get all assets visible to a tenant (own + inherited based on governance)
     *
     * @param Tenant $tenant The tenant
     * @return Asset[] Array of assets
     */
    public function getAssetsForTenant(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        // No parent or no corporate structure service - return own assets only
        if (!$parent instanceof Tenant || !$this->corporateStructureService instanceof CorporateStructureService || !$this->corporateGovernanceRepository instanceof CorporateGovernanceRepository) {
            return $this->assetRepository->findByTenant($tenant);
        }

        // Check governance model for assets
        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, 'asset');

        if (!$governance instanceof CorporateGovernance) {
            // No specific governance for assets - use default
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();

        // If hierarchical governance, include parent assets
        if ($governanceModel instanceof GovernanceModel && $governanceModel->value === 'hierarchical') {
            return $this->assetRepository->findByTenantIncludingParent($tenant, $parent);
        }

        // For shared or independent, return only own assets
        return $this->assetRepository->findByTenant($tenant);
    }

    /**
     * Get asset inheritance information for a tenant
     *
     * @param Tenant $tenant The tenant
     * @return array{hasParent: bool, canInherit: bool, governanceModel: string|null}
     */
    public function getAssetInheritanceInfo(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        if (!$parent instanceof Tenant || !$this->corporateGovernanceRepository instanceof CorporateGovernanceRepository) {
            return [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
            ];
        }

        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, 'asset');

        if (!$governance instanceof CorporateGovernance) {
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();
        $canInherit = $governanceModel instanceof GovernanceModel && $governanceModel->value === 'hierarchical';

        return [
            'hasParent' => true,
            'canInherit' => $canInherit,
            'governanceModel' => $governanceModel?->value,
        ];
    }

    /**
     * Check if an asset is inherited from parent
     *
     * @param Asset $asset The asset to check
     * @param Tenant $currentTenant The current tenant viewing the asset
     * @return bool True if asset belongs to parent tenant
     */
    public function isInheritedAsset(Asset $asset, Tenant $currentTenant): bool
    {
        $assetTenant = $asset->getTenant();

        if (!$assetTenant instanceof Tenant) {
            return false;
        }

        $assetTenantId = $assetTenant->getId();
        $currentTenantId = $currentTenant->getId();

        // Explicit null handling for unsaved entities
        if ($assetTenantId === null || $currentTenantId === null) {
            return false;
        }

        return $assetTenantId !== $currentTenantId;
    }

    /**
     * Check if user can edit an asset (not inherited)
     *
     * @param Asset $asset The asset
     * @param Tenant $currentTenant The current tenant
     * @return bool True if asset can be edited
     */
    public function canEditAsset(Asset $asset, Tenant $currentTenant): bool
    {
        return !$this->isInheritedAsset($asset, $currentTenant);
    }

    /**
     * Get asset statistics for a tenant including inherited assets
     *
     * @param Tenant $tenant The tenant
     * @return array{total: int, active: int, inactive: int, ownAssets: int, inheritedAssets: int}
     */
    public function getAssetStatsWithInheritance(Tenant $tenant): array
    {
        $allAssets = $this->getAssetsForTenant($tenant);
        $ownAssets = $this->assetRepository->findByTenant($tenant);

        $stats = $this->assetRepository->getAssetStatsByTenant($tenant);
        $stats['ownAssets'] = count($ownAssets);
        $stats['inheritedAssets'] = count($allAssets) - count($ownAssets);

        return $stats;
    }
}
