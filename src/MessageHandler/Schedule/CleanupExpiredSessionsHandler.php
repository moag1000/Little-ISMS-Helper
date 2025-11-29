<?php

namespace App\MessageHandler\Schedule;

use App\Message\Schedule\CleanupExpiredSessionsMessage;
use App\Repository\UserSessionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles cleanup of expired session records
 *
 * Removes sessions older than configured timeout
 */
#[AsMessageHandler]
class CleanupExpiredSessionsHandler
{
    public function __construct(
        private readonly UserSessionRepository $sessionRepository,
        private readonly LoggerInterface $logger
    ) {}

    public function __invoke(CleanupExpiredSessionsMessage $message): void
    {
        $this->logger->info('Starting expired session cleanup', [
            'scheduled_at' => $message->getScheduledAt()->format('Y-m-d H:i:s'),
        ]);

        try {
            $deletedCount = $this->sessionRepository->cleanupExpiredSessions();

            $this->logger->info('Expired session cleanup completed', [
                'cleaned_up_count' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup expired sessions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
