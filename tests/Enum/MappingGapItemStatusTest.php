<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\MappingGapItem;
use App\Enum\MappingGapItemStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MappingGapItemStatusTest extends TestCase
{
    #[Test]
    public function allStagesAreCovered(): void
    {
        self::assertSame('identified', MappingGapItemStatus::Identified->value);
        self::assertSame('planned', MappingGapItemStatus::Planned->value);
        self::assertSame('in_progress', MappingGapItemStatus::InProgress->value);
        self::assertSame('resolved', MappingGapItemStatus::Resolved->value);
        self::assertSame('wont_fix', MappingGapItemStatus::WontFix->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('mapping_gap_item.status.identified', MappingGapItemStatus::Identified->label());
        self::assertSame('mapping_gap_item.status.resolved', MappingGapItemStatus::Resolved->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', MappingGapItemStatus::Identified->pillVariant());
        self::assertSame('info', MappingGapItemStatus::Planned->pillVariant());
        self::assertSame('warning', MappingGapItemStatus::InProgress->pillVariant());
        self::assertSame('success', MappingGapItemStatus::Resolved->pillVariant());
        self::assertSame('neutral', MappingGapItemStatus::WontFix->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $entity = new MappingGapItem();

        $entity->setStatus(MappingGapItemStatus::Resolved);
        self::assertSame('resolved', $entity->getStatus());
        self::assertSame(MappingGapItemStatus::Resolved, $entity->getStatusEnum());

        $entity->setStatus('wont_fix');
        self::assertSame('wont_fix', $entity->getStatus());
        self::assertSame(MappingGapItemStatus::WontFix, $entity->getStatusEnum());
    }
}
