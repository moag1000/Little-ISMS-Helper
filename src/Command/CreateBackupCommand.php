<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TenantRepository;
use App\Service\BackupNotifier;
use App\Service\BackupService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backup:create',
    description: 'Create a backup of all productive ISMS data.',
)]
class CreateBackupCommand extends Command
{
    public function __construct(
        private readonly BackupService    $backupService,
        private readonly TenantRepository $tenantRepository,
        private readonly BackupNotifier   $backupNotifier,
        private readonly LoggerInterface  $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('include-audit-log', null, InputOption::VALUE_NEGATABLE, 'Include audit log in backup', true)
            ->addOption('include-user-sessions', null, InputOption::VALUE_NEGATABLE, 'Include user sessions in backup', false)
            ->addOption('include-files', null, InputOption::VALUE_NEGATABLE, 'Include file references in backup', true)
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant code to scope the backup (optional)')
            ->addOption('notify', null, InputOption::VALUE_REQUIRED, 'E-mail address to notify on completion or failure')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output JSON result instead of human-readable text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $includeAuditLog     = (bool) $input->getOption('include-audit-log');
        $includeUserSessions = (bool) $input->getOption('include-user-sessions');
        $includeFiles        = (bool) $input->getOption('include-files');
        $tenantCode          = $input->getOption('tenant');
        $notifyEmail         = $input->getOption('notify');
        $jsonOutput          = (bool) $input->getOption('json');

        // Resolve tenant scope
        $tenantScope = null;
        if ($tenantCode !== null) {
            $tenantScope = $this->tenantRepository->findOneBy(['code' => $tenantCode]);
            if ($tenantScope === null) {
                $message = sprintf('Tenant with code "%s" not found.', $tenantCode);
                if ($jsonOutput) {
                    $output->writeln(json_encode(['success' => false, 'error' => $message]));
                } else {
                    $io->error($message);
                }
                $this->logger->error('CreateBackup: tenant not found', ['code' => $tenantCode]);
                return Command::FAILURE;
            }
        }

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $this->logger->info('CreateBackup: starting', [
                'include_audit_log'     => $includeAuditLog,
                'include_user_sessions' => $includeUserSessions,
                'include_files'         => $includeFiles,
                'tenant_code'           => $tenantCode,
            ]);

            $backup   = $this->backupService->createBackup($includeAuditLog, $includeUserSessions, $includeFiles, $tenantScope);
            $filepath = $this->backupService->saveBackupToFile($backup);
            $durationMs = (int) round(microtime(true) * 1000) - $startMs;

            $size        = (int) filesize($filepath);
            $entityCount = (int) array_sum($backup['statistics'] ?? []);
            $sha256      = (string) ($backup['metadata']['sha256'] ?? '');

            $result = [
                'success'      => true,
                'path'         => $filepath,
                'size_bytes'   => $size,
                'entity_count' => $entityCount,
                'duration_ms'  => $durationMs,
                'sha256'       => $sha256,
            ];

            $this->logger->info('CreateBackup: completed', $result);

            if ($jsonOutput) {
                $output->writeln(json_encode($result, JSON_UNESCAPED_SLASHES));
            } else {
                $io->success([
                    sprintf('Backup saved: %s', $filepath),
                    sprintf('Size: %s', $this->formatBytes($size)),
                    sprintf('Entities: %d records', $entityCount),
                    sprintf('Duration: %d ms', $durationMs),
                    sprintf('SHA-256: %s', $sha256),
                ]);
            }

            if ($notifyEmail !== null) {
                $this->backupNotifier->notifySuccess($result, $notifyEmail);
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $durationMs = (int) round(microtime(true) * 1000) - $startMs;
            $this->logger->error('CreateBackup: failed', [
                'error'       => $e->getMessage(),
                'duration_ms' => $durationMs,
            ]);

            if ($notifyEmail !== null) {
                try {
                    $this->backupNotifier->notifyFailure($e, $notifyEmail);
                } catch (\Throwable) {
                    // notification failure must not mask the original error
                }
            }

            if ($jsonOutput) {
                $output->writeln(json_encode(['success' => false, 'error' => $e->getMessage()]));
            } else {
                $io->error($e->getMessage());
            }

            return Command::FAILURE;
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            return round($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1_024) {
            return round($bytes / 1_024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
