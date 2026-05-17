<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\CryptographicOperation;
use App\Enum\CryptographicOperationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CryptographicOperationStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('success', CryptographicOperationStatus::Success->value);
        self::assertSame('failure', CryptographicOperationStatus::Failure->value);
        self::assertSame('pending', CryptographicOperationStatus::Pending->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('crypto.status.success', CryptographicOperationStatus::Success->label());
        self::assertSame('crypto.status.failure', CryptographicOperationStatus::Failure->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('success', CryptographicOperationStatus::Success->pillVariant());
        self::assertSame('danger', CryptographicOperationStatus::Failure->pillVariant());
        self::assertSame('info', CryptographicOperationStatus::Pending->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new CryptographicOperation();

        $entity->setStatus(CryptographicOperationStatus::Failure);
        self::assertSame('failure', $entity->getStatus());
        self::assertSame(CryptographicOperationStatus::Failure, $entity->getStatusEnum());

        $entity->setStatus('success');
        self::assertSame('success', $entity->getStatus());
        self::assertSame(CryptographicOperationStatus::Success, $entity->getStatusEnum());
    }
}
