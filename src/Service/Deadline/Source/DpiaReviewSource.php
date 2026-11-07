<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\DataProtectionImpactAssessment;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** DSFA-Review: getNextReviewDate() of DataProtectionImpactAssessment. */
final class DpiaReviewSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return DataProtectionImpactAssessment::class;
    }

    public function slug(): string
    {
        return 'dpia_review';
    }

    public function label(): string
    {
        return 'DSFA-Review';
    }

    public function requiredModule(): ?string
    {
        return 'privacy';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof DataProtectionImpactAssessment);

        return $item->getNextReviewDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof DataProtectionImpactAssessment);

        return (string) ($item->getTitle() ?? 'DPIA #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof DataProtectionImpactAssessment);

        return $item->getStatus() !== 'approved';
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof DataProtectionImpactAssessment);

        return Responsibility::holders($item->getConductor(), $item->getConductorPerson())
            ->withDeputies(...$item->getConductorDeputyPersons())
            ->orRoles('ROLE_DPO');
    }

    public function linkOf(object $item): ?RecordLink
    {
        assert($item instanceof DataProtectionImpactAssessment);

        return new RecordLink('app_dpia_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'dpia_review_overdue', leadDays: 30, normReference: 'DSGVO Art. 35 Abs. 11');
    }
}
