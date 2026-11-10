<?php

declare(strict_types=1);

namespace App\Tests\Stub;

use App\Entity\Tenant;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;
use RuntimeException;
use stdClass;

/**
 * In-memory deadline source for tests: each record is a stdClass with
 * id, title, due (?DateTimeInterface), done (bool) and who (Responsibility).
 */
final class FakeDeadlineSource implements DeadlineSource
{
    /** How often findOpen() ran (load tests, S31). */
    public int $reads = 0;

    /** @param list<stdClass> $records */
    public function __construct(
        private readonly string $slug,
        private readonly DeadlineProfile $profile,
        private readonly array $records = [],
        private readonly bool $broken = false,
    ) {
    }

    public static function record(int $id, Responsibility $who, ?DateTimeInterface $due = null, bool $done = false): stdClass
    {
        $record = new stdClass();
        $record->id = $id;
        $record->title = 'Record ' . $id;
        $record->due = $due;
        $record->done = $done;
        $record->who = $who;

        return $record;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function label(): string
    {
        return ucfirst($this->slug);
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    public function findOpen(Tenant $tenant): iterable
    {
        ++$this->reads;
        if ($this->broken) {
            throw new RuntimeException('broken source');
        }

        return $this->records;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        return $item->due;
    }

    public function titleOf(object $item): string
    {
        return $item->title;
    }

    public function isCompleted(object $item): bool
    {
        return $item->done;
    }

    public function refId(object $item): int
    {
        return $item->id;
    }

    public function supports(object $item): bool
    {
        return in_array($item, $this->records, true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        return $item->who;
    }

    public function linkOf(object $item): ?RecordLink
    {
        return new RecordLink('app_test_show', ['id' => $item->id]);
    }

    public function profile(): DeadlineProfile
    {
        return $this->profile;
    }
}
