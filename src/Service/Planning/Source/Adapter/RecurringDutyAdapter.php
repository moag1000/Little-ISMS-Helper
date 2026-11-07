<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\RecurringDuty;
use App\Entity\Tenant;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Planning\Source\SourceAdapter;
use App\Service\RecurringDuty\RecurringDutyCatalog;
use App\Service\RecurringDuty\RecurringDutyService;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * SourceAdapter for the organisation's recurring duties without a date field
 * on a record ({@see RecurringDutyCatalog}). The duty owns its rhythm: "done"
 * moves nextDueAt by the interval, so the planner sets no recurrence of its own.
 */
final class RecurringDutyAdapter implements SourceAdapter
{
    public function __construct(
        private readonly RecurringDutyService $duties,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function slug(): string
    {
        return 'recurring_duty';
    }

    public function label(): string
    {
        return 'Wiederkehrende Pflicht';
    }

    /** Each duty names its own module; the service filters them. */
    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return iterable<RecurringDuty> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->duties->applicable($tenant);
    }

    /** @return iterable<RecurringDuty> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->duties->applicable($tenant);
    }

    public function dueDateOf(object $item): DateTimeInterface
    {
        assert($item instanceof RecurringDuty);

        return $item->getNextDueAt();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof RecurringDuty);

        return $this->translator->trans('recurring_duty.duty.' . $item->getDutyKey() . '.title', [], 'recurring_duty');
    }

    /** A recurring duty is never done for good. */
    public function isCompleted(object $item): bool
    {
        return false;
    }

    public function ownsRecurrence(): bool
    {
        return true;
    }

    public function refId(object $item): int
    {
        assert($item instanceof RecurringDuty);

        return (int) $item->getId();
    }

    public function supports(object $item): bool
    {
        return $item instanceof RecurringDuty;
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof RecurringDuty);

        return Responsibility::holders($item->getOwner())
            ->orRoles(...(RecurringDutyCatalog::get($item->getDutyKey())['roles'] ?? ['ROLE_ISB']));
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof RecurringDuty);

        return new RecordLink('app_recurring_duty_done', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(
            bucket: 'recurring_duties',
            leadDays: 30,
            normReference: 'ISO 27001 / ISO 22301 / NIS-2 / DORA / DSGVO',
        );
    }
}
