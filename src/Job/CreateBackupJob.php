<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\TenantRepository;
use App\Service\BackupService;

/**
 * Async admin job: create a full DB backup and persist it to var/backups/.
 *
 * Wraps BackupService::createBackup() + saveBackupToFile() so a multi-GB
 * tenant tree no longer pegs PHP-FPM for >30 s.
 *
 * Args:
 *   includeAuditLog     (bool)  — include audit_log table
 *   includeUserSessions (bool)  — include session rows
 *   tenantId            (?int)  — tenant scope (null = full export)
 *
 * Produces no DB writes itself; the filename is persisted to the status
 * record via the message field once saveBackupToFile() returns.
 */
final class CreateBackupJob implements AsyncJobInterface
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly TenantRepository $tenantRepository,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $includeAuditLog = (bool) $ctx->arg('includeAuditLog', true);
        $includeUserSessions = (bool) $ctx->arg('includeUserSessions', false);
        $tenantId = $ctx->arg('tenantId');

        $tenantScope = null;
        if ($tenantId !== null) {
            $tenantScope = $this->tenantRepository->find((int) $tenantId);
            if ($tenantScope === null) {
                // @intentional-assertion: job arg references nonexistent tenant
                throw new \RuntimeException(sprintf('Target tenant #%d not found.', $tenantId));
            }
        }

        $ctx->message('Collecting backup data…');
        $backup = $this->backupService->createBackup(
            $includeAuditLog,
            $includeUserSessions,
            true,
            $tenantScope,
        );

        $ctx->message('Writing backup file to disk…');
        $filepath = $this->backupService->saveBackupToFile($backup);

        $stats = $backup['statistics'] ?? [];
        $totalRows = 0;
        if (is_array($stats)) {
            foreach ($stats as $rowCount) {
                if (is_int($rowCount)) {
                    $totalRows += $rowCount;
                } elseif (is_array($rowCount) && isset($rowCount['count']) && is_int($rowCount['count'])) {
                    $totalRows += $rowCount['count'];
                }
            }
        }

        $ctx->progress(
            $totalRows,
            $totalRows,
            sprintf(
                'Done. Backup saved as %s (%d row(s) total)%s.',
                basename($filepath),
                $totalRows,
                $tenantScope !== null ? sprintf(' for tenant "%s"', $tenantScope->getName()) : '',
            ),
        );
    }
}
