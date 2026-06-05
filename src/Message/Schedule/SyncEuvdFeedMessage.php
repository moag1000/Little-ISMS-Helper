<?php

declare(strict_types=1);

namespace App\Message\Schedule;

use DateTimeImmutable;

/**
 * F39 — scheduled message to sync the ENISA EU Vulnerability Database (EUVD)
 * feed and flag existing vulnerabilities present in the EU database.
 *
 * Dispatched by {@see \App\Schedule} (daily). Handled by
 * {@see \App\MessageHandler\Schedule\SyncEuvdFeedHandler}.
 */
final readonly class SyncEuvdFeedMessage
{
    public function __construct(
        public DateTimeImmutable $scheduledAt = new DateTimeImmutable(),
    ) {
    }
}
