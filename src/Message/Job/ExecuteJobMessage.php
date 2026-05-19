<?php

declare(strict_types=1);

namespace App\Message\Job;

/**
 * Messenger message that triggers an async admin job.
 *
 * Carries the FQCN of the job class (must implement AsyncJobInterface),
 * the constructor args to pass, and the pre-created job ID so the
 * polling endpoint can serve status even before the worker picks it up.
 *
 * Routed to the 'async' transport (Doctrine-backed) — see messenger.yaml.
 */
readonly final class ExecuteJobMessage
{
    /**
     * @param string               $jobClass FQCN of class implementing AsyncJobInterface
     * @param array<string, mixed> $args     Constructor args forwarded to the job
     * @param string               $jobId    UUID v4 pre-created by JobStatusService
     */
    public function __construct(
        public string $jobClass,
        public array $args,
        public string $jobId,
    ) {
    }
}
