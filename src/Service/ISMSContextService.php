<?php

namespace App\Service;

use App\Entity\ISMSContext;
use App\Repository\ISMSContextRepository;
use Doctrine\ORM\EntityManagerInterface;

class ISMSContextService
{
    public function __construct(
        private ISMSContextRepository $contextRepository,
        private EntityManagerInterface $entityManager
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
}
