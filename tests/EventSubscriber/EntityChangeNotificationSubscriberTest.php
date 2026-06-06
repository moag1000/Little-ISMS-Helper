<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\IncidentSeverity;
use App\EventSubscriber\EntityChangeNotificationSubscriber;
use App\Service\Notification\Event\DomainEvent;
use App\Service\Notification\Event\DomainEventDetector;
use App\Service\Notification\Event\DomainEventNotifier;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the Doctrine glue: onFlush buffers detected events, postFlush fires
 * them through the notifier, and the re-entrancy guard prevents the nested
 * delivery-flush from re-collecting.
 */
#[AllowMockObjectsWithoutExpectations]
final class EntityChangeNotificationSubscriberTest extends TestCase
{
    #[Test]
    public function onFlushBuffersAndPostFlushFires(): void
    {
        $incident = (new Incident())->setTenant(new Tenant())->setSeverity(IncidentSeverity::Low);

        $notifier = $this->createMock(DomainEventNotifier::class);
        $notifier->expects(self::once())
            ->method('notify')
            ->with(self::callback(static fn (DomainEvent $e): bool => $e->eventType === 'incident.created'));

        $subscriber = new EntityChangeNotificationSubscriber(new DomainEventDetector(), $notifier);

        $subscriber->onFlush($this->onFlushArgs([$incident], []));
        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));
    }

    #[Test]
    public function postFlushWithEmptyBufferIsNoOp(): void
    {
        $notifier = $this->createMock(DomainEventNotifier::class);
        $notifier->expects(self::never())->method('notify');

        $subscriber = new EntityChangeNotificationSubscriber(new DomainEventDetector(), $notifier);
        $subscriber->postFlush($this->createMock(PostFlushEventArgs::class));
    }

    /**
     * @param object[] $insertions
     * @param object[] $updates
     */
    private function onFlushArgs(array $insertions, array $updates): OnFlushEventArgs
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($insertions);
        $uow->method('getScheduledEntityUpdates')->willReturn($updates);
        $uow->method('getEntityChangeSet')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);

        return new OnFlushEventArgs($em);
    }
}
