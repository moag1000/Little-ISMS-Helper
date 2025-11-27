<?php

namespace App\Service;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Repository\CorporateGovernanceRepository;

/**
 * Control Service - Business logic for ISO 27001 Controls with Corporate Structure awareness
 */
class ControlService
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly ?CorporateStructureService $corporateStructureService = null,
        private readonly ?CorporateGovernanceRepository $corporateGovernanceRepository = null
    ) {}

    /**
     * Get all controls visible to a tenant (own + inherited based on governance)
     *
     * @param Tenant $tenant The tenant
     * @return Control[] Array of controls
     */
    public function getControlsForTenant(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        // No parent or no corporate structure service - return own controls only
        if (!$parent instanceof Tenant || !$this->corporateStructureService instanceof CorporateStructureService || !$this->corporateGovernanceRepository) {
            return $this->controlRepository->findByTenant($tenant);
        }

        // Check governance model for controls
        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, 'control');

        if (!$governance) {
            // No specific governance for controls - use default
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();

        // If hierarchical governance, include parent controls
        if ($governanceModel && $governanceModel->value === 'hierarchical') {
            return $this->controlRepository->findByTenantIncludingParent($tenant, $parent);
        }

        // For shared or independent, return only own controls
        return $this->controlRepository->findByTenant($tenant);
    }

    /**
     * Get control inheritance information for a tenant
     *
     * @param Tenant $tenant The tenant
     * @return array{hasParent: bool, canInherit: bool, governanceModel: string|null}
     */
    public function getControlInheritanceInfo(Tenant $tenant): array
    {
        $parent = $tenant->getParent();

        if (!$parent instanceof Tenant || !$this->corporateGovernanceRepository) {
            return [
                'hasParent' => false,
                'canInherit' => false,
                'governanceModel' => null,
            ];
        }

        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, 'control');

        if (!$governance) {
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
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
     * Check if a control is inherited from parent
     *
     * @param Control $control The control to check
     * @param Tenant $currentTenant The current tenant viewing the control
     * @return bool True if control belongs to parent tenant
     */
    public function isInheritedControl(Control $control, Tenant $currentTenant): bool
    {
        $controlTenant = $control->getTenant();

        if (!$controlTenant instanceof Tenant) {
            return false;
        }

        return $controlTenant->getId() !== $currentTenant->getId();
    }

    /**
     * Check if user can edit a control (not inherited)
     *
     * @param Control $control The control
     * @param Tenant $currentTenant The current tenant
     * @return bool True if control can be edited
     */
    public function canEditControl(Control $control, Tenant $currentTenant): bool
    {
        return !$this->isInheritedControl($control, $currentTenant);
    }

    /**
     * Get implementation statistics for a tenant including inherited controls
     *
     * @param Tenant $tenant The tenant
     * @return array{total: int, implemented: int, in_progress: int, not_started: int, not_applicable: int, ownControls: int, inheritedControls: int}
     */
    public function getImplementationStatsWithInheritance(Tenant $tenant): array
    {
        $allControls = $this->getControlsForTenant($tenant);
        $ownControls = $this->controlRepository->findByTenant($tenant);

        $stats = $this->controlRepository->getImplementationStatsByTenant($tenant);
        $stats['ownControls'] = count($ownControls);
        $stats['inheritedControls'] = count($allControls) - count($ownControls);

        return $stats;
    }
}
