<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\ImportSession;
use App\Enum\ImportSessionStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImportSessionStatusTest extends TestCase
{
    #[Test]
    public function allThreeStagesAreCovered(): void
    {
        self::assertSame('preview', ImportSessionStatus::Preview->value);
        self::assertSame('committed', ImportSessionStatus::Committed->value);
        self::assertSame('cancelled', ImportSessionStatus::Cancelled->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('import_session.status.preview', ImportSessionStatus::Preview->label());
        self::assertSame('import_session.status.committed', ImportSessionStatus::Committed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('info', ImportSessionStatus::Preview->pillVariant());
        self::assertSame('success', ImportSessionStatus::Committed->pillVariant());
        self::assertSame('neutral', ImportSessionStatus::Cancelled->pillVariant());
    }

    #[Test]
    public function importSessionSetStatusAcceptsEnumAndString(): void
    {
        $session = new ImportSession();

        $session->setStatus(ImportSessionStatus::Committed);
        self::assertSame('committed', $session->getStatus());
        self::assertSame(ImportSessionStatus::Committed, $session->getStatusEnum());

        $session->setStatus('cancelled');
        self::assertSame('cancelled', $session->getStatus());
        self::assertSame(ImportSessionStatus::Cancelled, $session->getStatusEnum());
    }
}
