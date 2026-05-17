<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\FourEyesApprovalRequest;
use App\Enum\FourEyesApprovalRequestStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FourEyesApprovalRequestStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('pending', FourEyesApprovalRequestStatus::Pending->value);
        self::assertSame('approved', FourEyesApprovalRequestStatus::Approved->value);
        self::assertSame('rejected', FourEyesApprovalRequestStatus::Rejected->value);
        self::assertSame('expired', FourEyesApprovalRequestStatus::Expired->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('four_eyes.status.pending', FourEyesApprovalRequestStatus::Pending->label());
        self::assertSame('four_eyes.status.rejected', FourEyesApprovalRequestStatus::Rejected->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('info', FourEyesApprovalRequestStatus::Pending->pillVariant());
        self::assertSame('success', FourEyesApprovalRequestStatus::Approved->pillVariant());
        self::assertSame('danger', FourEyesApprovalRequestStatus::Rejected->pillVariant());
        self::assertSame('neutral', FourEyesApprovalRequestStatus::Expired->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new FourEyesApprovalRequest();

        $entity->setStatus(FourEyesApprovalRequestStatus::Approved);
        self::assertSame('approved', $entity->getStatus());
        self::assertSame(FourEyesApprovalRequestStatus::Approved, $entity->getStatusEnum());

        $entity->setStatus('rejected');
        self::assertSame('rejected', $entity->getStatus());
        self::assertSame(FourEyesApprovalRequestStatus::Rejected, $entity->getStatusEnum());
    }
}
