<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\TenantContext;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * No organisation sticks from one unit of work to the next (S23).
 *
 * The Messenger worker runs scheduled commands (RunCommandMessage, in the
 * worker's own console Application) and async jobs in ONE container, with one
 * TenantContext and one EntityManager: a pin left by the last report or KPI
 * snapshot scoped the next command — the instance backup then held one
 * organisation, reminders and SLA watchers missed the others.
 *
 * - A command run inside another unit of work (RunCommandMessage in the
 *   worker, admin pages that run a command in-process) puts context and
 *   tenant filter back to what they were when it started — in a web request
 *   that is the request's own scope, so the rest of the request stays
 *   scoped. A top-level command leaves nothing behind: its process ends
 *   (and building the context there would need the EntityManager even for
 *   cache:clear).
 * - After every worker message the context is reset (as kernel.reset does
 *   when reset_on_message is on — this keeps it when it is off).
 */
final class TenantContextResetSubscriber
{
    /** @var list<?\Closure(): void> one entry per running command, null = top level */
    private array $restoreStack = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[AsEventListener(event: ConsoleEvents::COMMAND, priority: 1024)]
    public function onCommand(ConsoleCommandEvent $event): void
    {
        $nested = $this->restoreStack !== [] || $this->requestStack->getMainRequest() !== null;
        $this->restoreStack[] = $nested ? $this->tenantContext->capture() : null;
    }

    #[AsEventListener(event: ConsoleEvents::TERMINATE, priority: -1024)]
    public function onTerminate(ConsoleTerminateEvent $event): void
    {
        $restore = array_pop($this->restoreStack);
        if ($restore !== null) {
            $restore();
        }
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class, priority: -1024)]
    #[AsEventListener(event: WorkerMessageFailedEvent::class, priority: -1024)]
    public function onWorkerMessage(): void
    {
        if ($this->requestStack->getMainRequest() === null) {
            $this->tenantContext->reset();
        }
    }
}
