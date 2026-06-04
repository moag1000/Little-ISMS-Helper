<?php

declare(strict_types=1);

namespace App\MessageHandler\Schedule;

use App\Message\Schedule\EnforceRetentionMessage;
use App\Repository\AuditLogRepository;
use App\Repository\SystemSettingsRepository;
use App\Service\RetentionEnforcementService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Weekly data-retention enforcement (GDPR Art. 5(1)(e) storage limitation):
 *
 *   1. per-tenant auto_delete policies (configured ISMS entity types), and
 *   2. audit-log retention (SystemSettings, NIS2 Art. 21.2 floor of 365 days).
 *
 * Runs non-interactively — unlike the manual app:audit-log:cleanup command it
 * never prompts for confirmation.
 */
#[AsMessageHandler]
class EnforceRetentionHandler
{
    /** NIS2 Art. 21.2 minimum for audit logs. */
    private const int AUDIT_MIN_DAYS = 365;
    private const int AUDIT_DEFAULT_DAYS = 730;

    public function __construct(
        private readonly RetentionEnforcementService $retentionEnforcementService,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly SystemSettingsRepository $systemSettingsRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(EnforceRetentionMessage $message): void
    {
        // 1. Per-tenant auto_delete policies (opt-in entity types only).
        $report = $this->retentionEnforcementService->enforce(apply: true);
        $deletedEntities = array_sum(array_column($report, 'deleted'));
        $this->logger->info('Retention: per-tenant policies enforced', [
            'policy_lines' => count($report),
            'deleted' => $deletedEntities,
        ]);

        // 2. Audit-log retention (NIS2 floor).
        $days = max(self::AUDIT_MIN_DAYS, $this->resolveAuditRetentionDays());
        $cutoff = new DateTimeImmutable(sprintf('-%d days', $days));
        try {
            $deletedLogs = $this->auditLogRepository->deleteOldLogs($cutoff);
            $this->entityManager->flush();
            $this->logger->info('Retention: audit logs purged', [
                'deleted' => $deletedLogs,
                'retention_days' => $days,
            ]);
        } catch (\Throwable $throwable) {
            $this->logger->error('Retention: audit-log purge failed', ['error' => $throwable->getMessage()]);
        }
    }

    private function resolveAuditRetentionDays(): int
    {
        $raw = $this->systemSettingsRepository->getSetting('audit', 'retention_days', self::AUDIT_DEFAULT_DAYS);
        if (is_int($raw)) {
            return $raw;
        }

        return is_string($raw) && ctype_digit($raw) ? (int) $raw : self::AUDIT_DEFAULT_DAYS;
    }
}
