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
 *
 * Carries the dispatching tenant because the worker runs outside the HTTP
 * request: TenantFilterSubscriber never fires there, so without this the
 * Doctrine tenant_filter stays unarmed and jobs read across ALL tenants.
 */
readonly final class ExecuteJobMessage
{
    /**
     * @param string               $jobClass FQCN of class implementing AsyncJobInterface
     * @param array<string, mixed> $args     Constructor args forwarded to the job
     * @param string               $jobId    UUID v4 pre-created by JobStatusService
     * @param int|null             $tenantId Tenant owning the dispatching request,
     *                                       captured while the HTTP context still
     *                                       exists. null = unscoped (instance admin),
     *                                       mirroring TenantFilterSubscriber.
     */
    public function __construct(
        public string $jobClass,
        public array $args,
        public string $jobId,
        public ?int $tenantId = null,
    ) {
    }
}
