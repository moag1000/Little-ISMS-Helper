<?php

declare(strict_types=1);

namespace App\Service\Incident;

use App\Enum\SlaDeadlineType;
use DateTimeImmutable;

/**
 * One statutory reporting duty of an incident or data breach: what has to be
 * reported (type), by when (deadline, counted from anchor) and whether it was
 * reported. `field` names the record's "reported at" property, which the
 * "mark as reported" action sets.
 */
final readonly class ReportingDuty
{
    public function __construct(
        public SlaDeadlineType $type,
        public DateTimeImmutable $anchor,
        public DateTimeImmutable $deadline,
        public ?DateTimeImmutable $reportedAt,
        public string $field,
    ) {
    }

    public function isReported(): bool
    {
        return $this->reportedAt !== null;
    }

    /** Reported, and no later than the deadline. */
    public function isReportedInTime(): bool
    {
        return $this->reportedAt !== null && $this->reportedAt <= $this->deadline;
    }

    public function isOverdue(DateTimeImmutable $now = new DateTimeImmutable()): bool
    {
        return $this->reportedAt === null && $this->deadline < $now;
    }
}
