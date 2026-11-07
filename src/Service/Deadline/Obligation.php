<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use DateTimeImmutable;

/**
 * One open duty of one record, as My Day and the reminder mails show it.
 */
final readonly class Obligation
{
    public function __construct(
        public DeadlineSource $source,
        public object $record,
        public string $title,
        public ?DateTimeImmutable $dueDate,
        public bool $overdue,
        public bool $urgent,
    ) {
    }

    public function profile(): DeadlineProfile
    {
        return $this->source->profile();
    }

    public function link(): ?RecordLink
    {
        return $this->source->linkOf($this->record);
    }

    /** Stable key of this duty (source + record + deadline) for "already notified" checks. */
    public function key(): string
    {
        return sprintf(
            '%s:%d:%s',
            $this->source->slug(),
            $this->source->refId($this->record),
            $this->dueDate?->format('Y-m-d\TH') ?? '-',
        );
    }
}
