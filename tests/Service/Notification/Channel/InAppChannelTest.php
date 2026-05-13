<?php

declare(strict_types=1);

namespace App\Tests\Service\Notification\Channel;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Service\Notification\Channel\InAppChannel;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class InAppChannelTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private InAppChannel $inAppChannel;

    protected function setUp(): void
    {
        $this->em           = $this->createMock(EntityManagerInterface::class);
        $this->inAppChannel = new InAppChannel($this->em);
    }

    #[Test]
    public function testDeliverMarksSentWithEntityState(): void
    {
        $rule     = new NotificationRule();
        $rule->setName('CISO In-App Alert');
        $rule->setEventType('control.verification.overdue');

        $channel  = new NotificationChannel();
        $channel->setType(NotificationChannel::TYPE_IN_APP);

        $delivery = new NotificationDelivery();
        $delivery->setAttemptedAt(new DateTimeImmutable());

        $entityState = ['control_id' => 42, 'verification_overdue' => true];

        $this->inAppChannel->deliver($rule, $channel, $delivery, $entityState);

        self::assertSame(NotificationDelivery::STATUS_SENT, $delivery->getStatus());
        self::assertNotNull($delivery->getDeliveredAt());

        $payload = $delivery->getResponsePayload();
        self::assertIsArray($payload);
        self::assertSame('CISO In-App Alert', $payload['rule_name']);
        self::assertSame('control.verification.overdue', $payload['event_type']);
        self::assertSame($entityState, $payload['entity_state']);
    }
}
