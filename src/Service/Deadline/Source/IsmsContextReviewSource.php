<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\ISMSContext;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Kontext-Review: getNextReviewDate() of ISMSContext. */
final class IsmsContextReviewSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return ISMSContext::class;
    }

    public function slug(): string
    {
        return 'isms_context_review';
    }

    public function label(): string
    {
        return 'Kontext-Review';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ISMSContext);

        return $item->getNextReviewDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ISMSContext);

        return (string) ($item->getOrganizationName() ?? 'ISMS-Kontext');
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ISMSContext);

        return false;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof ISMSContext);

        return Responsibility::roles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof ISMSContext);

        return new RecordLink('app_context_index', []);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'context_review_due', leadDays: 30, normReference: 'ISO 27001 4.1–4.3');
    }
}
