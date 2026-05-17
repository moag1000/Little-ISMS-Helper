<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\SsoUserApproval;
use App\Enum\SsoUserApprovalStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SsoUserApprovalStatusTest extends TestCase
{
    #[Test]
    public function allThreeStagesAreCovered(): void
    {
        self::assertSame('pending', SsoUserApprovalStatus::Pending->value);
        self::assertSame('approved', SsoUserApprovalStatus::Approved->value);
        self::assertSame('rejected', SsoUserApprovalStatus::Rejected->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('sso_user_approval.status.pending', SsoUserApprovalStatus::Pending->label());
        self::assertSame('sso_user_approval.status.approved', SsoUserApprovalStatus::Approved->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('warning', SsoUserApprovalStatus::Pending->pillVariant());
        self::assertSame('success', SsoUserApprovalStatus::Approved->pillVariant());
        self::assertSame('danger', SsoUserApprovalStatus::Rejected->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $sso = new SsoUserApproval();

        $sso->setStatus(SsoUserApprovalStatus::Approved);
        self::assertSame('approved', $sso->getStatus());
        self::assertSame(SsoUserApprovalStatus::Approved, $sso->getStatusEnum());

        $sso->setStatus('rejected');
        self::assertSame('rejected', $sso->getStatus());
        self::assertSame(SsoUserApprovalStatus::Rejected, $sso->getStatusEnum());
    }
}
