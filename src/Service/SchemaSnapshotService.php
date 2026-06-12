<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Produces a pre-mutation snapshot of the database so every Quick-Fix
 * schema/data repair has a rollback anchor.
 *
 * Strategy: mysqldump (schema + data) when the binary is available, else the
 * logical BackupService export as a weaker fallback, else a logged skip.
 * Never throws — a missing dump tool must not block an emergency repair, but
 * the operator is warned loudly.
 */
final class SchemaSnapshotService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BackupService $backupService,
        private readonly AuditLogger $auditLogger,
        #[Autowire('%kernel.project_dir%/var/quickfix-snapshots')]
        private readonly string $snapshotDir,
        #[Autowire('mysqldump')]
        private readonly string $mysqldumpBinary = 'mysqldump',
    ) {
    }

    /**
     * @return array{method: 'mysqldump'|'logical'|'skipped', path: ?string, warning: ?string}
     */
    public function snapshot(string $reason): array
    {
        if (!is_dir($this->snapshotDir)) {
            @mkdir($this->snapshotDir, 0775, true);
        }
        $stamp = (new \DateTimeImmutable())->format('Ymd_His');
        $params = $this->connection->getParams();
        $driver = (string) ($params['driver'] ?? '');

        // 1. mysqldump (MySQL/MariaDB only)
        if (str_contains($driver, 'mysql')) {
            $dumpPath = sprintf('%s/snap_%s.sql', $this->snapshotDir, $stamp);
            $dumped = $this->tryMysqldump($params, $dumpPath);
            if ($dumped) {
                $this->auditLogger->logCustom(
                    'admin.schema.snapshot.created',
                    'Doctrine', null, null,
                    ['method' => 'mysqldump', 'path' => $dumpPath, 'reason' => $reason],
                    sprintf('Quick-Fix snapshot (mysqldump) before %s -> %s', $reason, $dumpPath),
                );
                return ['method' => 'mysqldump', 'path' => $dumpPath, 'warning' => null];
            }
        }

        // 2. Logical fallback
        try {
            $backup = $this->backupService->createBackup();
            $path = $this->backupService->saveBackupToFile($backup, sprintf('quickfix_logical_%s.json', $stamp));
            $this->auditLogger->logCustom(
                'admin.schema.snapshot.created',
                'Doctrine', null, null,
                ['method' => 'logical', 'path' => $path, 'reason' => $reason],
                sprintf('Quick-Fix snapshot (logical export) before %s -> %s', $reason, $path),
            );
            return ['method' => 'logical', 'path' => $path, 'warning' => 'mysqldump unavailable - logical export only (no schema DDL captured)'];
        } catch (\Throwable $e) {
            $warning = sprintf('No snapshot taken before %s - mysqldump unavailable and logical export failed: %s', $reason, $e->getMessage());
            $this->auditLogger->logCustom(
                'admin.schema.snapshot.skipped',
                'Doctrine', null, null,
                ['reason' => $reason, 'error' => $e->getMessage()],
                'CRITICAL: ' . $warning,
            );
            return ['method' => 'skipped', 'path' => null, 'warning' => $warning];
        }
    }

    /** @param array<string,mixed> $params */
    private function tryMysqldump(array $params, string $outPath): bool
    {
        $host = (string) ($params['host'] ?? '127.0.0.1');
        $port = (string) ($params['port'] ?? '3306');
        $db = (string) ($params['dbname'] ?? '');
        $user = (string) ($params['user'] ?? '');
        $pass = (string) ($params['password'] ?? '');
        if ($db === '') {
            return false;
        }

        $process = new Process([
            $this->mysqldumpBinary,
            '--host=' . $host,
            '--port=' . $port,
            '--user=' . $user,
            '--single-transaction',
            '--routines',
            '--result-file=' . $outPath,
            $db,
        ], env: ['MYSQL_PWD' => $pass]); // password via env, never on the cmdline
        $process->setTimeout(120);

        try {
            $process->run();
        } catch (\Throwable) {
            return false;
        }

        return $process->isSuccessful() && is_file($outPath) && filesize($outPath) > 0;
    }
}
