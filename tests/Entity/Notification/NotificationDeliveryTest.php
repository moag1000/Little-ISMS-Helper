<?php

declare(strict_types=1);

namespace App\Tests\Entity\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationDeliveryTest extends TestCase
{
    #[Test]
    public function testDefaultValues(): void
    {
        $delivery = new NotificationDelivery();

        self::assertNull($delivery->getId());
        self::assertNull($delivery->getTenant());
        self::assertNull($delivery->getRule());
        self::assertNull($delivery->getChannel());
        self::assertSame(NotificationDelivery::STATUS_PENDING, $delivery->getStatus());
        self::assertSame(0, $delivery->getRetries());
        self::assertNull($delivery->getResponsePayload());
        self::assertNull($delivery->getAttemptedAt());
        self::assertNull($delivery->getDeliveredAt());
        self::assertNull($delivery->getErrorMessage());
    }

    #[Test]
    public function testStatusConstants(): void
    {
        self::assertSame('pending',  NotificationDelivery::STATUS_PENDING);
        self::assertSame('sent',     NotificationDelivery::STATUS_SENT);
        self::assertSame('failed',   NotificationDelivery::STATUS_FAILED);
        self::assertSame('retrying', NotificationDelivery::STATUS_RETRYING);
        self::assertCount(4, NotificationDelivery::VALID_STATUSES);
    }

    #[Test]
    public function testMarkSentSetsFieldsCorrectly(): void
    {
        $delivery = new NotificationDelivery();
        $delivery->markSent(['status_code' => 200]);

        self::assertSame(NotificationDelivery::STATUS_SENT, $delivery->getStatus());
        self::assertNotNull($delivery->getDeliveredAt());
        self::assertSame(['status_code' => 200], $delivery->getResponsePayload());
        self::assertNull($delivery->getErrorMessage());
    }

    #[Test]
    public function testMarkFailedSetsFieldsCorrectly(): void
    {
        $delivery = new NotificationDelivery();
        $delivery->markFailed('Connection timeout', ['url' => 'https://example.com']);

        self::assertSame(NotificationDelivery::STATUS_FAILED, $delivery->getStatus());
        self::assertSame('Connection timeout', $delivery->getErrorMessage());
        self::assertSame(['url' => 'https://example.com'], $delivery->getResponsePayload());
        self::assertNull($delivery->getDeliveredAt());
    }

    #[Test]
    public function testAccessors(): void
    {
        $tenant   = new Tenant();
        $rule     = new NotificationRule();
        $channel  = new NotificationChannel();
        $now      = new \DateTimeImmutable();

        $delivery = new NotificationDelivery();
        $delivery->setTenant($tenant);
        $delivery->setRule($rule);
        $delivery->setChannel($channel);
        $delivery->setStatus(NotificationDelivery::STATUS_RETRYING);
        $delivery->setRetries(3);
        $delivery->setAttemptedAt($now);

        self::assertSame($tenant, $delivery->getTenant());
        self::assertSame($rule, $delivery->getRule());
        self::assertSame($channel, $delivery->getChannel());
        self::assertSame(NotificationDelivery::STATUS_RETRYING, $delivery->getStatus());
        self::assertSame(3, $delivery->getRetries());
        self::assertSame($now, $delivery->getAttemptedAt());
    }

    #[Test]
    public function testIncrementRetries(): void
    {
        $delivery = new NotificationDelivery();
        $delivery->setRetries(2);
        $delivery->incrementRetries();
        self::assertSame(3, $delivery->getRetries());
    }
}
