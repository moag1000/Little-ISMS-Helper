<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\Notification\NotificationDelivery;
use App\Enum\NotificationDeliveryStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationDeliveryStatusTest extends TestCase
{
    #[Test]
    public function allFourStagesAreCovered(): void
    {
        self::assertSame('pending', NotificationDeliveryStatus::Pending->value);
        self::assertSame('sent', NotificationDeliveryStatus::Sent->value);
        self::assertSame('failed', NotificationDeliveryStatus::Failed->value);
        self::assertSame('retrying', NotificationDeliveryStatus::Retrying->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('notification_delivery.status.pending', NotificationDeliveryStatus::Pending->label());
        self::assertSame('notification_delivery.status.sent', NotificationDeliveryStatus::Sent->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', NotificationDeliveryStatus::Pending->pillVariant());
        self::assertSame('success', NotificationDeliveryStatus::Sent->pillVariant());
        self::assertSame('danger', NotificationDeliveryStatus::Failed->pillVariant());
        self::assertSame('warning', NotificationDeliveryStatus::Retrying->pillVariant());
    }

    #[Test]
    public function notificationDeliverySetStatusAcceptsEnumAndString(): void
    {
        $delivery = new NotificationDelivery();

        $delivery->setStatus(NotificationDeliveryStatus::Sent);
        self::assertSame('sent', $delivery->getStatus());
        self::assertSame(NotificationDeliveryStatus::Sent, $delivery->getStatusEnum());

        $delivery->setStatus('failed');
        self::assertSame('failed', $delivery->getStatus());
        self::assertSame(NotificationDeliveryStatus::Failed, $delivery->getStatusEnum());
    }
}
