<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Job\AsyncJobInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Facade over the two async-job execution strategies. Controllers inject
 * THIS service (not Messenger, not InRequestJobRunner directly) so the
 * deployment can switch strategies without code changes.
 *
 * Strategy is selected by the `app.async_job.runner` parameter in
 * `config/services.yaml`:
 *  - `in_request` (default) — runs the job in the same PHP-FPM worker via
 *    {@see InRequestJobRunner}. Works on commodity shared hosting with no
 *    worker daemon.
 *  - `messenger` — opt-in for multi-server / pre-allocated-worker deployments.
 *    Hands the job to {@see MessengerJobRunner} which dispatches an
 *    ExecuteJobMessage that a `messenger:consume async` worker picks up.
 *
 * Why a facade rather than two parallel injections at every call-site:
 *  - Lets the ops team flip strategy via parameter without redeploying code.
 *  - Keeps controllers free of conditional `if ($useMessenger) {…}` branches.
 *  - Centralises the "create status row first, then dispatch" contract.
 */
final class JobDispatcher
{
    /** @var "in_request"|"messenger" */
    private readonly string $strategy;

    public function __construct(
        private readonly InRequestJobRunner $inRequestRunner,
        private readonly MessengerJobRunner $messengerRunner,
        string $strategy = 'in_request',
    ) {
        if (!in_array($strategy, ['in_request', 'messenger'], true)) {
            // @intentional-assertion: programmer / config error — invalid strategy
            throw new \InvalidArgumentException(sprintf(
                'Invalid app.async_job.runner "%s" (expected one of: in_request, messenger).',
                $strategy,
            ));
        }
        $this->strategy = $strategy;
    }

    /**
     * Dispatch a job. The status record MUST have been created first via
     * {@see JobStatusService::create()} (the caller usually does this to
     * obtain $jobId and embed it in $response).
     *
     * Returns the Response unchanged — when the active strategy is
     * `in_request` on FPM/LiteSpeed, the response body has already been
     * flushed by the time we return, but you should still `return` it so the
     * Symfony kernel completes the request cycle cleanly.
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
        return match ($this->strategy) {
            'in_request' => $this->inRequestRunner->dispatch($jobClass, $args, $jobId, $response, $session),
            'messenger'  => $this->messengerRunner->dispatch($jobClass, $args, $jobId, $response, $session),
        };
    }

    /**
     * Active strategy name — useful for diagnostics pages (e.g. queue-status
     * dashboard) that want to surface the configured runner.
     *
     * @return "in_request"|"messenger"
     */
    public function getStrategy(): string
    {
        return $this->strategy;
    }
}
