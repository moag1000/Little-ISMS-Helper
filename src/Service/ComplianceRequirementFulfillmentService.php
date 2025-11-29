<?php

namespace App\Service;

use App\Entity\CorporateGovernance;
use App\Enum\GovernanceModel;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceFramework;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\CorporateGovernanceRepository;

/**
 * Compliance Requirement Fulfillment Service
 *
 * Business logic for tenant-specific compliance requirement fulfillment
 * with Corporate Structure awareness and data reuse.
 *
 * Features:
 * - Multi-tenancy support with parent inheritance
 * - Corporate Governance model awareness (hierarchical/shared/independent)
 * - Data reuse from parent organizations
 * - Automatic fulfillment record creation
 *
 * @see RiskService For similar corporate structure pattern
 */
class ComplianceRequirementFulfillmentService
{
    public function __construct(
        private readonly ComplianceRequirementFulfillmentRepository $complianceRequirementFulfillmentRepository,
        private readonly ?CorporateStructureService $corporateStructureService = null,
        private readonly ?CorporateGovernanceRepository $corporateGovernanceRepository = null
    ) {}

    /**
     * Get all fulfillments visible to a tenant (own + inherited based on governance)
     *
     * @param Tenant $tenant The tenant
     * @param ComplianceFramework|null $complianceFramework Optional framework filter
     * @return ComplianceRequirementFulfillment[] Array of fulfillments
     */
    public function getFulfillmentsForTenant(Tenant $tenant, ?ComplianceFramework $complianceFramework = null): array
    {
        $parent = $tenant->getParent();

        // No parent or no corporate structure service - return own fulfillments only
        if (!$parent instanceof Tenant || !$this->corporateStructureService instanceof CorporateStructureService || !$this->corporateGovernanceRepository instanceof CorporateGovernanceRepository) {
            return $complianceFramework instanceof ComplianceFramework
                ? $this->complianceRequirementFulfillmentRepository->findByFrameworkAndTenant($complianceFramework, $tenant)
                : $this->complianceRequirementFulfillmentRepository->findByTenant($tenant);
        }

        // Check governance model for compliance
        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, 'compliance');

        if (!$governance instanceof CorporateGovernance) {
            // No specific governance for compliance - use default
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();

        // If hierarchical governance, include parent fulfillments
        if ($governanceModel instanceof GovernanceModel && $governanceModel->value === 'hierarchical') {
            $fulfillments = $this->complianceRequirementFulfillmentRepository->findByTenantIncludingParent($tenant, $parent);

            // Filter by framework if specified
            if ($complianceFramework instanceof ComplianceFramework) {
                return array_filter($fulfillments, fn(ComplianceRequirementFulfillment $f): bool => $f->getRequirement()->getFramework()->id === $complianceFramework->id);
            }

            return $fulfillments;
        }

        // For shared or independent, return only own fulfillments
        return $complianceFramework instanceof ComplianceFramework
            ? $this->complianceRequirementFulfillmentRepository->findByFrameworkAndTenant($complianceFramework, $tenant)
            : $this->complianceRequirementFulfillmentRepository->findByTenant($tenant);
    }

    /**
     * Get fulfillment inheritance information for a tenant
     *
     * @param Tenant $tenant The tenant
     * @return array{hasParent: bool, canInherit: bool, governanceModel: string|null}
     */
    public function getFulfillmentInheritanceInfo(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        if (!$parent instanceof Tenant || !$this->corporateGovernanceRepository instanceof CorporateGovernanceRepository) {
            return [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
            ];
        }

        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, 'compliance');

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
     * Check if a fulfillment is inherited from parent
     *
     * @param ComplianceRequirementFulfillment $complianceRequirementFulfillment The fulfillment to check
     * @param Tenant $currentTenant The current tenant viewing the fulfillment
     * @return bool True if fulfillment belongs to parent tenant
     */
    public function isInheritedFulfillment(ComplianceRequirementFulfillment $complianceRequirementFulfillment, Tenant $currentTenant): bool
    {
        $fulfillmentTenant = $complianceRequirementFulfillment->getTenant();

        if (!$fulfillmentTenant instanceof Tenant) {
            return false;
        }

        $fulfillmentTenantId = $fulfillmentTenant->getId();
        $currentTenantId = $currentTenant->getId();

        // Explicit null handling for unsaved entities
        if ($fulfillmentTenantId === null || $currentTenantId === null) {
            return false;
        }

        return $fulfillmentTenantId !== $currentTenantId;
    }

    /**
     * Check if user can edit a fulfillment (not inherited)
     *
     * @param ComplianceRequirementFulfillment $complianceRequirementFulfillment The fulfillment
     * @param Tenant $currentTenant The current tenant
     * @return bool True if fulfillment can be edited
     */
    public function canEditFulfillment(ComplianceRequirementFulfillment $complianceRequirementFulfillment, Tenant $currentTenant): bool
    {
        return !$this->isInheritedFulfillment($complianceRequirementFulfillment, $currentTenant);
    }

    /**
     * Get or create fulfillment record for tenant and requirement
     * Supports data reuse: if parent has fulfillment in hierarchical mode, inherit as starting point
     */
    public function getOrCreateFulfillment(Tenant $tenant, ComplianceRequirement $complianceRequirement): ComplianceRequirementFulfillment
    {
        // Try to find existing fulfillment
        $fulfillment = $this->complianceRequirementFulfillmentRepository->findOrCreateForTenantAndRequirement($tenant, $complianceRequirement);

        // If new fulfillment and we have parent with hierarchical governance, inherit initial values
        if (!$fulfillment->getId()) {
            $inheritanceInfo = $this->getFulfillmentInheritanceInfo($tenant);

            if ($inheritanceInfo['canInherit']) {
                $parent = $tenant->getParent();
                if ($parent instanceof Tenant) {
                    $parentFulfillment = $this->complianceRequirementFulfillmentRepository->findOneBy([
                        'tenant' => $parent,
                        'requirement' => $complianceRequirement,
                    ]);

                    if ($parentFulfillment) {
                        // Data Reuse: Inherit values from parent as starting point
                        $fulfillment->setApplicable($parentFulfillment->isApplicable());
                        $fulfillment->setApplicabilityJustification($parentFulfillment->getApplicabilityJustification());
                        // Note: Don't copy fulfillmentPercentage - each tenant must track their own progress
                    }
                }
            }
        }

        return $fulfillment;
    }

    /**
     * Get fulfillment statistics for a tenant including inherited data
     *
     * @param Tenant $tenant The tenant
     * @param ComplianceFramework|null $complianceFramework Optional framework filter
     * @return array{total: int, own: int, inherited: int, avg_fulfillment: float}
     */
    public function getFulfillmentStatsWithInheritance(Tenant $tenant, ?ComplianceFramework $complianceFramework = null): array
    {
        $allFulfillments = $this->getFulfillmentsForTenant($tenant, $complianceFramework);
        $ownFulfillments = $complianceFramework instanceof ComplianceFramework
            ? $this->complianceRequirementFulfillmentRepository->findByFrameworkAndTenant($complianceFramework, $tenant)
            : $this->complianceRequirementFulfillmentRepository->findByTenant($tenant);

        $baseStats = $complianceFramework instanceof ComplianceFramework
            ? $this->complianceRequirementFulfillmentRepository->getComplianceStats($tenant)
            : $this->complianceRequirementFulfillmentRepository->getComplianceStats($tenant);

        return array_merge($baseStats, [
            'own' => count($ownFulfillments),
            'inherited' => count($allFulfillments) - count($ownFulfillments),
        ]);
    }
}
