<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BulkImportBatch;
use App\Entity\BulkImportRow;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class BulkImportBatchTest extends TestCase
{
    #[Test]
    public function testDefaultsAfterConstruct(): void
    {
        $batch = new BulkImportBatch();

        self::assertSame(BulkImportBatch::MODE_INITIAL, $batch->getMode());
        self::assertSame(BulkImportBatch::STATUS_UPLOADED, $batch->getStatus());
        self::assertSame(0, $batch->getRowCountTotal());
        self::assertSame(0, $batch->getRowCountSuccess());
        self::assertSame(0, $batch->getRowCountSkipped());
        self::assertSame(0, $batch->getRowCountError());
        self::assertSame(0, $batch->getRowCountUpdated());
        self::assertCount(0, $batch->getRows());
        self::assertNull($batch->getBatchId());
        self::assertNull($batch->getCommittedAt());
        self::assertNotNull($batch->getCreatedAt());
    }

    #[Test]
    public function testAddRowAttachesBackReference(): void
    {
        $batch = new BulkImportBatch();
        $row = new BulkImportRow();
        $row->setRowNumber(2);
        $row->setParsedData(['name' => 'Server-A']);

        $batch->addRow($row);

        self::assertCount(1, $batch->getRows());
        self::assertSame($batch, $row->getBatch());
    }

    #[Test]
    public function testRemoveRowDetachesBackReference(): void
    {
        $batch = new BulkImportBatch();
        $row = new BulkImportRow();
        $row->setRowNumber(2);
        $row->setParsedData([]);

        $batch->addRow($row);
        $batch->removeRow($row);

        self::assertCount(0, $batch->getRows());
        self::assertNull($row->getBatch());
    }

    #[Test]
    public function testIsCompletedReflectsStatus(): void
    {
        $batch = new BulkImportBatch();
        self::assertFalse($batch->isCompleted());

        $batch->setStatus(BulkImportBatch::STATUS_COMPLETED);
        self::assertTrue($batch->isCompleted());
    }

    #[Test]
    public function testIsDeltaModeFlag(): void
    {
        $batch = new BulkImportBatch();
        self::assertFalse($batch->isDeltaMode());

        $batch->setMode(BulkImportBatch::MODE_DELTA);
        self::assertTrue($batch->isDeltaMode());
    }

    #[Test]
    public function testRelationsArePropagated(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $user = $this->createMock(User::class);

        $batch = new BulkImportBatch();
        $batch->setTenant($tenant);
        $batch->setExecutedBy($user);
        $batch->setEntityType('Asset');
        $batch->setSourceFileName('assets-2026.xlsx');
        $batch->setSourceFileHash(str_repeat('a', 64));
        $batch->setSourceFileSize('12345');

        self::assertSame($tenant, $batch->getTenant());
        self::assertSame($user, $batch->getExecutedBy());
        self::assertSame('Asset', $batch->getEntityType());
        self::assertSame('assets-2026.xlsx', $batch->getSourceFileName());
        self::assertSame(str_repeat('a', 64), $batch->getSourceFileHash());
        self::assertSame('12345', $batch->getSourceFileSize());
    }
}
