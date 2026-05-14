<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Message\Notification\DispatchNotificationMessage;
use App\Service\Notification\NotificationDispatcher;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[AllowMockObjectsWithoutExpectations]
final class NotificationDispatcherTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private MessageBusInterface&MockObject $bus;
    private NotificationDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->bus        = $this->createMock(MessageBusInterface::class);
        $this->dispatcher = new NotificationDispatcher($this->em, $this->bus);
    }

    private function makeRule(int $ruleId): NotificationRule
    {
        // We need an entity with an ID — use reflection to set it
        $rule = new NotificationRule();
        $rule->setName('Test Rule');
        $rule->setEventType('incident.created');

        $ref = new \ReflectionProperty($rule, 'id');
        $ref->setValue($rule, $ruleId);

        return $rule;
    }

    private function makeChannel(int $channelId): NotificationChannel
    {
        $channel = new NotificationChannel();
        $channel->setType(NotificationChannel::TYPE_EMAIL);

        $ref = new \ReflectionProperty($channel, 'id');
        $ref->setValue($channel, $channelId);

        return $channel;
    }

    #[Test]
    public function testDispatchPersistsDeliveryAndSendsMessage(): void
    {
        $tenant  = new Tenant();
        $rule    = $this->makeRule(10);
        $rule->setTenant($tenant);
        $channel = $this->makeChannel(20);
        $rule->addChannel($channel);

        $this->em->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(NotificationDelivery::class));
        $this->em->expects($this->once())->method('flush');

        $dispatchedMessages = [];
        $this->bus->method('dispatch')
            ->willReturnCallback(function ($message) use (&$dispatchedMessages) {
                $dispatchedMessages[] = $message;
                return new Envelope($message);
            });

        $deliveries = $this->dispatcher->dispatch($rule, ['severity' => 'high']);

        self::assertCount(1, $deliveries);
        self::assertInstanceOf(NotificationDelivery::class, $deliveries[0]);
        self::assertSame(NotificationDelivery::STATUS_PENDING, $deliveries[0]->getStatus());

        self::assertCount(1, $dispatchedMessages);
        self::assertInstanceOf(DispatchNotificationMessage::class, $dispatchedMessages[0]);
        self::assertSame(10, $dispatchedMessages[0]->ruleId);
        self::assertSame(20, $dispatchedMessages[0]->channelId);
        self::assertSame(['severity' => 'high'], $dispatchedMessages[0]->entityState);
    }

    #[Test]
    public function testDispatchCreatesOneDeliveryPerChannel(): void
    {
        $tenant   = new Tenant();
        $rule     = $this->makeRule(1);
        $rule->setTenant($tenant);
        $channel1 = $this->makeChannel(1);
        $channel2 = $this->makeChannel(2);
        $rule->addChannel($channel1);
        $rule->addChannel($channel2);

        $this->em->method('persist');
        $this->em->method('flush');
        $this->bus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $deliveries = $this->dispatcher->dispatch($rule, []);

        self::assertCount(2, $deliveries);
    }
}
