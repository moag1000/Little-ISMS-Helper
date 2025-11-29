<?php

namespace App\Message\Schedule;

/**
 * Scheduled message to generate weekly compliance reports
 *
 * Runs every Monday at 6:00 AM to generate compliance summary reports
 */
class GenerateComplianceReportMessage
{
    public function __construct(
        private readonly \DateTimeImmutable $scheduledAt = new \DateTimeImmutable()
    ) {}

    public function getScheduledAt(): \DateTimeImmutable
    {
        return $this->scheduledAt;
    }
}
