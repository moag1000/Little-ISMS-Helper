<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TenantRepository;
use App\Service\BackupService;
use App\Service\RestoreService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backup:restore',
    description: 'Restore an ISMS backup from a JSON or gzipped backup file.',
)]
class RestoreBackupCommand extends Command
{
    public function __construct(
        private readonly BackupService    $backupService,
        private readonly RestoreService   $restoreService,
        private readonly TenantRepository $tenantRepository,
        private readonly LoggerInterface  $logger,
        private readonly string           $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'filepath',
                InputArgument::REQUIRED,
                'Path to the backup file (.json or .json.gz). Use "-" to read from stdin.'
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate restore without writing to the database')
            ->addOption('best-effort', null, InputOption::VALUE_NONE, 'Skip failing rows instead of aborting on the first error')
            ->addOption('clear-before-restore', null, InputOption::VALUE_NONE, 'Delete all existing data before restoring')
            ->addOption('skip-entities', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of entity types to skip (e.g. AuditLog,UserSession)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant code to scope the restore to a specific tenant')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of human-readable text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filepath           = (string) $input->getArgument('filepath');
        $dryRun             = (bool) $input->getOption('dry-run');
        $bestEffort         = (bool) $input->getOption('best-effort');
        $clearBeforeRestore = (bool) $input->getOption('clear-before-restore');
        $skipEntitiesRaw    = $input->getOption('skip-entities');
        $tenantCode         = $input->getOption('tenant');
        $jsonOutput         = (bool) $input->getOption('json');

        $skipEntities = [];
        if ($skipEntitiesRaw !== null && $skipEntitiesRaw !== '') {
            $skipEntities = array_filter(array_map('trim', explode(',', $skipEntitiesRaw)));
        }

        // --- Resolve tenant scope ---
        $tenantScope = null;
        if ($tenantCode !== null) {
            $tenantScope = $this->tenantRepository->findOneBy(['code' => $tenantCode]);
            if ($tenantScope === null) {
                $message = sprintf('Tenant with code "%s" not found.', $tenantCode);
                $this->writeError($output, $io, $jsonOutput, $message);
                return Command::FAILURE;
            }
        }

        // --- Resolve file path ---
        $resolvedPath = $filepath;
        if ($filepath !== '-') {
            if (!str_starts_with($filepath, '/')) {
                $resolvedPath = $this->projectDir . '/' . $filepath;
            }
            if (!file_exists($resolvedPath)) {
                // Try the default backups directory
                $backupsDir   = $this->projectDir . '/var/backups/' . basename($filepath);
                $resolvedPath = file_exists($backupsDir) ? $backupsDir : $resolvedPath;
            }
            if (!file_exists($resolvedPath)) {
                $message = sprintf('Backup file not found: %s', $filepath);
                $this->writeError($output, $io, $jsonOutput, $message);
                return Command::FAILURE;
            }
        }

        // --- Load backup ---
        try {
            if ($filepath === '-') {
                $raw = stream_get_contents(STDIN);
                if ($raw === false) {
                    $this->writeError($output, $io, $jsonOutput, 'Failed to read backup from stdin.');
                    return Command::FAILURE;
                }
                $backup = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } else {
                $backup = $this->backupService->loadBackupFromFile($resolvedPath);
            }
        } catch (\Throwable $loadException) {
            $message = sprintf('Failed to load backup: %s', $loadException->getMessage());
            $this->writeError($output, $io, $jsonOutput, $message);
            return Command::FAILURE;
        }

        $options = [
            'dry_run'             => $dryRun,
            'best_effort'         => $bestEffort,
            'clear_before_restore' => $clearBeforeRestore,
            'skip_entities'       => array_values($skipEntities),
            'missing_field_strategy' => RestoreService::STRATEGY_USE_DEFAULT,
            'existing_data_strategy' => RestoreService::EXISTING_UPDATE,
        ];

        $this->logger->info('RestoreBackup: starting', [
            'filepath'     => $resolvedPath,
            'dry_run'      => $dryRun,
            'best_effort'  => $bestEffort,
            'tenant_scope' => $tenantScope?->getId(),
        ]);

        // --- Execute restore ---
        $startMs = (int) round(microtime(true) * 1000);
        try {
            $result = $this->restoreService->restoreFromBackup($backup, $options, $tenantScope);
        } catch (\Throwable $restoreException) {
            $durationMs = (int) round(microtime(true) * 1000) - $startMs;
            $this->logger->error('RestoreBackup: failed', [
                'error'       => $restoreException->getMessage(),
                'duration_ms' => $durationMs,
            ]);
            $this->writeError($output, $io, $jsonOutput, $restoreException->getMessage());
            return Command::FAILURE;
        }
        $durationMs = (int) round(microtime(true) * 1000) - $startMs;

        $failures = $result['failures'] ?? [];
        $success  = $result['success'] ?? false;

        // --- Output ---
        if ($jsonOutput) {
            $output->writeln(json_encode(array_merge($result, [
                'duration_ms' => $durationMs,
                'filepath'    => $resolvedPath,
            ]), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->renderHumanOutput($io, $result, $failures, $dryRun, $bestEffort, $durationMs);
        }

        $this->logger->info('RestoreBackup: completed', [
            'success'      => $success,
            'dry_run'      => $dryRun,
            'best_effort'  => $bestEffort,
            'failures'     => count($failures),
            'duration_ms'  => $durationMs,
        ]);

        // Exit 0 for full success OR best-effort-partial; Exit 1 only for strict-mode failures
        if (!$success && !$bestEffort) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Render human-readable restore output via SymfonyStyle.
     *
     * @param array $result    Result from RestoreService::restoreFromBackup()
     * @param array $failures  Failure records (from result['failures'])
     */
    private function renderHumanOutput(
        SymfonyStyle $io,
        array $result,
        array $failures,
        bool $dryRun,
        bool $bestEffort,
        int $durationMs
    ): void {
        $success = $result['success'] ?? false;

        if ($dryRun) {
            $io->info('Dry-run mode — no changes written to the database.');
        }

        if ($success || $bestEffort) {
            if ($failures === []) {
                $io->success(sprintf(
                    'Restore completed successfully in %d ms.',
                    $durationMs
                ));
            } else {
                $io->warning(sprintf(
                    'Restore completed in %d ms with %d skipped row(s) (best-effort mode).',
                    $durationMs,
                    count($failures)
                ));
            }
        } else {
            $io->error(sprintf(
                'Restore failed after %d ms. See logs for details.',
                $durationMs
            ));
        }

        // Statistics table
        $stats = $result['statistics'] ?? [];
        if ($stats !== []) {
            $rows = [];
            foreach ($stats as $entityName => $entityStats) {
                if (!is_array($entityStats)) {
                    continue;
                }
                $rows[] = [
                    $entityName,
                    $entityStats['created'] ?? 0,
                    $entityStats['updated'] ?? 0,
                    $entityStats['skipped'] ?? 0,
                    $entityStats['errors']  ?? 0,
                ];
            }
            if ($rows !== []) {
                $io->table(['Entity', 'Created', 'Updated', 'Skipped', 'Errors'], $rows);
            }
        }

        // Warnings
        $warnings = $result['warnings'] ?? [];
        if ($warnings !== []) {
            $io->section('Warnings');
            foreach ($warnings as $warning) {
                $io->writeln(' <comment>⚠ ' . $warning . '</comment>');
            }
        }

        // Failures (best-effort)
        if ($failures !== []) {
            $io->section(sprintf('Skipped rows (%d)', count($failures)));
            $failureRows = [];
            foreach ($failures as $failure) {
                $failureRows[] = [
                    $failure['entity']        ?? '-',
                    (string) ($failure['row_index'] ?? '-'),
                    (string) ($failure['row_id']    ?? '-'),
                    $failure['error_message'] ?? '-',
                ];
            }
            $io->table(['Entity', 'Row #', 'Row ID', 'Error'], $failureRows);
        }
    }

    /**
     * Output an error in the appropriate format.
     */
    private function writeError(OutputInterface $output, SymfonyStyle $io, bool $jsonOutput, string $message): void
    {
        if ($jsonOutput) {
            $output->writeln(json_encode(['success' => false, 'error' => $message]));
        } else {
            $io->error($message);
        }
    }
}
