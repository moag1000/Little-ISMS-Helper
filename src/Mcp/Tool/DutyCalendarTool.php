<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Entity\RecurringDuty;
use App\Mcp\McpSession;
use App\Service\RecurringDuty\RecurringDutyCatalog;
use App\Service\RecurringDuty\RecurringDutyService;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The duty calendar: recurring norm duties without a date on a record (risk
 * acceptance criteria, SoA review, BIA, NIS-2 management training, …) with
 * the next due date and the last time it was done.
 */
final class DutyCalendarTool extends AbstractMcpTool
{
    public function __construct(
        private readonly RecurringDutyService $duties,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return 'duty_calendar';
    }

    public function description(): string
    {
        return 'Recurring duties of the norms that apply to the organisation (duty calendar): norm clause, interval, '
            . 'next due date, overdue flag, last done and by whom, owner.';
    }

    public function inputSchema(McpSession $session): array
    {
        return ['type' => 'object', 'properties' => new \stdClass()];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $today = new \DateTimeImmutable('today');
        $rows = array_map(fn (RecurringDuty $d): array => [
            'key' => $d->getDutyKey(),
            'title' => $this->translator->trans('recurring_duty.duty.' . $d->getDutyKey() . '.title', [], 'recurring_duty', $session->locale),
            'norm' => RecurringDutyCatalog::get($d->getDutyKey())['norm'] ?? null,
            'interval_months' => $d->getIntervalMonths(),
            'next_due' => self::date($d->getNextDueAt()),
            'overdue' => $d->getNextDueAt() < $today,
            'last_done' => self::date($d->getLastDoneAt()),
            'last_done_by' => $d->getLastDoneBy()?->getFullName(),
            'owner' => $d->getOwner()?->getFullName(),
        ], $this->duties->applicable($session->tenant));

        return ['duties' => $rows, '_audit' => ['count' => count($rows)]];
    }
}
