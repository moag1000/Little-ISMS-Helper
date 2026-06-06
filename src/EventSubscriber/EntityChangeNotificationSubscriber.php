<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Notification\Event\DomainEvent;
use App\Service\Notification\Event\DomainEventDetector;
use App\Service\Notification\Event\DomainEventNotifier;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Bridges Doctrine entity changes to the notification engine — the missing
 * emission layer that makes the 10 entity-driven NotificationRule event types
 * actually fire.
 *
 * WHY two phases:
 *   - onFlush reads the UnitOfWork insertions + change-sets (transitions are only
 *     knowable here) and buffers the resulting domain events. It does NOT
 *     dispatch — dispatching persists NotificationDelivery rows, which must not
 *     happen mid-flush.
 *   - postFlush drains the buffer and fires each event. The dispatcher's own
 *     persist+flush of deliveries triggers a nested flush; a re-entrancy guard
 *     stops that nested cycle from re-collecting (NotificationDelivery is not a
 *     watched entity anyway, so there is no infinite recursion).
 *
 * Storm avoidance: transitions are detected from the change-set, so an event
 * fires once when an entity crosses into the triggering state, never on every
 * subsequent save.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class EntityChangeNotificationSubscriber
{
    /** @var DomainEvent[] */
    private array $buffer = [];

    private bool $firing = false;

    public function __construct(
        private readonly DomainEventDetector $detector,
        private readonly DomainEventNotifier $notifier,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->firing) {
            return; // nested flush from delivery persistence — ignore
        }

        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            foreach ($this->detector->forInsert($entity) as $event) {
                $this->buffer[] = $event;
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $changeSet = $uow->getEntityChangeSet($entity);
            foreach ($this->detector->forUpdate($entity, $changeSet) as $event) {
                $this->buffer[] = $event;
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->firing || $this->buffer === []) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];

        $this->firing = true;
        try {
            foreach ($events as $event) {
                $this->notifier->notify($event);
            }
        } finally {
            $this->firing = false;
        }
    }
}
