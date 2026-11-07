<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\CorrectiveAction;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/** Wirksamkeitsprüfung Korrekturmaßnahme: getEffectivenessReviewDate() of CorrectiveAction. */
final class CapaEffectivenessSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return CorrectiveAction::class;
    }

    public function slug(): string
    {
        return 'capa_effectiveness_review';
    }

    public function label(): string
    {
        return 'Wirksamkeitsprüfung Korrekturmaßnahme';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof CorrectiveAction);

        return $item->getEffectivenessReviewDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof CorrectiveAction);

        return (string) ($item->getTitle() ?? 'CAPA #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof CorrectiveAction);

        return $item->getStatus() !== 'completed';
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof CorrectiveAction);

        return Responsibility::holders($item->getResponsiblePersonUser(), $item->getResponsiblePerson())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof CorrectiveAction);

        return new RecordLink('app_corrective_action_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'capa_effectiveness_due', leadDays: 14, normReference: 'ISO 27001 10.2 f');
    }
}
