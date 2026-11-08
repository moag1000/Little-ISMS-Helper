<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Patch;
use App\Enum\PatchStatus;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Patch-Frist: getDeploymentDeadline() of a Patch not deployed yet (ISO 27001 A.8.8). */
final class PatchDeploymentSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Patch::class;
    }

    public function slug(): string
    {
        return 'patch_deployment';
    }

    public function label(): string
    {
        return 'Patch-Frist';
    }

    public function requiredModule(): string
    {
        return 'patches';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Patch);

        return $item->getDeploymentDeadline();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Patch);

        return (string) $item->getTitle();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Patch);

        return in_array($item->getStatusEnum(), [PatchStatus::Deployed, PatchStatus::NotApplicable], true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof Patch);

        return Responsibility::holders($item->getResponsiblePersonUser(), $item->getResponsiblePersonRef())
            ->withDeputies(...$item->getResponsibleDeputyPersons()->toArray())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof Patch);

        return new RecordLink('app_patch_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'patches_due', leadDays: 14, normReference: 'ISO 27001 A.8.8');
    }
}
