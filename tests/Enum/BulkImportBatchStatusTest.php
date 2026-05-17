<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Entity\BulkImportBatch;
use App\Enum\BulkImportBatchStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BulkImportBatchStatusTest extends TestCase
{
    #[Test]
    public function allSevenStagesAreCovered(): void
    {
        self::assertSame('uploaded', BulkImportBatchStatus::Uploaded->value);
        self::assertSame('mapped', BulkImportBatchStatus::Mapped->value);
        self::assertSame('preview', BulkImportBatchStatus::Preview->value);
        self::assertSame('committing', BulkImportBatchStatus::Committing->value);
        self::assertSame('completed', BulkImportBatchStatus::Completed->value);
        self::assertSame('failed', BulkImportBatchStatus::Failed->value);
        self::assertSame('cancelled', BulkImportBatchStatus::Cancelled->value);
    }

    #[Test]
    public function labelReturnsTranslationKey(): void
    {
        self::assertSame('bulk_import_batch.status.uploaded', BulkImportBatchStatus::Uploaded->label());
        self::assertSame('bulk_import_batch.status.completed', BulkImportBatchStatus::Completed->label());
    }

    #[Test]
    public function pillVariantMapsToAuroraTones(): void
    {
        self::assertSame('neutral', BulkImportBatchStatus::Uploaded->pillVariant());
        self::assertSame('info', BulkImportBatchStatus::Mapped->pillVariant());
        self::assertSame('info', BulkImportBatchStatus::Preview->pillVariant());
        self::assertSame('warning', BulkImportBatchStatus::Committing->pillVariant());
        self::assertSame('success', BulkImportBatchStatus::Completed->pillVariant());
        self::assertSame('danger', BulkImportBatchStatus::Failed->pillVariant());
        self::assertSame('neutral', BulkImportBatchStatus::Cancelled->pillVariant());
    }

    #[Test]
    public function bulkImportBatchSetStatusAcceptsEnumAndString(): void
    {
        $batch = new BulkImportBatch();

        $batch->setStatus(BulkImportBatchStatus::Completed);
        self::assertSame('completed', $batch->getStatus());
        self::assertSame(BulkImportBatchStatus::Completed, $batch->getStatusEnum());

        $batch->setStatus('failed');
        self::assertSame('failed', $batch->getStatus());
        self::assertSame(BulkImportBatchStatus::Failed, $batch->getStatusEnum());
    }
}
