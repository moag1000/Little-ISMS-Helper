<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Control;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Wirksamkeitstest: getNextEffectivenessTest() of Control. */
final class ControlEffectivenessSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Control::class;
    }

    public function slug(): string
    {
        return 'control_effectiveness';
    }

    public function label(): string
    {
        return 'Wirksamkeitstest';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Control);

        return $item->getNextEffectivenessTest();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Control);

        return trim(($item->getControlId() ?? '') . ' ' . ($item->getName() ?? ''));
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Control);

        return $item->isApplicable() === false;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof Control);

        return Responsibility::holders($item->getResponsiblePersonUser(), $item->getResponsiblePersonRef())
            ->withDeputies(...$item->getResponsibleDeputyPersons())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): ?RecordLink
    {
        assert($item instanceof Control);

        return new RecordLink('app_soa_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'control_effectiveness_overdue', leadDays: 14, normReference: 'ISO 27001 9.1');
    }
}
