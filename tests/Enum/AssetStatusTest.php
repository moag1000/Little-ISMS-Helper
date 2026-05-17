<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\Asset;
use App\Enum\AssetStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('active', AssetStatus::Active->value);
        self::assertSame('inactive', AssetStatus::Inactive->value);
        self::assertSame('in_use', AssetStatus::InUse->value);
        self::assertSame('returned', AssetStatus::Returned->value);
        self::assertSame('retired', AssetStatus::Retired->value);
        self::assertSame('disposed', AssetStatus::Disposed->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('asset.status.active', AssetStatus::Active->label());
        self::assertSame('asset.status.disposed', AssetStatus::Disposed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('success', AssetStatus::Active->pillVariant());
        self::assertSame('neutral', AssetStatus::Inactive->pillVariant());
        self::assertSame('info', AssetStatus::InUse->pillVariant());
        self::assertSame('warning', AssetStatus::Returned->pillVariant());
        self::assertSame('neutral', AssetStatus::Retired->pillVariant());
        self::assertSame('danger', AssetStatus::Disposed->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new Asset();

        $entity->setStatus(AssetStatus::InUse);
        self::assertSame('in_use', $entity->getStatus());
        self::assertSame(AssetStatus::InUse, $entity->getStatusEnum());

        $entity->setStatus('disposed');
        self::assertSame('disposed', $entity->getStatus());
        self::assertSame(AssetStatus::Disposed, $entity->getStatusEnum());
    }
}
