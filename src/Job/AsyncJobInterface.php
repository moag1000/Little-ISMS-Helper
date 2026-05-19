<?php

declare(strict_types=1);

namespace App\Job;

/**
 * Contract for async admin jobs dispatched via Symfony Messenger.
 *
 * Implementations are autowired by the DI container, so any service
 * can be constructor-injected as normal. The job runner creates the
 * instance and calls run() inside the worker process.
 *
 * Example:
 *   final class MyJob implements AsyncJobInterface
 *   {
 *       public function __construct(private readonly MyService $svc) {}
 *
 *       public function run(JobContext $ctx): void
 *       {
 *           $ctx->message('Starting…');
 *           $items = $this->svc->findAll();
 *           foreach ($items as $i => $item) {
 *               $this->svc->process($item);
 *               $ctx->progress($i + 1, count($items));
 *           }
 *       }
 *   }
 */
interface AsyncJobInterface
{
    /**
     * Execute the job. Must call $ctx->progress() and/or $ctx->message()
     * to give the polling UI meaningful feedback.
     *
     * Throw any Throwable to signal failure — the handler will catch it,
     * write 'failed' status + traceback, and NOT retry automatically.
     */
    public function run(JobContext $ctx): void;
}
