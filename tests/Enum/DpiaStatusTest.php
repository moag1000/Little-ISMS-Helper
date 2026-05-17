<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\DataProtectionImpactAssessment;
use App\Enum\DpiaStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DpiaStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('draft', DpiaStatus::Draft->value);
        self::assertSame('in_review', DpiaStatus::InReview->value);
        self::assertSame('approved', DpiaStatus::Approved->value);
        self::assertSame('rejected', DpiaStatus::Rejected->value);
        self::assertSame('requires_revision', DpiaStatus::RequiresRevision->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('dpia.status.draft', DpiaStatus::Draft->label());
        self::assertSame('dpia.status.approved', DpiaStatus::Approved->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', DpiaStatus::Draft->pillVariant());
        self::assertSame('info', DpiaStatus::InReview->pillVariant());
        self::assertSame('success', DpiaStatus::Approved->pillVariant());
        self::assertSame('danger', DpiaStatus::Rejected->pillVariant());
        self::assertSame('warning', DpiaStatus::RequiresRevision->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $dpia = new DataProtectionImpactAssessment();

        $dpia->setStatus(DpiaStatus::Approved);
        self::assertSame('approved', $dpia->getStatus());
        self::assertSame(DpiaStatus::Approved, $dpia->getStatusEnum());

        $dpia->setStatus('in_review');
        self::assertSame('in_review', $dpia->getStatus());
        self::assertSame(DpiaStatus::InReview, $dpia->getStatusEnum());
    }
}
