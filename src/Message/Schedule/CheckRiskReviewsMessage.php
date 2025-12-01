<?php

namespace App\Message\Schedule;

use DateTimeImmutable;

/**
 * Scheduled message to check for risks requiring periodic review
 *
 * ISO 27001:2022 Clause 6.1.3.d - Risks should be reviewed periodically
 * Runs daily at 8:00 AM to identify risks due for review
 */
class CheckRiskReviewsMessage
{
    public function __construct(
        private readonly DateTimeImmutable $scheduledAt = new DateTimeImmutable()
    ) {}

    public function getScheduledAt(): DateTimeImmutable
    {
        return $this->scheduledAt;
    }
}
