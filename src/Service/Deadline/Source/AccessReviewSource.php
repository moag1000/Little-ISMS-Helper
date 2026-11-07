<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\AccessReviewCampaign;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Zugriffsreview: getDueDate() of AccessReviewCampaign. */
final class AccessReviewSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return AccessReviewCampaign::class;
    }

    public function slug(): string
    {
        return 'access_review';
    }

    public function label(): string
    {
        return 'Zugriffsreview';
    }

    public function requiredModule(): ?string
    {
        return 'access_review';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof AccessReviewCampaign);

        return $item->getDueDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof AccessReviewCampaign);

        return (string) ($item->getName() ?? 'Access review #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof AccessReviewCampaign);

        return $item->getStatus() === \App\Entity\AccessReviewCampaign::STATUS_CLOSED;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof AccessReviewCampaign);

        return Responsibility::holders($item->getCreatedBy())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): ?RecordLink
    {
        assert($item instanceof AccessReviewCampaign);

        return new RecordLink('app_access_review_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'access_reviews_due', leadDays: 14, normReference: 'ISO 27001 A.5.18');
    }
}
