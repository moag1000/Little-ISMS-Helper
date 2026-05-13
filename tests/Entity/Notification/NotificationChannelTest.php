<?php

declare(strict_types=1);

namespace App\Tests\Entity\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Tenant;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationChannelTest extends TestCase
{
    #[Test]
    public function testDefaultValues(): void
    {
        $channel = new NotificationChannel();

        self::assertNull($channel->getId());
        self::assertNull($channel->getTenant());
        self::assertSame('', $channel->getName());
        self::assertSame(NotificationChannel::TYPE_EMAIL, $channel->getType());
        self::assertSame([], $channel->getConfig());
        self::assertNull($channel->getSecretEncrypted());
        self::assertTrue($channel->isActive());
        self::assertNull($channel->getVerifiedAt());
        self::assertCount(0, $channel->getRules());
    }

    #[Test]
    public function testAccessors(): void
    {
        $tenant = new Tenant();
        $now    = new DateTimeImmutable();

        $channel = new NotificationChannel();
        $channel->setTenant($tenant);
        $channel->setName('Slack Webhook');
        $channel->setType(NotificationChannel::TYPE_WEBHOOK);
        $channel->setConfig(['url' => 'https://hooks.example.com/test']);
        $channel->setSecretEncrypted('encrypted-secret');
        $channel->setIsActive(false);
        $channel->setVerifiedAt($now);

        self::assertSame($tenant, $channel->getTenant());
        self::assertSame('Slack Webhook', $channel->getName());
        self::assertSame(NotificationChannel::TYPE_WEBHOOK, $channel->getType());
        self::assertSame(['url' => 'https://hooks.example.com/test'], $channel->getConfig());
        self::assertSame('encrypted-secret', $channel->getSecretEncrypted());
        self::assertFalse($channel->isActive());
        self::assertSame($now, $channel->getVerifiedAt());
    }

    #[Test]
    public function testValidTypeConstants(): void
    {
        self::assertSame('email',   NotificationChannel::TYPE_EMAIL);
        self::assertSame('webhook', NotificationChannel::TYPE_WEBHOOK);
        self::assertSame('in_app',  NotificationChannel::TYPE_IN_APP);
        self::assertCount(3, NotificationChannel::VALID_TYPES);
    }
}
