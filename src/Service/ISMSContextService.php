<?php

namespace App\Service;

use Symfony\Component\Security\Core\User\UserInterface;
use DateTimeImmutable;
use App\Entity\Tenant;
use DateTimeInterface;
use DateTime;
use App\Entity\ISMSContext;
use App\Repository\ISMSContextRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

class ISMSContextService
{
    public function __construct(
        private readonly ISMSContextRepository $ismsContextRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?CorporateStructureService $corporateStructureService = null,
        private readonly ?Security $security = null
    ) {}

    /**
     * Get the current ISMS context or create a new one if none exists
     * Automatically syncs organization name from tenant
     * Respects current user's tenant in multi-tenant environment
     */
    public function getCurrentContext(): ISMSContext
    {
        // Get current user's tenant if available
        $currentTenant = $this->getCurrentUserTenant();

        // Try to get context for current tenant
        $context = null;
        if ($currentTenant) {
            $context = $this->ismsContextRepository->getContextForTenant($currentTenant);
        } else {
            $context = $this->ismsContextRepository->getCurrentContext();
        }

        if (!$context) {
            $context = new ISMSContext();
            // Assign to current user's tenant if available
            if ($currentTenant) {
                $context->setTenant($currentTenant);
                $context->setOrganizationName($currentTenant->getName());
            }
            $this->entityManager->persist($context);
        }

        // Auto-sync organization name from tenant
        $this->syncOrganizationNameFromTenant($context);

        return $context;
    }

    /**
     * Get the current user's tenant
     */
    private function getCurrentUserTenant(): ?object
    {
        if (!$this->security instanceof Security) {
            return null;
        }

        $user = $this->security->getUser();
        if (!$user instanceof UserInterface || !method_exists($user, 'getTenant')) {
            return null;
        }

        return $user->getTenant();
    }

    /**
     * Save or update ISMS context
     * Automatically syncs organization name from tenant if available
     */
    public function saveContext(ISMSContext $ismsContext): void
    {
        // Auto-sync organization name from tenant
        $this->syncOrganizationNameFromTenant($ismsContext);

        $ismsContext->setUpdatedAt(new DateTimeImmutable());

        if (!$ismsContext->getId()) {
            $this->entityManager->persist($ismsContext);
        }

        $this->entityManager->flush();
    }

    /**
     * Sync organization name from associated tenant
     * This prevents redundancy between Tenant.name and ISMSContext.organizationName
     */
    public function syncOrganizationNameFromTenant(ISMSContext $ismsContext): void
    {
        $tenant = $ismsContext->getTenant();
        if ($tenant instanceof Tenant) {
            $ismsContext->setOrganizationName($tenant->getName());
        }
    }

    /**
     * Calculate completeness percentage of ISMS context
     */
    public function calculateCompleteness(ISMSContext $ismsContext): int
    {
        $fields = [
            $ismsContext->getOrganizationName(),
            $ismsContext->getIsmsScope(),
            $ismsContext->getExternalIssues(),
            $ismsContext->getInternalIssues(),
            $ismsContext->getInterestedParties(),
            $ismsContext->getInterestedPartiesRequirements(),
            $ismsContext->getLegalRequirements(),
            $ismsContext->getRegulatoryRequirements(),
            $ismsContext->getContractualObligations(),
            $ismsContext->getIsmsPolicy(),
            $ismsContext->getRolesAndResponsibilities(),
        ];

        $totalFields = count($fields);
        $filledFields = count(array_filter($fields, fn(?string $field): bool => !in_array($field, [null, '', '0'], true)));

        return $totalFields > 0 ? (int)(($filledFields / $totalFields) * 100) : 0;
    }

    /**
     * Check if review is due
     */
    public function isReviewDue(ISMSContext $ismsContext): bool
    {
        $nextReview = $ismsContext->getNextReviewDate();

        if (!$nextReview instanceof DateTimeInterface) {
            return true; // No review date set, consider it due
        }

        return $nextReview <= new DateTime();
    }

    /**
     * Get days until next review
     */
    public function getDaysUntilReview(ISMSContext $ismsContext): ?int
    {
        $nextReview = $ismsContext->getNextReviewDate();

        if (!$nextReview instanceof DateTimeInterface) {
            return null;
        }

        $now = new DateTime();
        $dateInterval = $now->diff($nextReview);

        return $dateInterval->invert ? -$dateInterval->days : $dateInterval->days;
    }

    /**
     * Schedule next review (default: 1 year from last review or today)
     */
    public function scheduleNextReview(ISMSContext $ismsContext, ?DateTime $baseDate = null): void
    {
        $baseDate ??= $ismsContext->getLastReviewDate() ?? new DateTime();
        $nextReview = (clone $baseDate)->modify('+1 year');

        $ismsContext->setNextReviewDate($nextReview);
    }

    /**
     * Validate ISMS context for completeness
     */
    public function validateContext(ISMSContext $ismsContext): array
    {
        $errors = [];

        if (in_array($ismsContext->getOrganizationName(), [null, '', '0'], true)) {
            $errors[] = 'Organisationsname ist erforderlich.';
        }

        if (in_array($ismsContext->getIsmsScope(), [null, '', '0'], true)) {
            $errors[] = 'ISMS-Geltungsbereich ist erforderlich.';
        }

        if (in_array($ismsContext->getIsmsPolicy(), [null, '', '0'], true)) {
            $errors[] = 'ISMS-Richtlinie ist erforderlich.';
        }

        if (in_array($ismsContext->getRolesAndResponsibilities(), [null, '', '0'], true)) {
            $errors[] = 'Rollen und Verantwortlichkeiten sind erforderlich.';
        }

        return $errors;
    }

    /**
     * Get effective ISMS context considering corporate structure
     * Returns inherited context if governance model is HIERARCHICAL
     */
    public function getEffectiveContext(?ISMSContext $ismsContext = null): ISMSContext
    {
        if (!$ismsContext instanceof ISMSContext) {
            $ismsContext = $this->getCurrentContext();
        }

        $tenant = $ismsContext->getTenant();

        // If no corporate structure service or no tenant, return as-is
        if (!$this->corporateStructureService instanceof CorporateStructureService || !$tenant instanceof Tenant) {
            return $ismsContext;
        }

        // Use corporate structure service to get effective context
        $effectiveContext = $this->corporateStructureService->getEffectiveISMSContext($tenant);

        return $effectiveContext ?? $ismsContext;
    }

    /**
     * Get information about context inheritance
     * Returns array with: isInherited, inheritedFrom, effectiveContext
     */
    public function getContextInheritanceInfo(?ISMSContext $ismsContext = null): array
    {
        if (!$ismsContext instanceof ISMSContext) {
            $ismsContext = $this->getCurrentContext();
        }

        $tenant = $ismsContext->getTenant();
        $effectiveContext = $this->getEffectiveContext($ismsContext);

        // Check if contexts are different (null-safe comparison)
        $contextId = $ismsContext->getId();
        $effectiveContextId = $effectiveContext->getId();
        $isInherited = $contextId !== null && $effectiveContextId !== null && $effectiveContextId !== $contextId;
        $inheritedFrom = $isInherited ? $effectiveContext->getTenant() : null;

        return [
            'isInherited' => $isInherited,
            'inheritedFrom' => $inheritedFrom,
            'effectiveContext' => $effectiveContext,
            'ownContext' => $ismsContext,
            'hasParent' => $tenant instanceof Tenant && $tenant->getParent() instanceof Tenant,
        ];
    }

    /**
     * Check if current user's tenant can edit this context
     * Subsidiaries with HIERARCHICAL governance can only view, not edit
     */
    public function canEditContext(ISMSContext $ismsContext): bool
    {
        $tenant = $ismsContext->getTenant();

        if (!$tenant instanceof Tenant || !$this->security instanceof Security) {
            return true; // Default: allow if no restrictions
        }

        $user = $this->security->getUser();
        if (!$user instanceof UserInterface || !method_exists($user, 'getTenant')) {
            return true;
        }

        $userTenant = $user->getTenant();
        if (!$userTenant) {
            return true; // No tenant assigned to user - allow by default
        }

        // If different tenant, check corporate access
        if ($userTenant->getId() !== $tenant->getId()) {
            if (!$this->corporateStructureService instanceof CorporateStructureService) {
                return false;
            }

            return $this->corporateStructureService->canAccessTenant($userTenant, $tenant);
        }

        // Same tenant: Check if using inherited context
        $inheritanceInfo = $this->getContextInheritanceInfo($ismsContext);

        // If context is inherited, user cannot edit (must edit at parent level)
        return !$inheritanceInfo['isInherited'];
    }
}
