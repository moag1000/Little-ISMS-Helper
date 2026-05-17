<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\Consent;
use App\Enum\ConsentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsentStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('active', ConsentStatus::Active->value);
        self::assertSame('revoked', ConsentStatus::Revoked->value);
        self::assertSame('expired', ConsentStatus::Expired->value);
        self::assertSame('pending_verification', ConsentStatus::PendingVerification->value);
        self::assertSame('rejected', ConsentStatus::Rejected->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('consent.status.active', ConsentStatus::Active->label());
        self::assertSame('consent.status.revoked', ConsentStatus::Revoked->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('success', ConsentStatus::Active->pillVariant());
        self::assertSame('neutral', ConsentStatus::Revoked->pillVariant());
        self::assertSame('warning', ConsentStatus::Expired->pillVariant());
        self::assertSame('info', ConsentStatus::PendingVerification->pillVariant());
        self::assertSame('danger', ConsentStatus::Rejected->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $consent = new Consent();

        $consent->setStatus(ConsentStatus::Active);
        self::assertSame('active', $consent->getStatus());
        self::assertSame(ConsentStatus::Active, $consent->getStatusEnum());

        $consent->setStatus('revoked');
        self::assertSame('revoked', $consent->getStatus());
        self::assertSame(ConsentStatus::Revoked, $consent->getStatusEnum());
    }
}
