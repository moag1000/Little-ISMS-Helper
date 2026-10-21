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
     *                                       exists.
     * @param bool                 $unscoped Only with $tenantId null: true = run
     *                                       unscoped (an instance-wide job of an
     *                                       instance operator / anonymous request,
     *                                       TenantFilter::UNSCOPED). false = the
     *                                       dispatcher had no organisation and is
     *                                       no instance operator → the worker arms
     *                                       TenantFilter::NO_ORGANISATION, exactly
     *                                       like TenantFilterSubscriber in the
     *                                       in-request runner. Fail closed: a
     *                                       payload without this field (queued
     *                                       before it existed) is never unscoped.
     */
    public function __construct(
        public string $jobClass,
        public array $args,
        public string $jobId,
        public ?int $tenantId = null,
        public bool $unscoped = false,
    ) {
    }
}
