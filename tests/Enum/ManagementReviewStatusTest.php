<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\ManagementReview;
use App\Enum\ManagementReviewStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManagementReviewStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('planned', ManagementReviewStatus::Planned->value);
        self::assertSame('completed', ManagementReviewStatus::Completed->value);
        self::assertSame('follow_up_required', ManagementReviewStatus::FollowUpRequired->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('management_review.status.planned', ManagementReviewStatus::Planned->label());
        self::assertSame('management_review.status.follow_up_required', ManagementReviewStatus::FollowUpRequired->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('info', ManagementReviewStatus::Planned->pillVariant());
        self::assertSame('success', ManagementReviewStatus::Completed->pillVariant());
        self::assertSame('warning', ManagementReviewStatus::FollowUpRequired->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new ManagementReview();

        $entity->setStatus(ManagementReviewStatus::Completed);
        self::assertSame('completed', $entity->getStatus());
        self::assertSame(ManagementReviewStatus::Completed, $entity->getStatusEnum());

        $entity->setStatus('follow_up_required');
        self::assertSame('follow_up_required', $entity->getStatus());
        self::assertSame(ManagementReviewStatus::FollowUpRequired, $entity->getStatusEnum());
    }
}
