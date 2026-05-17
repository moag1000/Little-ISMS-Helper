<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\BulkImportRow;
use App\Enum\BulkImportRowStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BulkImportRowStatusTest extends TestCase
{
    #[Test]
    public function allSixStagesAreCovered(): void
    {
        self::assertSame('pending', BulkImportRowStatus::Pending->value);
        self::assertSame('created', BulkImportRowStatus::Created->value);
        self::assertSame('updated', BulkImportRowStatus::Updated->value);
        self::assertSame('unchanged', BulkImportRowStatus::Unchanged->value);
        self::assertSame('skipped', BulkImportRowStatus::Skipped->value);
        self::assertSame('error', BulkImportRowStatus::Error->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('bulk_import_row.status.pending', BulkImportRowStatus::Pending->label());
        self::assertSame('bulk_import_row.status.error', BulkImportRowStatus::Error->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', BulkImportRowStatus::Pending->pillVariant());
        self::assertSame('success', BulkImportRowStatus::Created->pillVariant());
        self::assertSame('info', BulkImportRowStatus::Updated->pillVariant());
        self::assertSame('neutral', BulkImportRowStatus::Unchanged->pillVariant());
        self::assertSame('warning', BulkImportRowStatus::Skipped->pillVariant());
        self::assertSame('danger', BulkImportRowStatus::Error->pillVariant());
    }

    #[Test]
    public function bulkImportRowSetStatusAcceptsEnumAndString(): void
    {
        $row = new BulkImportRow();

        $row->setStatus(BulkImportRowStatus::Created);
        self::assertSame('created', $row->getStatus());
        self::assertSame(BulkImportRowStatus::Created, $row->getStatusEnum());

        $row->setStatus('error');
        self::assertSame('error', $row->getStatus());
        self::assertSame(BulkImportRowStatus::Error, $row->getStatusEnum());
    }
}
