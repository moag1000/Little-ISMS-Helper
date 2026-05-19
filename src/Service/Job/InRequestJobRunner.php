<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Job\AsyncJobInterface;
use App\Job\JobContext;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Dispatch strategy that runs the job in the SAME PHP-FPM worker as the
 * triggering request — using the setup-wizard's proven
 * {@see \fastcgi_finish_request()} pattern.
 *
 * Sequence:
 *  1. Disable all PHP timeouts so the job can run for hours if it needs to.
 *  2. Release the session lock (if any) so the polling endpoint can read
 *     status concurrently.
 *  3. Mark the job 'running' via {@see JobStatusService}.
 *  4. Detach the response — browser sees the rendered progress page (or a
 *     JSON envelope) immediately and starts polling.
 *  5. Execute the job in the now-detached worker. On any Throwable, persist
 *     the failure so the polling UI surfaces it cleanly.
 *
 * Why this exists alongside Messenger:
 *  - Shared hosting (commodity PHP-FPM with no shell/cron access) cannot run
 *    `messenger:consume async` as a daemon. Without a worker, Messenger jobs
 *    sit in the doctrine queue forever and the polling UI hangs at "pending".
 *  - This runner needs only PHP-FPM (or LiteSpeed) — no out-of-process worker,
 *    no systemd unit, no cron.
 *
 * Fallback path (CLI / non-FPM SAPI): runs the job synchronously and returns.
 * That keeps phpunit + CLI consumers working unchanged.
 *
 * @see \App\Service\Job\MessengerJobRunner — Messenger-based alternative for
 *      multi-server / pre-allocated-worker deployments.
 * @see \App\Service\Job\JobDispatcher — facade that chooses between the two
 *      runners based on `app.async_job.runner` parameter.
 */
// NOT `final` — JobDispatcherTest mocks this class.
class InRequestJobRunner
{
    public function __construct(
        private readonly JobStatusService $jobStatusService,
        #[TaggedLocator('app.async_job')]
        private readonly ContainerInterface $jobs,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Dispatch and execute a job in this same request.
     *
     * On PHP-FPM/LiteSpeed: sends $response, detaches the client connection,
     * then executes the job. The caller still receives $response as the
     * return value for type-safety / framework-level handling — the body has
     * already been flushed at that point.
     *
     * On CLI / non-FPM SAPI: runs the job synchronously, then returns the
     * (un-sent) response so the caller can return it through Symfony's
     * normal request/response cycle.
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
        $isDetachable = function_exists('fastcgi_finish_request')
            || function_exists('litespeed_finish_request');

        // Disable timeouts BEFORE marking the job running — if the markRunning
        // call itself were ever to block, we still want unlimited runtime.
        // Silenced because some shared-hosting providers disable_functions
        // these via php.ini and the resulting WARNING would pollute logs.
        @set_time_limit(0);
        @ignore_user_abort(true);
        @ini_set('max_execution_time', '0');
        @ini_set('memory_limit', '512M');

        // Release the session write-lock so the polling request (which also
        // touches the session) is not serialised behind the long-running job.
        // After save() the session becomes read-only in this PHP process — the
        // job code MUST NOT call any service that writes to the session.
        $session?->save();

        $this->jobStatusService->markRunning($jobId);

        if ($isDetachable) {
            $this->detach($response);
        }

        try {
            $job = $this->resolveJob($jobClass);
            $ctx = new JobContext($jobId, $this->jobStatusService, $args);
            $job->run($ctx);
            // Read current state — don't clobber a terminal status that the
            // job set itself (e.g. via $ctx->message() right before return).
            $current = $this->jobStatusService->read($jobId);
            if (($current['status'] ?? 'running') === 'running') {
                $this->jobStatusService->markSucceeded($jobId);
            }
        } catch (\Throwable $e) {
            $trace = sprintf(
                '%s: %s in %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            );
            $frames = array_slice(explode("\n", $e->getTraceAsString()), 0, 10);
            $trace .= "\n" . implode("\n", $frames);
            $this->jobStatusService->markFailed($jobId, $e->getMessage(), $trace);
            $this->logger->error('In-request job execution failed', [
                'job_id' => $jobId,
                'job_class' => $jobClass,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    /**
     * Drain output buffers and close the FCGI/LiteSpeed connection so the
     * browser receives the response NOW and we can keep working.
     *
     * Mirrors {@see \App\Controller\Trait\DetachableResponseTrait::detachAndContinue()}
     * — kept inlined here (not via the trait) because a service cannot mix in
     * controller traits cleanly, and duplicating five lines is preferable to
     * promoting the trait to a shared library.
     */
    private function detach(Response $response): void
    {
        $response->send();
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }

    /**
     * Resolve a job-class FQCN to an autowired service instance via the
     * `app.async_job` tagged locator. Mirrors
     * {@see \App\MessageHandler\Job\ExecuteJobHandler::resolveJob()}.
     */
    private function resolveJob(string $jobClass): AsyncJobInterface
    {
        if (!class_exists($jobClass)) {
            // @intentional-assertion: programmer error — invalid job class dispatched
            throw new \InvalidArgumentException(sprintf(
                'Job class "%s" does not exist.',
                $jobClass,
            ));
        }

        if (!is_a($jobClass, AsyncJobInterface::class, true)) {
            // @intentional-assertion: programmer error — wrong interface
            throw new \InvalidArgumentException(sprintf(
                'Job class "%s" must implement %s.',
                $jobClass,
                AsyncJobInterface::class,
            ));
        }

        if ($this->jobs->has($jobClass)) {
            /** @var AsyncJobInterface $job */
            $job = $this->jobs->get($jobClass);
            return $job;
        }

        // @intentional-assertion: programmer error — missing app.async_job tag
        throw new \RuntimeException(sprintf(
            'Job class "%s" is not registered. Ensure it implements AsyncJobInterface and is tagged with app.async_job.',
            $jobClass,
        ));
    }
}
