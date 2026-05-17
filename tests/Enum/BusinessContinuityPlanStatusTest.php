<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\BusinessContinuityPlan;
use App\Enum\BusinessContinuityPlanStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BusinessContinuityPlanStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('draft', BusinessContinuityPlanStatus::Draft->value);
        self::assertSame('under_review', BusinessContinuityPlanStatus::UnderReview->value);
        self::assertSame('active', BusinessContinuityPlanStatus::Active->value);
        self::assertSame('archived', BusinessContinuityPlanStatus::Archived->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('bc_plans.status.draft', BusinessContinuityPlanStatus::Draft->label());
        self::assertSame('bc_plans.status.active', BusinessContinuityPlanStatus::Active->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', BusinessContinuityPlanStatus::Draft->pillVariant());
        self::assertSame('info', BusinessContinuityPlanStatus::UnderReview->pillVariant());
        self::assertSame('success', BusinessContinuityPlanStatus::Active->pillVariant());
        self::assertSame('neutral', BusinessContinuityPlanStatus::Archived->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new BusinessContinuityPlan();

        $entity->setStatus(BusinessContinuityPlanStatus::Active);
        self::assertSame('active', $entity->getStatus());
        self::assertSame(BusinessContinuityPlanStatus::Active, $entity->getStatusEnum());

        $entity->setStatus('archived');
        self::assertSame('archived', $entity->getStatus());
        self::assertSame(BusinessContinuityPlanStatus::Archived, $entity->getStatusEnum());
    }
}
