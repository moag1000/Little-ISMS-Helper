<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\Job\JobStatusService;

/**
 * Runtime context passed to an async job during execution.
 *
 * Provides callbacks for progress reporting, message updates,
 * and carries the args that were passed when the job was dispatched
 * (from ExecuteJobMessage::$args).
 *
 * The underlying JobStatusService writes to a file so the HTTP
 * polling endpoint can serve status without a shared process.
 */
final class JobContext
{
    /**
     * @param array<string, mixed> $args Args forwarded from ExecuteJobMessage
     */
    public function __construct(
        private readonly string $jobId,
        private readonly JobStatusService $statusService,
        private readonly array $args = [],
    ) {
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    /**
     * Retrieve an arg that was passed when the job was dispatched.
     *
     * @param mixed $default
     * @return mixed
     */
    public function arg(string $key, mixed $default = null): mixed
    {
        return $this->args[$key] ?? $default;
    }

    /**
     * All args as-is (for structured access).
     *
     * @return array<string, mixed>
     */
    public function args(): array
    {
        return $this->args;
    }

    /**
     * Update progress counters (and optionally a message) on the status file.
     * Call this inside processing loops to give meaningful UI feedback.
     */
    public function progress(int $current, int $total, ?string $message = null): void
    {
        $this->statusService->updateProgress($this->jobId, $current, $total, $message);
    }

    /**
     * Update the free-text status message without changing counters.
     */
    public function message(string $text): void
    {
        $this->statusService->updateProgress(
            $this->jobId,
            0,
            0,
            $text,
        );
    }

    /**
     * Write a heartbeat so the stale-detection timer resets.
     * Call inside tight inner loops that take > 60 s per iteration.
     */
    public function heartbeat(): void
    {
        $this->statusService->heartbeat($this->jobId);
    }
}
