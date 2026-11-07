<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\BusinessContinuityPlan;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** BC-Plan-Review: getNextReviewDate() of BusinessContinuityPlan. */
final class BcPlanReviewSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return BusinessContinuityPlan::class;
    }

    public function slug(): string
    {
        return 'bc_plan_review';
    }

    public function label(): string
    {
        return 'BC-Plan-Review';
    }

    public function requiredModule(): ?string
    {
        return 'bcm';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof BusinessContinuityPlan);

        return $item->getNextReviewDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof BusinessContinuityPlan);

        return (string) ($item->getName() ?? 'BC plan #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof BusinessContinuityPlan);

        return in_array($item->getStatus(), ['draft', 'archived'], true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof BusinessContinuityPlan);

        return Responsibility::holders($item->getPlanOwnerUser(), $item->getPlanOwnerPerson())
            ->withDeputies(...$item->getPlanOwnerDeputyPersons())
            ->orRoles('ROLE_BCM_OFFICER');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof BusinessContinuityPlan);

        return new RecordLink('app_bc_plan_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'bc_plan_review_overdue', leadDays: 30, normReference: 'ISO 22301 8.4 / 9.1');
    }
}
