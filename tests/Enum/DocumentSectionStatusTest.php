<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\DocumentSection;
use App\Enum\DocumentSectionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentSectionStatusTest extends TestCase
{
    #[Test]
    public function allFourStagesAreCovered(): void
    {
        self::assertSame('draft', DocumentSectionStatus::Draft->value);
        self::assertSame('dpo_sign_off', DocumentSectionStatus::DpoSignOff->value);
        self::assertSame('approved', DocumentSectionStatus::Approved->value);
        self::assertSame('rejected', DocumentSectionStatus::Rejected->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('document_section.status.draft', DocumentSectionStatus::Draft->label());
        self::assertSame('document_section.status.dpo_sign_off', DocumentSectionStatus::DpoSignOff->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', DocumentSectionStatus::Draft->pillVariant());
        self::assertSame('info', DocumentSectionStatus::DpoSignOff->pillVariant());
        self::assertSame('success', DocumentSectionStatus::Approved->pillVariant());
        self::assertSame('danger', DocumentSectionStatus::Rejected->pillVariant());
    }

    #[Test]
    public function entitySetStatusAcceptsEnumAndString(): void
    {
        $ds = new DocumentSection();

        $ds->setStatus(DocumentSectionStatus::Approved);
        self::assertSame('approved', $ds->getStatus());
        self::assertSame(DocumentSectionStatus::Approved, $ds->getStatusEnum());

        $ds->setStatus('dpo_sign_off');
        self::assertSame('dpo_sign_off', $ds->getStatus());
        self::assertSame(DocumentSectionStatus::DpoSignOff, $ds->getStatusEnum());
    }
}
