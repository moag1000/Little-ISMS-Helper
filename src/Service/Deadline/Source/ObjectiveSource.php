<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\ISMSObjective;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** ISMS-Ziel: getTargetDate() of ISMSObjective. */
final class ObjectiveSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return ISMSObjective::class;
    }

    public function slug(): string
    {
        return 'isms_objective';
    }

    public function label(): string
    {
        return 'ISMS-Ziel';
    }

    public function requiredModule(): ?string
    {
        return 'objectives';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ISMSObjective);

        return $item->getTargetDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ISMSObjective);

        return (string) ($item->getTitle() ?? 'Objective #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ISMSObjective);

        return in_array($item->getStatus(), ['achieved', 'cancelled'], true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof ISMSObjective);

        return Responsibility::holders($item->getResponsiblePersonUser(), $item->getResponsiblePersonRef())
            ->withDeputies(...$item->getResponsibleDeputyPersons())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof ISMSObjective);

        return new RecordLink('app_objective_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'objectives_due', leadDays: 30, normReference: 'ISO 27001 6.2');
    }
}
