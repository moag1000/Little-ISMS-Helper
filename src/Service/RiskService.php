<?php

namespace App\Service;

use App\Entity\CorporateGovernance;
use App\Enum\GovernanceModel;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\RiskRepository;
use App\Repository\CorporateGovernanceRepository;

/**
 * Risk Service - Business logic for Risk Management with Corporate Structure awareness
 */
class RiskService
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly ?CorporateStructureService $corporateStructureService = null,
        private readonly ?CorporateGovernanceRepository $corporateGovernanceRepository = null
    ) {}

    /**
     * Get all risks visible to a tenant (own + inherited based on governance)
     *
     * @param Tenant $tenant The tenant
     * @return Risk[] Array of risks
     */
    public function getRisksForTenant(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        // No parent or no corporate structure service - return own risks only
        if (!$parent instanceof Tenant || !$this->corporateStructureService instanceof CorporateStructureService || !$this->corporateGovernanceRepository instanceof CorporateGovernanceRepository) {
            return $this->riskRepository->findByTenant($tenant);
        }

        // Check governance model for risks
        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, 'risk');

        if (!$governance instanceof CorporateGovernance) {
            // No specific governance for risks - use default
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();

        // If hierarchical governance, include parent risks
        if ($governanceModel instanceof GovernanceModel && $governanceModel->value === 'hierarchical') {
            return $this->riskRepository->findByTenantIncludingParent($tenant, $parent);
        }

        // For shared or independent, return only own risks
        return $this->riskRepository->findByTenant($tenant);
    }

    /**
     * Get risk inheritance information for a tenant
     *
     * @param Tenant $tenant The tenant
     * @return array{hasParent: bool, canInherit: bool, governanceModel: string|null}
     */
    public function getRiskInheritanceInfo(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        if (!$parent instanceof Tenant || !$this->corporateGovernanceRepository instanceof CorporateGovernanceRepository) {
            return [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
            ];
        }

        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, 'risk');

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
     * Check if a risk is inherited from parent
     *
     * @param Risk $risk The risk to check
     * @param Tenant $currentTenant The current tenant viewing the risk
     * @return bool True if risk belongs to parent tenant
     */
    public function isInheritedRisk(Risk $risk, Tenant $currentTenant): bool
    {
        $riskTenant = $risk->getTenant();

        if (!$riskTenant instanceof Tenant) {
            return false;
        }

        $riskTenantId = $riskTenant->getId();
        $currentTenantId = $currentTenant->getId();

        // Explicit null handling for unsaved entities
        if ($riskTenantId === null || $currentTenantId === null) {
            return false;
        }

        return $riskTenantId !== $currentTenantId;
    }

    /**
     * Check if user can edit a risk (not inherited)
     *
     * @param Risk $risk The risk
     * @param Tenant $currentTenant The current tenant
     * @return bool True if risk can be edited
     */
    public function canEditRisk(Risk $risk, Tenant $currentTenant): bool
    {
        return !$this->isInheritedRisk($risk, $currentTenant);
    }

    /**
     * Get risk statistics for a tenant including inherited risks
     *
     * @param Tenant $tenant The tenant
     * @return array{total: int, high: int, medium: int, low: int, ownRisks: int, inheritedRisks: int}
     */
    public function getRiskStatsWithInheritance(Tenant $tenant): array
    {
        $allRisks = $this->getRisksForTenant($tenant);
        $ownRisks = $this->riskRepository->findByTenant($tenant);

        $stats = $this->riskRepository->getRiskStatsByTenant($tenant);
        $stats['ownRisks'] = count($ownRisks);
        $stats['inheritedRisks'] = count($allRisks) - count($ownRisks);

        return $stats;
    }

    /**
     * Get high risks for a tenant (including inherited if governance allows)
     *
     * @param Tenant $tenant The tenant
     * @param int $threshold Minimum risk score threshold (default: 12)
     * @return Risk[] Array of high risks
     */
    public function getHighRisksForTenant(Tenant $tenant, int $threshold = 12): array
    {
        $allRisks = $this->getRisksForTenant($tenant);

        return array_filter($allRisks, function (Risk $risk) use ($threshold): bool {
            $riskScore = ($risk->getProbability() ?? 0) * ($risk->getImpact() ?? 0);
            return $riskScore >= $threshold;
        });
    }
}
