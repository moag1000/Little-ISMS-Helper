<?php

declare(strict_types=1);

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
final class RiskService implements RiskQuantSummaryInterface
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
        return $this->inheritsParentRisks($tenant)
            ? $this->riskRepository->findByTenantIncludingParent($tenant, $tenant->getParent())
            : $this->riskRepository->findByTenant($tenant);
    }

    /**
     * Organisations whose risks the tenant sees in its default ("inherited") view: its own, plus every
     * ancestor's when the risk governance is hierarchical. The register list filters on it in SQL (S31).
     *
     * @return list<Tenant>
     */
    public function tenantsVisibleTo(Tenant $tenant): array
    {
        return $this->inheritsParentRisks($tenant) ? array_values([$tenant, ...$tenant->getAllAncestors()]) : [$tenant];
    }

    /** Hierarchical risk governance (scope "risk", else the default) under a parent organisation. */
    private function inheritsParentRisks(Tenant $tenant): bool
    {
        if (!$tenant->getParent() instanceof Tenant || !$this->corporateStructureService instanceof CorporateStructureService || !$this->corporateGovernanceRepository instanceof CorporateGovernanceRepository) {
            return false;
        }
        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, 'risk')
            ?? $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
        $model = $governance?->getGovernanceModel();

        // Shared or independent governance: own risks only
        return $model instanceof GovernanceModel && $model->value === 'hierarchical';
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

    /**
     * Aggregate ALE (Annual Loss Expectancy) across all risks for a tenant.
     *
     * Returns total ALE sum + count of risks with quantitative data.
     * Only meaningful when 'risk_quant' module is active; callers should
     * check module status before calling (BoardReportGenerator does this).
     *
     * @return array{total_ale_eur: int, quantified_risk_count: int}
     */
    public function getRiskQuantSummary(Tenant $tenant): array
    {
        $risks = $this->getRisksForTenant($tenant);
        $totalAle = 0;
        $count = 0;

        foreach ($risks as $risk) {
            $ale = $risk->getAnnualLossExpectancy();
            if ($ale !== null) {
                $totalAle += $ale;
                $count++;
            }
        }

        return [
            'total_ale_eur' => $totalAle,
            'quantified_risk_count' => $count,
        ];
    }
}
