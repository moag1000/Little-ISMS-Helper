<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Responsibility\ResponsibilityResolver;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Every open duty from every deadline source, at the person responsible for it.
 *
 * A duty shows up when it has no date (open task), when its source has no
 * lead time, or when its deadline is within the source's lead days. Each duty
 * appears exactly once per person — My Day, the digest mail and the urgent
 * mail all read from here.
 */
class ObligationInbox
{
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
    public function byRecipient(Tenant $tenant, ?DateTimeImmutable $now = null): array
    {
        $grouped = [];
        foreach ($this->openWithRecipients($tenant, $now) as [$obligation, $recipients]) {
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

    /**
     * @return iterable<array{0: Obligation, 1: list<User>}>
     */
    private function openWithRecipients(Tenant $tenant, ?DateTimeImmutable $now): iterable
    {
        $now ??= new DateTimeImmutable();
        $today = $now->setTime(0, 0);
        foreach ($this->sources->active($tenant) as $source) {
            try {
                $profile = $source->profile();
                foreach ($source->findOpen($tenant) as $record) {
                    if ($source->isCompleted($record)) {
                        continue;
                    }
                    $due = $source->dueDateOf($record);
                    $due = $due !== null ? DateTimeImmutable::createFromInterface($due) : null;
                    if ($due !== null && $profile->leadDays !== null && $due > $today->modify(sprintf('+%d days', $profile->leadDays + 1))) {
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
                    yield [$obligation, $this->responsibility->recipients($source->responsibleOf($record), $tenant)];
                }
            } catch (\Throwable $e) {
                // One broken source must not empty everybody's inbox.
                $this->logger->error('Deadline source failed', ['source' => $source->slug(), 'error' => $e->getMessage()]);
            }
        }
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
