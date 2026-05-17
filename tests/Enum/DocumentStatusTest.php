<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\Document;
use App\Enum\DocumentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentStatusTest extends TestCase
{
    #[Test]
    public function allFiveStagesAreCovered(): void
    {
        self::assertSame('draft', DocumentStatus::Draft->value);
        self::assertSame('in_review', DocumentStatus::InReview->value);
        self::assertSame('approved', DocumentStatus::Approved->value);
        self::assertSame('published', DocumentStatus::Published->value);
        self::assertSame('archived', DocumentStatus::Archived->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('document.status.draft', DocumentStatus::Draft->label());
        self::assertSame('document.status.published', DocumentStatus::Published->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', DocumentStatus::Draft->pillVariant());
        self::assertSame('info', DocumentStatus::InReview->pillVariant());
        self::assertSame('warning', DocumentStatus::Approved->pillVariant());
        self::assertSame('success', DocumentStatus::Published->pillVariant());
        self::assertSame('neutral', DocumentStatus::Archived->pillVariant());
    }

    #[Test]
    public function documentSetStatusAcceptsEnumAndString(): void
    {
        $doc = new Document();

        $doc->setStatus(DocumentStatus::Approved);
        self::assertSame('approved', $doc->getStatus());
        self::assertSame(DocumentStatus::Approved, $doc->getStatusEnum());

        $doc->setStatus('published');
        self::assertSame('published', $doc->getStatus());
        self::assertSame(DocumentStatus::Published, $doc->getStatusEnum());
    }
}
