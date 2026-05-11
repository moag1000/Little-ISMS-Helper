<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Async message dispatched by BulkImportOrchestrator::dispatchCommit().
 *
 * Carries only the integer PK so the MessageHandler can re-fetch the batch
 * from the database — avoids serialising the full entity graph.
 */
readonly final class BulkImportMessage
{
    public function __construct(public int $batchId) {}
}
