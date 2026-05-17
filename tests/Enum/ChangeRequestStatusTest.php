<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\ChangeRequest;
use App\Enum\ChangeRequestStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChangeRequestStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('draft', ChangeRequestStatus::Draft->value);
        self::assertSame('submitted', ChangeRequestStatus::Submitted->value);
        self::assertSame('under_review', ChangeRequestStatus::UnderReview->value);
        self::assertSame('approved', ChangeRequestStatus::Approved->value);
        self::assertSame('rejected', ChangeRequestStatus::Rejected->value);
        self::assertSame('scheduled', ChangeRequestStatus::Scheduled->value);
        self::assertSame('implemented', ChangeRequestStatus::Implemented->value);
        self::assertSame('verified', ChangeRequestStatus::Verified->value);
        self::assertSame('closed', ChangeRequestStatus::Closed->value);
        self::assertSame('cancelled', ChangeRequestStatus::Cancelled->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('change_request.status.draft', ChangeRequestStatus::Draft->label());
        self::assertSame('change_request.status.implemented', ChangeRequestStatus::Implemented->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', ChangeRequestStatus::Draft->pillVariant());
        self::assertSame('success', ChangeRequestStatus::Approved->pillVariant());
        self::assertSame('danger', ChangeRequestStatus::Rejected->pillVariant());
        self::assertSame('warning', ChangeRequestStatus::Implemented->pillVariant());
        self::assertSame('success', ChangeRequestStatus::Verified->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new ChangeRequest();

        $entity->setStatus(ChangeRequestStatus::Approved);
        self::assertSame('approved', $entity->getStatus());
        self::assertSame(ChangeRequestStatus::Approved, $entity->getStatusEnum());

        $entity->setStatus('verified');
        self::assertSame('verified', $entity->getStatus());
        self::assertSame(ChangeRequestStatus::Verified, $entity->getStatusEnum());
    }
}
