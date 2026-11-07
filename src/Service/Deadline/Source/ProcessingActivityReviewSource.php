<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\ProcessingActivity;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** VVT-Review: getNextReviewDate() of ProcessingActivity. */
final class ProcessingActivityReviewSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return ProcessingActivity::class;
    }

    public function slug(): string
    {
        return 'processing_activity_review';
    }

    public function label(): string
    {
        return 'VVT-Review';
    }

    public function requiredModule(): ?string
    {
        return 'privacy';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ProcessingActivity);

        return $item->getNextReviewDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ProcessingActivity);

        return (string) ($item->getName() ?? 'Processing activity #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ProcessingActivity);

        return in_array($item->getStatus(), ['draft', 'archived'], true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof ProcessingActivity);

        return Responsibility::holders($item->getContactPersonUser(), $item->getContactPerson())
            ->withDeputies(...$item->getContactDeputyPersons())
            ->orRoles('ROLE_DPO');
    }

    public function linkOf(object $item): ?RecordLink
    {
        assert($item instanceof ProcessingActivity);

        return new RecordLink('app_processing_activity_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'processing_activity_review_due', leadDays: 30, normReference: 'DSGVO Art. 30 / 32 Abs. 1 d');
    }
}
