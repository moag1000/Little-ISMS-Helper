<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Team;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * Team gültig bis: getValidUntil() of an active planning Team. Its tasks and
 * capacity end with it, so the team lead extends or hands over (ISO 27001 7.1).
 */
final class TeamValiditySource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Team::class;
    }

    public function slug(): string
    {
        return 'team_validity';
    }

    public function label(): string
    {
        return 'Team läuft aus';
    }

    public function requiredModule(): string
    {
        return 'resource_planning';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Team);

        return $item->getValidUntil();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Team);

        return (string) $item->getName();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Team);

        return !$item->isActive();
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof Team);

        return Responsibility::holders($item->getTeamLead(), $item->getTeamLeadPerson())
            ->orRoles('ROLE_MANAGER');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof Team);

        return new RecordLink('app_planning_team_edit', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'teams_expiring', leadDays: 30, normReference: 'ISO 27001 7.1');
    }
}
