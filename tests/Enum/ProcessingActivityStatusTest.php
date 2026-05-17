<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\ProcessingActivity;
use App\Enum\ProcessingActivityStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProcessingActivityStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('draft', ProcessingActivityStatus::Draft->value);
        self::assertSame('in_review', ProcessingActivityStatus::InReview->value);
        self::assertSame('approved', ProcessingActivityStatus::Approved->value);
        self::assertSame('published', ProcessingActivityStatus::Published->value);
        self::assertSame('archived', ProcessingActivityStatus::Archived->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('processing_activity.status.draft', ProcessingActivityStatus::Draft->label());
        self::assertSame('processing_activity.status.published', ProcessingActivityStatus::Published->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', ProcessingActivityStatus::Draft->pillVariant());
        self::assertSame('info', ProcessingActivityStatus::InReview->pillVariant());
        self::assertSame('warning', ProcessingActivityStatus::Approved->pillVariant());
        self::assertSame('success', ProcessingActivityStatus::Published->pillVariant());
        self::assertSame('neutral', ProcessingActivityStatus::Archived->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $pa = new ProcessingActivity();

        $pa->setStatus(ProcessingActivityStatus::Approved);
        self::assertSame('approved', $pa->getStatus());
        self::assertSame(ProcessingActivityStatus::Approved, $pa->getStatusEnum());

        $pa->setStatus('published');
        self::assertSame('published', $pa->getStatus());
        self::assertSame(ProcessingActivityStatus::Published, $pa->getStatusEnum());
    }
}
