<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\PrototypeProtectionAssessment;
use App\Enum\PrototypeProtectionAssessmentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrototypeProtectionAssessmentStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('draft', PrototypeProtectionAssessmentStatus::Draft->value);
        self::assertSame('in_review', PrototypeProtectionAssessmentStatus::InReview->value);
        self::assertSame('approved', PrototypeProtectionAssessmentStatus::Approved->value);
        self::assertSame('rejected', PrototypeProtectionAssessmentStatus::Rejected->value);
        self::assertSame('expired', PrototypeProtectionAssessmentStatus::Expired->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('prototype_protection.status.draft', PrototypeProtectionAssessmentStatus::Draft->label());
        self::assertSame('prototype_protection.status.expired', PrototypeProtectionAssessmentStatus::Expired->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', PrototypeProtectionAssessmentStatus::Draft->pillVariant());
        self::assertSame('info', PrototypeProtectionAssessmentStatus::InReview->pillVariant());
        self::assertSame('success', PrototypeProtectionAssessmentStatus::Approved->pillVariant());
        self::assertSame('danger', PrototypeProtectionAssessmentStatus::Rejected->pillVariant());
        self::assertSame('neutral', PrototypeProtectionAssessmentStatus::Expired->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new PrototypeProtectionAssessment();

        $entity->setStatus(PrototypeProtectionAssessmentStatus::Approved);
        self::assertSame('approved', $entity->getStatus());
        self::assertSame(PrototypeProtectionAssessmentStatus::Approved, $entity->getStatusEnum());

        $entity->setStatus('expired');
        self::assertSame('expired', $entity->getStatus());
        self::assertSame(PrototypeProtectionAssessmentStatus::Expired, $entity->getStatusEnum());
    }
}
