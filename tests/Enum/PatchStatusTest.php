<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\Patch;
use App\Enum\PatchStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatchStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('pending', PatchStatus::Pending->value);
        self::assertSame('testing', PatchStatus::Testing->value);
        self::assertSame('approved', PatchStatus::Approved->value);
        self::assertSame('deployed', PatchStatus::Deployed->value);
        self::assertSame('failed', PatchStatus::Failed->value);
        self::assertSame('rolled_back', PatchStatus::RolledBack->value);
        self::assertSame('not_applicable', PatchStatus::NotApplicable->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('patch.status.pending', PatchStatus::Pending->label());
        self::assertSame('patch.status.rolled_back', PatchStatus::RolledBack->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', PatchStatus::Pending->pillVariant());
        self::assertSame('info', PatchStatus::Testing->pillVariant());
        self::assertSame('warning', PatchStatus::Approved->pillVariant());
        self::assertSame('success', PatchStatus::Deployed->pillVariant());
        self::assertSame('danger', PatchStatus::Failed->pillVariant());
        self::assertSame('danger', PatchStatus::RolledBack->pillVariant());
        self::assertSame('neutral', PatchStatus::NotApplicable->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new Patch();

        $entity->setStatus(PatchStatus::Deployed);
        self::assertSame('deployed', $entity->getStatus());
        self::assertSame(PatchStatus::Deployed, $entity->getStatusEnum());

        $entity->setStatus('rolled_back');
        self::assertSame('rolled_back', $entity->getStatus());
        self::assertSame(PatchStatus::RolledBack, $entity->getStatusEnum());
    }
}
