<?php

declare(strict_types=1);

namespace App\Message\Schedule;

use DateTimeImmutable;

/**
 * Scheduled message that enforces data-retention (GDPR Art. 5(1)(e)):
 * per-tenant auto_delete policies + audit-log retention. Runs weekly.
 */
final readonly class EnforceRetentionMessage
{
    public function __construct(
        private DateTimeImmutable $scheduledAt = new DateTimeImmutable(),
    ) {
    }

    public function getScheduledAt(): DateTimeImmutable
    {
        return $this->scheduledAt;
    }
}
