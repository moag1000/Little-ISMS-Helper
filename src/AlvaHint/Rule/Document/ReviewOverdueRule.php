<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\Document;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\Document;
use App\Entity\User;
use App\Enum\DocumentStatus;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Tier-2 hint: ISO 27001 Cl. 7.5.3 / A.5.9 — documents approved more
 * than 6 months ago with no scheduled next-review date (or overdue
 * next-review) are a document-control gap. External auditors
 * specifically look for documented review cadence.
 */
final class ReviewOverdueRule extends AbstractAlvaHintRule
{
    private const int OVERDUE_MONTHS = 6;

    public function key(): string
    {
        return 'document.review_overdue';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['documents'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof Document) {
            return false;
        }

        // Only approved or published documents
        if (
            !in_array(
                $entity->getStatusEnum(),
                [DocumentStatus::Approved, DocumentStatus::Published],
                true,
            )
        ) {
            return false;
        }

        // If a next-review date is set and still in the future — no hint
        $nextReview = $entity->getNextReviewDate();
        if ($nextReview instanceof DateTimeInterface && $nextReview > new DateTimeImmutable()) {
            return false;
        }

        // If next-review is in the past or not set, check updatedAt age
        $updated = $entity->getUpdatedAt();
        if (!$updated instanceof DateTimeInterface) {
            return false;
        }

        $threshold = (new DateTimeImmutable())->modify(sprintf('-%d months', self::OVERDUE_MONTHS));

        return $updated < $threshold;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof Document);

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'document.review_overdue.title',
            bodyTranslationKey: 'document.review_overdue.body',
            bodyTranslationParams: [
                '%months%' => self::OVERDUE_MONTHS,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'Document',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'document.review_overdue.action',
            actionRoute: 'app_document_edit',
            actionRouteParams: ['id' => $entity->getId() ?? 0],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
