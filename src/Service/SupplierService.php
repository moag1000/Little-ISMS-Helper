<?php

namespace App\Service;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Repository\SupplierRepository;
use App\Repository\CorporateGovernanceRepository;

/**
 * Supplier Service - Business logic for Supplier Management with Corporate Structure awareness
 */
class SupplierService
{
    public function __construct(
        private SupplierRepository $supplierRepository,
        private ?CorporateStructureService $corporateStructureService = null,
        private ?CorporateGovernanceRepository $governanceRepository = null
    ) {}

    /**
     * Get all suppliers visible to a tenant (own + inherited based on governance)
     *
     * @param Tenant $tenant The tenant
     * @return Supplier[] Array of suppliers
     */
    public function getSuppliersForTenant(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        // No parent or no corporate structure service - return own suppliers only
        if (!$parent || !$this->corporateStructureService || !$this->governanceRepository) {
            return $this->supplierRepository->findByTenant($tenant);
        }

        // Check governance model for suppliers
        $governance = $this->governanceRepository->findGovernanceForScope($tenant, 'supplier');

        if (!$governance) {
            // No specific governance for suppliers - use default
            $governance = $this->governanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();

        // If hierarchical governance, include parent suppliers
        if ($governanceModel && $governanceModel->value === 'hierarchical') {
            return $this->supplierRepository->findByTenantIncludingParent($tenant, $parent);
        }

        // For shared or independent, return only own suppliers
        return $this->supplierRepository->findByTenant($tenant);
    }

    /**
     * Get supplier inheritance information for a tenant
     *
     * @param Tenant $tenant The tenant
     * @return array{hasParent: bool, canInherit: bool, governanceModel: string|null}
     */
    public function getSupplierInheritanceInfo(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        if (!$parent || !$this->governanceRepository) {
            return [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
            ];
        }

        $governance = $this->governanceRepository->findGovernanceForScope($tenant, 'supplier');

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
     * Check if a supplier is inherited from parent
     *
     * @param Supplier $supplier The supplier to check
     * @param Tenant $currentTenant The current tenant viewing the supplier
     * @return bool True if supplier belongs to parent tenant
     */
    public function isInheritedSupplier(Supplier $supplier, Tenant $currentTenant): bool
    {
        $supplierTenant = $supplier->getTenant();

        if (!$supplierTenant) {
            return false;
        }

        return $supplierTenant->getId() !== $currentTenant->getId();
    }

    /**
     * Check if user can edit a supplier (not inherited)
     *
     * @param Supplier $supplier The supplier
     * @param Tenant $currentTenant The current tenant
     * @return bool True if supplier can be edited
     */
    public function canEditSupplier(Supplier $supplier, Tenant $currentTenant): bool
    {
        return !$this->isInheritedSupplier($supplier, $currentTenant);
    }

    /**
     * Get supplier statistics for a tenant including inherited suppliers
     *
     * @param Tenant $tenant The tenant
     * @return array Statistics including own and inherited supplier counts
     */
    public function getSupplierStatsWithInheritance(Tenant $tenant): array
    {
        $allSuppliers = $this->getSuppliersForTenant($tenant);
        $ownSuppliers = $this->supplierRepository->findByTenant($tenant);

        $stats = $this->supplierRepository->getStatisticsByTenant($tenant);
        $stats['ownSuppliers'] = count($ownSuppliers);
        $stats['inheritedSuppliers'] = count($allSuppliers) - count($ownSuppliers);

        return $stats;
    }
}
