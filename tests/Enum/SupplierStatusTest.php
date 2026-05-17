<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\Supplier;
use App\Enum\SupplierStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SupplierStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('active', SupplierStatus::Active->value);
        self::assertSame('inactive', SupplierStatus::Inactive->value);
        self::assertSame('evaluation', SupplierStatus::Evaluation->value);
        self::assertSame('terminated', SupplierStatus::Terminated->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('supplier.status.active', SupplierStatus::Active->label());
        self::assertSame('supplier.status.evaluation', SupplierStatus::Evaluation->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('success', SupplierStatus::Active->pillVariant());
        self::assertSame('neutral', SupplierStatus::Inactive->pillVariant());
        self::assertSame('info', SupplierStatus::Evaluation->pillVariant());
        self::assertSame('danger', SupplierStatus::Terminated->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new Supplier();

        $entity->setStatus(SupplierStatus::Active);
        self::assertSame('active', $entity->getStatus());
        self::assertSame(SupplierStatus::Active, $entity->getStatusEnum());

        $entity->setStatus('terminated');
        self::assertSame('terminated', $entity->getStatus());
        self::assertSame(SupplierStatus::Terminated, $entity->getStatusEnum());
    }
}
