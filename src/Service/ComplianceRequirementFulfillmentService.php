<?php

namespace App\Service;

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
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly ?CorporateStructureService $corporateStructureService = null,
        private readonly ?CorporateGovernanceRepository $governanceRepository = null
    ) {}

    /**
     * Get all fulfillments visible to a tenant (own + inherited based on governance)
     *
     * @param Tenant $tenant The tenant
     * @param ComplianceFramework|null $framework Optional framework filter
     * @return ComplianceRequirementFulfillment[] Array of fulfillments
     */
    public function getFulfillmentsForTenant(Tenant $tenant, ?ComplianceFramework $framework = null): array
    {
        $parent = $tenant->getParent();

        // No parent or no corporate structure service - return own fulfillments only
        if (!$parent || !$this->corporateStructureService || !$this->governanceRepository) {
            return $framework
                ? $this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant)
                : $this->fulfillmentRepository->findByTenant($tenant);
        }

        // Check governance model for compliance
        $governance = $this->governanceRepository->findGovernanceForScope($tenant, 'compliance');

        if (!$governance) {
            // No specific governance for compliance - use default
            $governance = $this->governanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();

        // If hierarchical governance, include parent fulfillments
        if ($governanceModel && $governanceModel->value === 'hierarchical') {
            $fulfillments = $this->fulfillmentRepository->findByTenantIncludingParent($tenant, $parent);

            // Filter by framework if specified
            if ($framework) {
                return array_filter($fulfillments, function ($f) use ($framework) {
                    return $f->getRequirement()->getFramework()->getId() === $framework->getId();
                });
            }

            return $fulfillments;
        }

        // For shared or independent, return only own fulfillments
        return $framework
            ? $this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant)
            : $this->fulfillmentRepository->findByTenant($tenant);
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

        if (!$parent || !$this->governanceRepository) {
            return [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
            ];
        }

        $governance = $this->governanceRepository->findGovernanceForScope($tenant, 'compliance');

        if (!$governance) {
            $governance = $this->governanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();
        $canInherit = $governanceModel && $governanceModel->value === 'hierarchical';

        return [
            'hasParent' => true,
            'canInherit' => $canInherit,
            'governanceModel' => $governanceModel?->value,
        ];
    }

    /**
     * Check if a fulfillment is inherited from parent
     *
     * @param ComplianceRequirementFulfillment $fulfillment The fulfillment to check
     * @param Tenant $currentTenant The current tenant viewing the fulfillment
     * @return bool True if fulfillment belongs to parent tenant
     */
    public function isInheritedFulfillment(ComplianceRequirementFulfillment $fulfillment, Tenant $currentTenant): bool
    {
        $fulfillmentTenant = $fulfillment->getTenant();

        if (!$fulfillmentTenant) {
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
     * @param ComplianceRequirementFulfillment $fulfillment The fulfillment
     * @param Tenant $currentTenant The current tenant
     * @return bool True if fulfillment can be edited
     */
    public function canEditFulfillment(ComplianceRequirementFulfillment $fulfillment, Tenant $currentTenant): bool
    {
        return !$this->isInheritedFulfillment($fulfillment, $currentTenant);
    }

    /**
     * Get or create fulfillment record for tenant and requirement
     * Supports data reuse: if parent has fulfillment in hierarchical mode, inherit as starting point
     *
     * @param Tenant $tenant
     * @param ComplianceRequirement $requirement
     * @return ComplianceRequirementFulfillment
     */
    public function getOrCreateFulfillment(Tenant $tenant, ComplianceRequirement $requirement): ComplianceRequirementFulfillment
    {
        // Try to find existing fulfillment
        $fulfillment = $this->fulfillmentRepository->findOrCreateForTenantAndRequirement($tenant, $requirement);

        // If new fulfillment and we have parent with hierarchical governance, inherit initial values
        if (!$fulfillment->getId()) {
            $inheritanceInfo = $this->getFulfillmentInheritanceInfo($tenant);

            if ($inheritanceInfo['canInherit']) {
                $parent = $tenant->getParent();
                if ($parent) {
                    $parentFulfillment = $this->fulfillmentRepository->findOneBy([
                        'tenant' => $parent,
                        'requirement' => $requirement,
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
     * @param ComplianceFramework|null $framework Optional framework filter
     * @return array{total: int, own: int, inherited: int, avg_fulfillment: float}
     */
    public function getFulfillmentStatsWithInheritance(Tenant $tenant, ?ComplianceFramework $framework = null): array
    {
        $allFulfillments = $this->getFulfillmentsForTenant($tenant, $framework);
        $ownFulfillments = $framework
            ? $this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant)
            : $this->fulfillmentRepository->findByTenant($tenant);

        $baseStats = $framework
            ? $this->fulfillmentRepository->getComplianceStats($tenant)
            : $this->fulfillmentRepository->getComplianceStats($tenant);

        return array_merge($baseStats, [
            'own' => count($ownFulfillments),
            'inherited' => count($allFulfillments) - count($ownFulfillments),
        ]);
    }
}
