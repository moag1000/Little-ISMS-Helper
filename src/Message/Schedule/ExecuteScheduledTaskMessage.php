<?php

namespace App\Message\Schedule;

/**
 * Message to execute a database-defined scheduled task
 */
class ExecuteScheduledTaskMessage
{
    public function __construct(
        private readonly int $taskId,
        private readonly \DateTimeImmutable $scheduledAt = new \DateTimeImmutable()
    ) {}

    public function getTaskId(): int
    {
        return $this->taskId;
    }

    public function getScheduledAt(): \DateTimeImmutable
    {
        return $this->scheduledAt;
    }
}
