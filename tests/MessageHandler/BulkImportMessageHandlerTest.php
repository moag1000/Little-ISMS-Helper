<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\BulkImportBatch;
use App\Message\BulkImportMessage;
use App\MessageHandler\BulkImportMessageHandler;
use App\Repository\BulkImportBatchRepository;
use App\Service\Import\BulkImportOrchestrator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BulkImportMessageHandler.
 */
#[AllowMockObjectsWithoutExpectations]
final class BulkImportMessageHandlerTest extends TestCase
{
    /** @var BulkImportOrchestrator&MockObject */
    private BulkImportOrchestrator $orchestrator;

    /** @var BulkImportBatchRepository&MockObject */
    private BulkImportBatchRepository $batchRepo;

    private BulkImportMessageHandler $handler;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(BulkImportOrchestrator::class);
        $this->batchRepo    = $this->createMock(BulkImportBatchRepository::class);

        $this->handler = new BulkImportMessageHandler(
            $this->orchestrator,
            $this->batchRepo,
        );
    }

    #[Test]
    public function testInvokeForwardsToCommitWhenBatchExists(): void
    {
        $batch   = $this->createMock(BulkImportBatch::class);
        $message = new BulkImportMessage(99);

        $this->batchRepo->expects($this->once())
            ->method('find')
            ->with(99)
            ->willReturn($batch);

        $this->orchestrator->expects($this->once())
            ->method('commit')
            ->with($batch);

        ($this->handler)($message);
    }

    #[Test]
    public function testInvokeIsNoopWhenBatchDeletedBetweenDispatchAndRun(): void
    {
        $message = new BulkImportMessage(999);

        $this->batchRepo->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        // commit() must NOT be called when batch is null
        $this->orchestrator->expects($this->never())
            ->method('commit');

        ($this->handler)($message);
    }
}
