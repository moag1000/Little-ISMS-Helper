<?php

declare(strict_types=1);

namespace App\MessageHandler\Job;

use App\Job\AsyncJobInterface;
use App\Job\JobContext;
use App\Message\Job\ExecuteJobMessage;
use App\Service\Job\JobStatusService;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\TaggedLocator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles ExecuteJobMessage by resolving the target job class from
 * the tagged service locator (keyed by FQCN) and calling run() with a JobContext.
 *
 * On Throwable: writes 'failed' + truncated traceback to the status file.
 * No Messenger auto-retry — admin tasks must be idempotent if re-dispatched
 * manually, but automatic retry risks double mutations on data-repair ops.
 *
 * Job classes are registered automatically via the `app.async_job` tag
 * (services.yaml _instanceof block tags all AsyncJobInterface implementations).
 * The locator indexes by service ID which equals the FQCN for autowired services.
 */
#[AsMessageHandler]
final class ExecuteJobHandler
{
    public function __construct(
        private readonly JobStatusService $jobStatusService,
        #[TaggedLocator('app.async_job')]
        private readonly ContainerInterface $jobs,
    ) {
    }

    public function __invoke(ExecuteJobMessage $message): void
    {
        $id = $message->jobId;

        // Guard: job file must exist (may have been deleted via admin UI)
        if (!$this->jobStatusService->exists($id)) {
            return;
        }

        $this->jobStatusService->markRunning($id);

        try {
            $job = $this->resolveJob($message->jobClass);
            $ctx = new JobContext($id, $this->jobStatusService, $message->args);
            $job->run($ctx);
            $this->jobStatusService->markSucceeded($id);
        } catch (\Throwable $e) {
            $trace = sprintf(
                '%s: %s in %s:%d',
                $e::class,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            );
            // Append up to 10 frames of stack trace for debugging
            $frames = array_slice(explode("\n", $e->getTraceAsString()), 0, 10);
            $trace .= "\n" . implode("\n", $frames);

            $this->jobStatusService->markFailed($id, $e->getMessage(), $trace);
        }
    }

    private function resolveJob(string $jobClass): AsyncJobInterface
    {
        if (!class_exists($jobClass)) {
            throw new \InvalidArgumentException(sprintf(
                'Job class "%s" does not exist.',
                $jobClass,
            ));
        }

        if (!is_a($jobClass, AsyncJobInterface::class, true)) {
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

        throw new \RuntimeException(sprintf(
            'Job class "%s" is not registered. Ensure it implements AsyncJobInterface and is tagged with app.async_job.',
            $jobClass,
        ));
    }
}
