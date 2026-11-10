<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Responsibility\ResponsibilityResolver;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Every open duty from every deadline source, at the person responsible for it.
 *
 * A duty shows up when it has no date (open task), when its source has no
 * lead time, or when its deadline is within the source's lead days. Each duty
 * appears exactly once per person — My Day, the digest mail and the urgent
 * mail all read from here.
 *
 * Load (S31): the tenant's open duties are built once per request (My Day, the
 * header bell and the dashboard share them; kept per tenant and minute, dropped
 * by {@see reset()} after each request or worker message). Sources that can
 * filter by date ({@see HorizonFilteredSource}) only return records due within
 * their lead time.
 */
class ObligationInbox implements ResetInterface
{
    /** @var array<string, list<array{0: Obligation, 1: list<User>}>> */
    private array $built = [];

    public function __construct(
        private readonly DeadlineSourceRegistry $sources,
        private readonly ResponsibilityResolver $responsibility,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * The user's open duties, soonest first (undated last).
     *
     * @return list<Obligation>
     */
    public function forUser(User $user, Tenant $tenant, ?DateTimeImmutable $now = null): array
    {
        $mine = [];
        foreach ($this->openWithRecipients($tenant, $now) as [$obligation, $recipients]) {
            foreach ($recipients as $recipient) {
                if ($recipient === $user || ($recipient->getId() !== null && $recipient->getId() === $user->getId())) {
                    $mine[] = $obligation;
                    break;
                }
            }
        }

        return self::sorted($mine);
    }

    /**
     * All open duties of the tenant grouped by the person responsible.
     *
     * @return array<int, array{user: User, items: list<Obligation>}> keyed by user id
     */
    public function byRecipient(Tenant $tenant, ?DateTimeImmutable $now = null, bool $urgentOnly = false): array
    {
        $grouped = [];
        foreach ($this->openWithRecipients($tenant, $now, $urgentOnly) as [$obligation, $recipients]) {
            foreach ($recipients as $recipient) {
                $key = (int) $recipient->getId();
                $grouped[$key] ??= ['user' => $recipient, 'items' => []];
                $grouped[$key]['items'][] = $obligation;
            }
        }
        foreach ($grouped as $key => $entry) {
            $grouped[$key]['items'] = self::sorted($entry['items']);
        }

        return $grouped;
    }

    /** Drops the built inboxes and the people looked up for them (last request or organisation). */
    public function reset(): void
    {
        $this->built = [];
        $this->responsibility->reset();
    }

    /**
     * @param bool $urgentOnly only sources with an hour deadline (the urgent mail run)
     * @return list<array{0: Obligation, 1: list<User>}>
     */
    private function openWithRecipients(Tenant $tenant, ?DateTimeImmutable $now, bool $urgentOnly = false): array
    {
        $now ??= new DateTimeImmutable();
        $key = sprintf('%d|%s|%d', (int) $tenant->getId(), $now->format('Y-m-d H:i'), (int) $urgentOnly);
        if ($tenant->getId() === null) {
            return $this->build($tenant, $now, $urgentOnly);
        }

        return $this->built[$key] ??= $this->build($tenant, $now, $urgentOnly);
    }

    /**
     * @return list<array{0: Obligation, 1: list<User>}>
     */
    private function build(Tenant $tenant, DateTimeImmutable $now, bool $urgentOnly): array
    {
        $today = $now->setTime(0, 0);
        $open = [];
        foreach ($this->sources->active($tenant) as $source) {
            try {
                $profile = $source->profile();
                if ($urgentOnly && $profile->urgentHours === null) {
                    continue;
                }
                $horizon = $profile->leadDays !== null ? $today->modify(sprintf('+%d days', $profile->leadDays + 1)) : null;
                $records = $horizon !== null && $source instanceof HorizonFilteredSource
                    ? $source->findOpenDueBy($tenant, $horizon)
                    : $source->findOpen($tenant);
                foreach ($records as $record) {
                    if ($source->isCompleted($record)) {
                        continue;
                    }
                    $due = $source->dueDateOf($record);
                    $due = $due !== null ? DateTimeImmutable::createFromInterface($due) : null;
                    if ($due !== null && $horizon !== null && $due > $horizon) {
                        continue;
                    }
                    $obligation = new Obligation(
                        source: $source,
                        record: $record,
                        title: $source->titleOf($record),
                        dueDate: $due,
                        overdue: $due !== null && $due < ($profile->urgentHours !== null ? $now : $today),
                        urgent: $due !== null && $profile->urgentHours !== null
                            && $due <= $now->modify(sprintf('+%d hours', $profile->urgentHours)),
                    );
                    $open[] = [$obligation, $this->responsibility->recipients($source->responsibleOf($record), $tenant)];
                }
            } catch (\Throwable $e) {
                // One broken source must not empty everybody's inbox.
                $this->logger->error('Deadline source failed', ['source' => $source->slug(), 'error' => $e->getMessage()]);
            }
        }

        return $open;
    }

    /**
     * @param list<Obligation> $items
     * @return list<Obligation>
     */
    private static function sorted(array $items): array
    {
        usort($items, static fn (Obligation $a, Obligation $b): int => [
            $a->dueDate === null, $a->dueDate?->getTimestamp() ?? 0,
        ] <=> [
            $b->dueDate === null, $b->dueDate?->getTimestamp() ?? 0,
        ]);

        return $items;
    }
}
