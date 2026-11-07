<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Training;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeImmutable;
use DateTimeInterface;

/** Schulungsauffrischung: refreshDueOf($item) of Training. */
final class TrainingRefreshSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return Training::class;
    }

    public function slug(): string
    {
        return 'training_refresh';
    }

    public function label(): string
    {
        return 'Schulungsauffrischung';
    }

    public function requiredModule(): string
    {
        return 'training';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof Training);

        return $this->refreshDueOf($item);
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof Training);

        return (string) ($item->getTitle() ?? 'Training #' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof Training);

        return $item->getStatus() === 'cancelled';
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof Training);

        return Responsibility::holders($item->getTrainerUser(), $item->getTrainerPerson())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof Training);

        return new RecordLink('app_training_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'training_refresh_due', leadDays: 30, normReference: 'ISO 27001 7.2 / NIS-2 Art. 20 Abs. 2');
    }

    /** Next refresher: last session (or last reminder) plus the recurrence interval. */
    private function refreshDueOf(Training $item): ?DateTimeInterface
    {
        $months = (int) $item->getRecurrenceMonths();
        $base = $item->getScheduledDate() ?? $item->getLastReminderSentAt();
        if ($months < 1 || $base === null) {
            return null;
        }

        return DateTimeImmutable::createFromInterface($base)->modify(sprintf('+%d months', $months));
    }
}
