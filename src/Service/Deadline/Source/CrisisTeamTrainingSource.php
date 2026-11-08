<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\CrisisTeam;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Krisenstab-Training: getNextTrainingAt() of an active CrisisTeam (ISO 22301 7.2, 8.4.2). */
final class CrisisTeamTrainingSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return CrisisTeam::class;
    }

    public function slug(): string
    {
        return 'crisis_team_training';
    }

    public function label(): string
    {
        return 'Krisenstab-Training';
    }

    public function requiredModule(): string
    {
        return 'bcm';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof CrisisTeam);

        return $item->getNextTrainingAt();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof CrisisTeam);

        return (string) $item->getTeamName();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof CrisisTeam);

        return !$item->isActive();
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof CrisisTeam);

        return Responsibility::holders($item->getTeamLeader(), $item->getTeamLeaderPerson())
            ->withDeputies(
                $item->getDeputyLeader(),
                $item->getDeputyLeaderPerson(),
                ...$item->getTeamLeaderDeputyPersons()->toArray(),
            )
            ->orRoles('ROLE_BCM_OFFICER');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof CrisisTeam);

        return new RecordLink('app_crisis_team_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'crisis_team_training_due', leadDays: 30, normReference: 'ISO 22301 7.2, 8.4.2');
    }
}
