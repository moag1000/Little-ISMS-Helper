<?php

declare(strict_types=1);

namespace App\Job;

use App\Repository\TenantRepository;
use App\Service\BackupService;
use App\Service\RestoreService;

/**
 * Async admin job: restore a backup file into the live database.
 *
 * Wraps BackupService::loadBackupFromFile() + RestoreService::restoreFromBackup().
 * Large backups (10k+ rows) can easily exceed PHP-FPM's 30 s timeout; the
 * worker process has its own --time-limit (defaults to 300 s in dev / 3600 s
 * in systemd).
 *
 * Args:
 *   filename          (string) — backup filename in var/backups/
 *   options           (array)  — see RestoreService::restoreFromBackup()
 *                                  (admin_password is NOT logged)
 *   targetTenantId    (?int)   — null for cross-tenant restore (ROLE_SUPER_ADMIN)
 *
 * NOTE: the controller already validated the filename pattern + tenant
 * scope before dispatch; the job assumes both are well-formed.
 */
final class RestoreBackupJob implements AsyncJobInterface
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly RestoreService $restoreService,
        private readonly TenantRepository $tenantRepository,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $filepath = (string) $ctx->arg('filepath', '');
        $options = $ctx->arg('options', []);
        $targetTenantId = $ctx->arg('targetTenantId');

        if ($filepath === '' || !is_file($filepath)) {
            // @intentional-assertion: programmer error — invalid backup path
            throw new \RuntimeException(sprintf('Backup file not found: "%s".', $filepath));
        }
        if (!is_array($options)) {
            $options = [];
        }

        $targetTenantScope = null;
        if ($targetTenantId !== null) {
            $targetTenantScope = $this->tenantRepository->find((int) $targetTenantId);
            if ($targetTenantScope === null) {
                // @intentional-assertion: job arg references nonexistent tenant
                throw new \RuntimeException(sprintf('Target tenant #%d not found.', $targetTenantId));
            }
        }

        $ctx->message('Loading backup file…');
        $backup = $this->backupService->loadBackupFromFile($filepath);

        $ctx->message('Restoring entities (this may take several minutes)…');
        $result = $this->restoreService->restoreFromBackup($backup, $options, $targetTenantScope);

        $stats = $result['statistics'] ?? [];
        $totalRestored = 0;
        $totalFailed = 0;
        if (is_array($stats)) {
            foreach ($stats as $row) {
                if (is_array($row)) {
                    $totalRestored += (int) ($row['restored'] ?? 0);
                    $totalFailed += (int) ($row['failed'] ?? 0);
                }
            }
        }

        $isDryRun = (bool) ($result['dry_run'] ?? false);
        $warnings = $result['warnings'] ?? [];
        $warningCount = is_array($warnings) ? count($warnings) : 0;

        $message = $isDryRun
            ? sprintf('Dry-run done. %d row(s) would be restored, %d would fail.', $totalRestored, $totalFailed)
            : sprintf('Done. %d row(s) restored, %d failed.', $totalRestored, $totalFailed);

        if ($warningCount > 0) {
            $message .= sprintf(' %d warning(s) — see audit log.', $warningCount);
        }

        $ctx->progress($totalRestored, $totalRestored + $totalFailed, $message);

        if (!($result['success'] ?? false) && !$isDryRun) {
            // @intentional-assertion: surface restore-flag failure to operator
            throw new \RuntimeException('Restore reported success=false — review warnings / failures.');
        }
    }
}
