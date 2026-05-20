<?php

declare(strict_types=1);

namespace App\AlvaHint\Rule\ManagementReview;

use App\AlvaHint\AbstractAlvaHintRule;
use App\AlvaHint\AlvaHint;
use App\Entity\ManagementReview;
use App\Entity\User;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Tier-2 hint: ISO 27001 Cl. 9.3 — the most-recently-completed
 * management review was more than 12 months ago. Management reviews
 * at planned intervals are a Clause 9.3 requirement; missing the
 * annual cadence is a finding at certification audit.
 */
final class ReviewIntervalOverdueRule extends AbstractAlvaHintRule
{
    private const int MAX_MONTHS = 12;

    public function key(): string
    {
        return 'management_review.interval_overdue';
    }

    public function priorityTier(): int
    {
        return 2;
    }

    public function requiredModules(): array
    {
        return ['reviews'];
    }

    public function appliesTo(object $entity, User $user): bool
    {
        if (!$entity instanceof ManagementReview) {
            return false;
        }

        $reviewDate = $entity->getReviewDate();
        if (!$reviewDate instanceof DateTimeInterface) {
            return false;
        }

        $threshold = (new DateTimeImmutable())->modify(sprintf('-%d months', self::MAX_MONTHS));

        return $reviewDate < $threshold;
    }

    public function build(object $entity, User $user): AlvaHint
    {
        \assert($entity instanceof ManagementReview);

        $months = $entity->getDaysSinceReview() !== null
            ? (int) round($entity->getDaysSinceReview() / 30)
            : self::MAX_MONTHS;

        return new AlvaHint(
            key: $this->key(),
            titleTranslationKey: 'management_review.interval_overdue.title',
            bodyTranslationKey: 'management_review.interval_overdue.body',
            bodyTranslationParams: [
                '%months%' => $months,
            ],
            translationDomain: 'alva',
            variant: 'warning',
            priorityTier: 2,
            dismissible: true,
            entityType: 'ManagementReview',
            entityId: $entity->getId() ?? 0,
            actionLabelTranslationKey: 'management_review.interval_overdue.action',
            actionRoute: 'app_management_review_new',
            actionRouteParams: [],
            actionMethod: 'GET',
            requiredRoles: ['ROLE_MANAGER'],
            mood: 'thinking',
        );
    }
}
