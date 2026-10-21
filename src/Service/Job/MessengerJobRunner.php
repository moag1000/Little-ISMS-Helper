<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Job\AsyncJobInterface;
use App\Entity\User;
use App\Message\Job\ExecuteJobMessage;
use App\Repository\UserRepository;
use App\Service\TenantContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatch strategy that hands the job off to a Symfony Messenger worker
 * (`messenger:consume async`). Use when you have systemd / supervisord /
 * Docker workers running independently of the web request lifecycle —
 * e.g. multi-server deployments, or jobs that must survive an HTTP timeout
 * from an upstream load-balancer that doesn't honour the FCGI detach.
 *
 * In the `test` environment, ExecuteJobMessage is routed to the sync transport
 * (see `config/packages/test/messenger.yaml`) so WebTestCase still observes
 * the job-side effects without spinning up a worker.
 *
 * @see \App\Service\Job\InRequestJobRunner — default strategy for shared
 *      hosting; runs jobs in the same PHP-FPM worker as the triggering
 *      request via {@see \fastcgi_finish_request()}.
 * @see \App\Service\Job\JobDispatcher — facade that chooses between the two
 *      runners based on `app.async_job.runner` parameter.
 */
// NOT `final` — JobDispatcherTest mocks this class.
class MessengerJobRunner
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly JobStatusService $jobStatusService,
        private readonly TenantContext $tenantContext,
        private readonly Security $security,
        private readonly ?UserRepository $userRepository = null,
    ) {
    }

    /**
     * Enqueue an ExecuteJobMessage and return the prepared response unchanged.
     *
     * The job will be picked up by `messenger:consume async`; in the meantime
     * the polling UI sees status='pending'. The caller is expected to render
     * a progress template (or build a JsonResponse) before calling dispatch().
     *
     * @param class-string<AsyncJobInterface> $jobClass
     * @param array<string, mixed>            $args
     */
    public function dispatch(
        string $jobClass,
        array $args,
        string $jobId,
        Response $response,
        ?SessionInterface $session = null,
    ): Response {
        // Defensive: ensure the status record exists. ExecuteJobHandler bails
        // out silently if the file is missing — that would manifest as a
        // job stuck at 'pending' forever.
        if (!$this->jobStatusService->exists($jobId)) {
            // @intentional-assertion: caller did not pre-create the status record
            throw new \LogicException(sprintf(
                'Cannot dispatch job %s: JobStatusService::create() must be called before dispatch().',
                $jobId,
            ));
        }

        // Suppress the unused $session warning — kept in the signature to
        // mirror InRequestJobRunner so JobDispatcher can switch strategies
        // without changing call-sites.
        unset($session);

        // Capture the tenant NOW, while the HTTP request context still exists.
        // The worker has no request, so it cannot resolve this itself.
        $instanceWide = $this->isHonouredInstanceWideJob($args, $jobId);
        $tenantId = $instanceWide ? null : $this->tenantContext->getCurrentTenantId();
        $this->messageBus->dispatch(new ExecuteJobMessage(
            jobClass: $jobClass,
            args: $args,
            jobId: $jobId,
            tenantId: $tenantId,
            unscoped: $tenantId === null && ($instanceWide || $this->mayRunUnscoped()),
        ));

        return $response;
    }

    /**
     * An instance-wide job ({@see JobTenantScope::ARG_ALL_TENANTS} without a
     * tenant id) runs unscoped — exactly as in the in-request runner, whose
     * request filter is unscoped for an instance operator; TenantContext would
     * instead hand that account a default organisation and truncate the
     * export. The flag is honoured ONLY when the job's recorded owner
     * (JobStatusService `owner_user_id`) is a ROLE_SUPER_ADMIN — defence in
     * depth against a flag smuggled into the args. Otherwise it is ignored and
     * the job keeps the dispatching tenant scope (fail closed).
     *
     * @param array<string, mixed> $args
     */
    private function isHonouredInstanceWideJob(array $args, string $jobId): bool
    {
        if (($args[JobTenantScope::ARG_ALL_TENANTS] ?? null) !== true
            || ($args[JobTenantScope::ARG_TENANT_ID] ?? null) !== null
        ) {
            return false;
        }

        $ownerId = $this->jobStatusService->read($jobId)['owner_user_id'] ?? null;
        if (!is_int($ownerId) || $this->userRepository === null) {
            return false;
        }
        $owner = $this->userRepository->find($ownerId);

        return $owner instanceof User && in_array('ROLE_SUPER_ADMIN', $owner->getRoles(), true);
    }

    /**
     * Whether a job without a tenant may run unscoped — the same decision
     * TenantFilterSubscriber takes for the in-request runner: an anonymous
     * request or an instance operator. Every other account without an
     * organisation is queued for the no-organisation scope (fail closed).
     */
    private function mayRunUnscoped(): bool
    {
        return $this->security->getUser() === null || $this->security->isGranted('ROLE_SUPER_ADMIN');
    }
}
