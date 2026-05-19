<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Job\WorkerHealthService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;

/**
 * Touches the worker heartbeat file on every Messenger event the worker
 * loop emits, so the Worker-Health-UI can detect a live worker even when
 * the queue is currently empty.
 *
 * We listen on TWO events:
 *  - WorkerMessageHandledEvent: confirms a message went through the
 *    handler successfully — strongest signal the worker is alive.
 *  - WorkerRunningEvent: emitted on the worker tick even with an empty
 *    queue, so the UI shows GREEN immediately after a cron run instead
 *    of staying YELLOW until the next message arrives.
 *
 * Cost: one `touch()` per event — file-system only, no DB roundtrip.
 */
final class WorkerHeartbeatSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly WorkerHealthService $workerHealth,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageHandledEvent::class => 'onWorkerEvent',
            WorkerRunningEvent::class => 'onWorkerEvent',
        ];
    }

    public function onWorkerEvent(): void
    {
        $this->workerHealth->recordHeartbeat();
    }
}
