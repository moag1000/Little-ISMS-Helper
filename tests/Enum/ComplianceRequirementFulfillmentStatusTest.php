<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\ComplianceRequirementFulfillment;
use App\Enum\ComplianceRequirementFulfillmentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ComplianceRequirementFulfillmentStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('not_started', ComplianceRequirementFulfillmentStatus::NotStarted->value);
        self::assertSame('in_progress', ComplianceRequirementFulfillmentStatus::InProgress->value);
        self::assertSame('implemented', ComplianceRequirementFulfillmentStatus::Implemented->value);
        self::assertSame('verified', ComplianceRequirementFulfillmentStatus::Verified->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('compliance.fulfillment.status.not_started', ComplianceRequirementFulfillmentStatus::NotStarted->label());
        self::assertSame('compliance.fulfillment.status.verified', ComplianceRequirementFulfillmentStatus::Verified->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', ComplianceRequirementFulfillmentStatus::NotStarted->pillVariant());
        self::assertSame('info', ComplianceRequirementFulfillmentStatus::InProgress->pillVariant());
        self::assertSame('warning', ComplianceRequirementFulfillmentStatus::Implemented->pillVariant());
        self::assertSame('success', ComplianceRequirementFulfillmentStatus::Verified->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new ComplianceRequirementFulfillment();

        $entity->setStatus(ComplianceRequirementFulfillmentStatus::Implemented);
        self::assertSame('implemented', $entity->getStatus());
        self::assertSame(ComplianceRequirementFulfillmentStatus::Implemented, $entity->getStatusEnum());

        $entity->setStatus('verified');
        self::assertSame('verified', $entity->getStatus());
        self::assertSame(ComplianceRequirementFulfillmentStatus::Verified, $entity->getStatusEnum());
    }
}
