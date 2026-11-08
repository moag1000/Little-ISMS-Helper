<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use DateTimeImmutable;

/** Records of one type the retention sweep deletes at {@see $deletesAt}. */
final readonly class UpcomingDeletion
{
    public function __construct(
        public string $type,
        public int $id,
        public int $count,
        public DateTimeImmutable $deletesAt,
    ) {
    }
}
