<?php

namespace App\Service;

use App\Entity\ISMSContext;
use App\Repository\ISMSContextRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Security;

class ISMSContextService
{
    public function __construct(
        private ISMSContextRepository $contextRepository,
        private EntityManagerInterface $entityManager,
        private ?CorporateStructureService $corporateStructureService = null,
        private ?Security $security = null
    ) {}

    /**
     * Get the current ISMS context or create a new one if none exists
     */
    public function getCurrentContext(): ISMSContext
    {
        $context = $this->contextRepository->getCurrentContext();

        if (!$context) {
            $context = new ISMSContext();
            $this->entityManager->persist($context);
        }

        return $context;
    }

    /**
     * Save or update ISMS context
     */
    public function saveContext(ISMSContext $context): void
    {
        $context->setUpdatedAt(new \DateTime());

        if (!$context->getId()) {
            $this->entityManager->persist($context);
        }

        $this->entityManager->flush();
    }

    /**
     * Calculate completeness percentage of ISMS context
     */
    public function calculateCompleteness(ISMSContext $context): int
    {
        $fields = [
            $context->getOrganizationName(),
            $context->getIsmsScope(),
            $context->getExternalIssues(),
            $context->getInternalIssues(),
            $context->getInterestedParties(),
            $context->getInterestedPartiesRequirements(),
            $context->getLegalRequirements(),
            $context->getRegulatoryRequirements(),
            $context->getContractualObligations(),
            $context->getIsmsPolicy(),
            $context->getRolesAndResponsibilities(),
        ];

        $totalFields = count($fields);
        $filledFields = count(array_filter($fields, fn($field) => !empty($field)));

        return $totalFields > 0 ? (int)(($filledFields / $totalFields) * 100) : 0;
    }

    /**
     * Check if review is due
     */
    public function isReviewDue(ISMSContext $context): bool
    {
        $nextReview = $context->getNextReviewDate();

        if (!$nextReview) {
            return true; // No review date set, consider it due
        }

        return $nextReview <= new \DateTime();
    }

    /**
     * Get days until next review
     */
    public function getDaysUntilReview(ISMSContext $context): ?int
    {
        $nextReview = $context->getNextReviewDate();

        if (!$nextReview) {
            return null;
        }

        $now = new \DateTime();
        $interval = $now->diff($nextReview);

        return $interval->invert ? -$interval->days : $interval->days;
    }

    /**
     * Schedule next review (default: 1 year from last review or today)
     */
    public function scheduleNextReview(ISMSContext $context, ?\DateTime $baseDate = null): void
    {
        $baseDate = $baseDate ?? $context->getLastReviewDate() ?? new \DateTime();
        $nextReview = (clone $baseDate)->modify('+1 year');

        $context->setNextReviewDate($nextReview);
    }

    /**
     * Validate ISMS context for completeness
     */
    public function validateContext(ISMSContext $context): array
    {
        $errors = [];

        if (empty($context->getOrganizationName())) {
            $errors[] = 'Organisationsname ist erforderlich.';
        }

        if (empty($context->getIsmsScope())) {
            $errors[] = 'ISMS-Geltungsbereich ist erforderlich.';
        }

        if (empty($context->getIsmsPolicy())) {
            $errors[] = 'ISMS-Richtlinie ist erforderlich.';
        }

        if (empty($context->getRolesAndResponsibilities())) {
            $errors[] = 'Rollen und Verantwortlichkeiten sind erforderlich.';
        }

        return $errors;
    }

    /**
     * Get effective ISMS context considering corporate structure
     * Returns inherited context if governance model is HIERARCHICAL
     */
    public function getEffectiveContext(?ISMSContext $context = null): ISMSContext
    {
        if (!$context) {
            $context = $this->getCurrentContext();
        }

        $tenant = $context->getTenant();

        // If no corporate structure service or no tenant, return as-is
        if (!$this->corporateStructureService || !$tenant) {
            return $context;
        }

        // Use corporate structure service to get effective context
        $effectiveContext = $this->corporateStructureService->getEffectiveISMSContext($tenant);

        return $effectiveContext ?? $context;
    }

    /**
     * Get information about context inheritance
     * Returns array with: isInherited, inheritedFrom, effectiveContext
     */
    public function getContextInheritanceInfo(?ISMSContext $context = null): array
    {
        if (!$context) {
            $context = $this->getCurrentContext();
        }

        $tenant = $context->getTenant();
        $effectiveContext = $this->getEffectiveContext($context);

        // Check if contexts are different (null-safe comparison)
        $contextId = $context->getId();
        $effectiveContextId = $effectiveContext->getId();
        $isInherited = $contextId !== null && $effectiveContextId !== null && $effectiveContextId !== $contextId;
        $inheritedFrom = $isInherited ? $effectiveContext->getTenant() : null;

        return [
            'isInherited' => $isInherited,
            'inheritedFrom' => $inheritedFrom,
            'effectiveContext' => $effectiveContext,
            'ownContext' => $context,
            'hasParent' => $tenant && $tenant->getParent() !== null,
        ];
    }

    /**
     * Check if current user's tenant can edit this context
     * Subsidiaries with HIERARCHICAL governance can only view, not edit
     */
    public function canEditContext(ISMSContext $context): bool
    {
        $tenant = $context->getTenant();

        if (!$tenant || !$this->security) {
            return true; // Default: allow if no restrictions
        }

        $user = $this->security->getUser();
        if (!$user || !method_exists($user, 'getTenant')) {
            return true;
        }

        $userTenant = $user->getTenant();
        if (!$userTenant) {
            return true; // No tenant assigned to user - allow by default
        }

        // If different tenant, check corporate access
        if ($userTenant->getId() !== $tenant->getId()) {
            if (!$this->corporateStructureService) {
                return false;
            }

            return $this->corporateStructureService->canAccessTenant($userTenant, $tenant);
        }

        // Same tenant: Check if using inherited context
        $inheritanceInfo = $this->getContextInheritanceInfo($context);

        // If context is inherited, user cannot edit (must edit at parent level)
        return !$inheritanceInfo['isInherited'];
    }
}
