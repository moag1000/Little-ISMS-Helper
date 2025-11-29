<?php

namespace App\Service;

use App\Entity\CorporateGovernance;
use App\Entity\ISMSContext;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Repository\CorporateGovernanceRepository;
use App\Repository\ISMSContextRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for managing corporate structures and ISMS context inheritance
 */
class CorporateStructureService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ISMSContextRepository $ismsContextRepository,
        private readonly CorporateGovernanceRepository $corporateGovernanceRepository
    ) {
    }

    /**
     * Get the effective ISMS context for a tenant based on governance model
     *
     * - HIERARCHICAL: Returns parent's context
     * - SHARED: Returns own context, with parent as reference
     * - INDEPENDENT: Returns own context only
     */
    public function getEffectiveISMSContext(Tenant $tenant): ?ISMSContext
    {
        $parent = $tenant->getParent();

        // No parent = use own context
        if (!$parent instanceof Tenant) {
            return $this->ismsContextRepository->findOneBy(['tenant' => $tenant]);
        }

        // Get governance for ISMS context scope
        $governance = $this->corporateGovernanceRepository->findGovernanceForScope($tenant, 'isms_context');
        if (!$governance instanceof CorporateGovernance) {
            // Fall back to default governance
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
        }

        $governanceModel = $governance?->getGovernanceModel();

        switch ($governanceModel) {
            case GovernanceModel::HIERARCHICAL:
                // Use parent's context recursively
                return $this->getEffectiveISMSContext($parent);

            case GovernanceModel::SHARED:
            case GovernanceModel::INDEPENDENT:
            default:
                // Use own context (create if not exists for SHARED model)
                $context = $this->ismsContextRepository->findOneBy(['tenant' => $tenant]);

                if ($context === null && $governanceModel === GovernanceModel::SHARED) {
                    // For shared model, create context based on parent as template
                    $parentContext = $this->getEffectiveISMSContext($parent);
                    if ($parentContext instanceof ISMSContext) {
                        $context = $this->createDerivedContext($tenant, $parentContext);
                    }
                }

                return $context;
        }
    }

    /**
     * Create a new ISMS context for a subsidiary based on parent context
     */
    private function createDerivedContext(Tenant $tenant, ISMSContext $ismsContext): ISMSContext
    {
        $context = new ISMSContext();
        $context->setTenant($tenant);
        $context->setOrganizationName($tenant->getName());

        // Copy relevant fields from parent as templates
        $context->setIsmsPolicy($ismsContext->getIsmsPolicy());
        $context->setLegalRequirements($ismsContext->getLegalRequirements());
        $context->setRegulatoryRequirements($ismsContext->getRegulatoryRequirements());

        // Note: scope and other specific fields should be set by the subsidiary

        return $context;
    }

    /**
     * Check if a user has access to a tenant within the corporate structure
     *
     * Rules:
     * - Users of parent company can access all subsidiaries
     * - Users of subsidiaries can only access their own tenant (unless SHARED with explicit permissions)
     */
    public function canAccessTenant(Tenant $userTenant, Tenant $targetTenant): bool
    {
        // Same tenant = always allowed
        if ($userTenant->getId() === $targetTenant->getId()) {
            return true;
        }

        // User's tenant is parent of target = allowed
        if ($this->isParentOf($userTenant, $targetTenant)) {
            return true;
        }

        // User's tenant is in same corporate group with SHARED governance
        if ($this->isInSameCorporateGroup($userTenant, $targetTenant)) {
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($targetTenant);
            $targetGovernance = $governance?->getGovernanceModel();
            return $targetGovernance === GovernanceModel::SHARED;
        }

        return false;
    }

    /**
     * Check if tenant1 is a parent (direct or indirect) of tenant2
     */
    public function isParentOf(Tenant $parent, Tenant $child): bool
    {
        $current = $child->getParent();

        while ($current instanceof Tenant) {
            if ($current->getId() === $parent->getId()) {
                return true;
            }
            $current = $current->getParent();
        }

        return false;
    }

    /**
     * Check if two tenants are in the same corporate group
     */
    public function isInSameCorporateGroup(Tenant $tenant1, Tenant $tenant2): bool
    {
        return $tenant1->getRootParent()->getId() === $tenant2->getRootParent()->getId();
    }

    /**
     * Get all tenants in the corporate group (parent + all subsidiaries)
     */
    public function getCorporateGroup(Tenant $tenant): array
    {
        $root = $tenant->getRootParent();
        return array_merge([$root], $root->getAllSubsidiaries());
    }

    /**
     * Validate corporate structure (prevent circular references, etc.)
     */
    public function validateStructure(Tenant $tenant): array
    {
        $errors = [];

        // Check for circular reference
        if ($this->hasCircularReference($tenant)) {
            $errors[] = 'Circular reference detected in corporate structure';
        }

        // Validate governance model requirements
        if ($tenant->getParent() instanceof Tenant) {
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);
            if (!$governance instanceof CorporateGovernance) {
                $errors[] = 'Subsidiaries must have a default governance model defined';
            }
        }

        // Parent should be marked as corporate parent if it has subsidiaries
        if ($tenant->getSubsidiaries()->count() > 0 && !$tenant->isCorporateParent()) {
            $errors[] = 'Tenant with subsidiaries should be marked as corporate parent';
        }

        return $errors;
    }

    /**
     * Check for circular references in parent chain
     */
    private function hasCircularReference(Tenant $tenant): bool
    {
        $visited = [];
        $current = $tenant;

        while ($current instanceof Tenant) {
            $id = $current->getId();
            if (isset($visited[$id])) {
                return true; // Circular reference found
            }
            $visited[$id] = true;
            $current = $current->getParent();
        }

        return false;
    }

    /**
     * Get corporate structure as hierarchical tree
     */
    public function getStructureTree(Tenant $rootTenant): array
    {
        return $this->buildTreeNode($rootTenant);
    }

    private function buildTreeNode(Tenant $tenant): array
    {
        // Get default governance for this tenant
        $governance = $this->corporateGovernanceRepository->findDefaultGovernance($tenant);

        $node = [
            'id' => $tenant->getId(),
            'code' => $tenant->getCode(),
            'name' => $tenant->getName(),
            'governanceModel' => $governance?->getGovernanceModel()?->value,
            'governanceLabel' => $governance?->getGovernanceModel()?->getLabel(),
            'isCorporateParent' => $tenant->isCorporateParent(),
            'depth' => $tenant->getHierarchyDepth(),
            'children' => []
        ];

        foreach ($tenant->getSubsidiaries() as $subsidiary) {
            $node['children'][] = $this->buildTreeNode($subsidiary);
        }

        return $node;
    }

    /**
     * Propagate ISMS context changes to subsidiaries (for HIERARCHICAL model)
     */
    public function propagateContextChanges(Tenant $tenant, ISMSContext $ismsContext): int
    {
        $updatedCount = 0;

        foreach ($tenant->getSubsidiaries() as $subsidiary) {
            $governance = $this->corporateGovernanceRepository->findDefaultGovernance($subsidiary);
            if ($governance instanceof CorporateGovernance && $governance->getGovernanceModel() === GovernanceModel::HIERARCHICAL) {
                // Update or create context for hierarchical subsidiaries
                $subsidiaryContext = $this->ismsContextRepository->findOneBy(['tenant' => $subsidiary]);

                if ($subsidiaryContext === null) {
                    $subsidiaryContext = new ISMSContext();
                    $subsidiaryContext->setTenant($subsidiary);
                    $subsidiaryContext->setOrganizationName($subsidiary->getName());
                }

                // Copy all fields from parent
                $this->copyContextFields($ismsContext, $subsidiaryContext);

                $this->entityManager->persist($subsidiaryContext);
                $updatedCount++;

                // Recursively propagate to sub-subsidiaries
                $updatedCount += $this->propagateContextChanges($subsidiary, $ismsContext);
            }
        }

        if ($updatedCount > 0) {
            $this->entityManager->flush();
        }

        return $updatedCount;
    }

    private function copyContextFields(ISMSContext $source, ISMSContext $target): void
    {
        $target->setIsmsScope($source->getIsmsScope());
        $target->setScopeExclusions($source->getScopeExclusions());
        $target->setExternalIssues($source->getExternalIssues());
        $target->setInternalIssues($source->getInternalIssues());
        $target->setInterestedParties($source->getInterestedParties());
        $target->setInterestedPartiesRequirements($source->getInterestedPartiesRequirements());
        $target->setLegalRequirements($source->getLegalRequirements());
        $target->setRegulatoryRequirements($source->getRegulatoryRequirements());
        $target->setContractualObligations($source->getContractualObligations());
        $target->setIsmsPolicy($source->getIsmsPolicy());
        $target->setRolesAndResponsibilities($source->getRolesAndResponsibilities());
        $target->setLastReviewDate($source->getLastReviewDate());
        $target->setNextReviewDate($source->getNextReviewDate());
    }
}
