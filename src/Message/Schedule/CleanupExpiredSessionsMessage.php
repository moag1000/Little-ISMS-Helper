<?php

namespace App\Message\Schedule;

use DateTimeImmutable;

/**
 * Scheduled message to clean up expired sessions
 *
 * Runs daily at 3:00 AM to remove expired session records
 */
class CleanupExpiredSessionsMessage
{
    public function __construct(
        private readonly DateTimeImmutable $scheduledAt = new DateTimeImmutable()
    ) {}

    public function getScheduledAt(): DateTimeImmutable
    {
        return $this->scheduledAt;
    }
}
