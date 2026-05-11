<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\BulkImportMessage;
use App\Repository\BulkImportBatchRepository;
use App\Service\Import\BulkImportOrchestrator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Async handler for BulkImportMessage.
 *
 * Resolves the BulkImportBatch from the database and delegates to
 * BulkImportOrchestrator::commit(). Idempotent on missing batch (e.g. batch
 * deleted between dispatch and execution — treated as no-op).
 */
#[AsMessageHandler]
final class BulkImportMessageHandler
{
    public function __construct(
        private readonly BulkImportOrchestrator $orchestrator,
        private readonly BulkImportBatchRepository $batchRepo,
    ) {}

    public function __invoke(BulkImportMessage $message): void
    {
        $batch = $this->batchRepo->find($message->batchId);

        if (!$batch) {
            // Batch was deleted between dispatch and execution — skip silently.
            return;
        }

        $this->orchestrator->commit($batch);
    }
}
