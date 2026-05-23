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
    public function allSixStagesAreCovered(): void
    {
        // Junior-ISB-Audit Phase-2 Lifecycle — `Delivered` + `Archived` added
        // to the original 4-stage set. See `config/workflows/notification_delivery.yaml`.
        self::assertSame('pending', NotificationDeliveryStatus::Pending->value);
        self::assertSame('sent', NotificationDeliveryStatus::Sent->value);
        self::assertSame('delivered', NotificationDeliveryStatus::Delivered->value);
        self::assertSame('failed', NotificationDeliveryStatus::Failed->value);
        self::assertSame('retrying', NotificationDeliveryStatus::Retrying->value);
        self::assertSame('archived', NotificationDeliveryStatus::Archived->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('notification_delivery.status.pending', NotificationDeliveryStatus::Pending->label());
        self::assertSame('notification_delivery.status.sent', NotificationDeliveryStatus::Sent->label());
        self::assertSame('notification_delivery.status.delivered', NotificationDeliveryStatus::Delivered->label());
        self::assertSame('notification_delivery.status.archived', NotificationDeliveryStatus::Archived->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        // Junior-ISB-Audit Phase-2 Lifecycle — `Sent` downgraded from `success`
        // to `info` because `Delivered` is now the explicit end-to-end-ACK
        // success state. `Sent` only means "handed off to transport".
        self::assertSame('neutral', NotificationDeliveryStatus::Pending->pillVariant());
        self::assertSame('info', NotificationDeliveryStatus::Sent->pillVariant());
        self::assertSame('success', NotificationDeliveryStatus::Delivered->pillVariant());
        self::assertSame('danger', NotificationDeliveryStatus::Failed->pillVariant());
        self::assertSame('warning', NotificationDeliveryStatus::Retrying->pillVariant());
        self::assertSame('neutral', NotificationDeliveryStatus::Archived->pillVariant());
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
