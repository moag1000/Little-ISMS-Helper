<?php

declare(strict_types=1);

namespace App\MessageHandler\Schedule;

use App\Message\Schedule\SyncEuvdFeedMessage;
use App\Service\Vulnerability\EuvdSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * F39 — handles the scheduled EUVD feed sync. Enrichment-only: flags existing
 * vulnerabilities present in the EU Vulnerability Database.
 */
#[AsMessageHandler]
final class SyncEuvdFeedHandler
{
    public function __construct(
        private readonly EuvdSyncService $syncService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncEuvdFeedMessage $message): void
    {
        $result = $this->syncService->sync();

        $this->logger->info('EUVD feed sync completed', [
            'scheduled_at' => $message->scheduledAt->format('Y-m-d H:i:s'),
            'fetched'      => $result['fetched'],
            'flagged'      => $result['flagged'],
            'matched_cves' => $result['matched_cves'],
        ]);
    }
}
