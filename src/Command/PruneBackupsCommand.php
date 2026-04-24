<?php

declare(strict_types=1);

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backup:prune',
    description: 'Delete old backup files according to age and count retention policies.',
)]
class PruneBackupsCommand extends Command
{
    private const array FILE_PATTERNS = [
        'backup_*.json',
        'backup_*.gz',
        'backup_*.zip',
        'uploaded_*.json',
        'uploaded_*.gz',
        'uploaded_*.zip',
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('keep-days', null, InputOption::VALUE_REQUIRED, 'Delete files older than N days', 30)
            ->addOption('keep-last', null, InputOption::VALUE_REQUIRED, 'Always keep the N most-recent files regardless of age', 10)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be deleted without deleting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keepDays = (int) $input->getOption('keep-days');
        $keepLast = (int) $input->getOption('keep-last');
        $dryRun   = (bool) $input->getOption('dry-run');

        $backupDir = $this->projectDir . '/var/backups';

        if (!is_dir($backupDir)) {
            $io->error('Backup directory does not exist: ' . $backupDir);
            return Command::FAILURE;
        }

        $files = $this->collectFiles($backupDir);

        if ($files === []) {
            $io->success('No backup files found.');
            return Command::SUCCESS;
        }

        // Sort newest-first so we can apply keep-last by index
        usort($files, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        $now      = time();
        $cutoff   = $now - ($keepDays * 86400);
        $rows     = [];
        $pruned   = 0;

        foreach ($files as $index => $file) {
            $ageDays = (int) floor(($now - $file['mtime']) / 86400);
            $isProtectedByKeepLast = $index < $keepLast;
            $isOldEnoughToPrune    = $file['mtime'] < $cutoff;

            if ($isOldEnoughToPrune && !$isProtectedByKeepLast) {
                $action = 'DELETE';
            } else {
                $action = 'KEEP';
            }

            $rows[] = [
                $file['basename'],
                $this->formatBytes($file['size']),
                (string) $ageDays,
                $action,
            ];

            if ($action !== 'DELETE') {
                continue;
            }

            $pruned++;

            if ($dryRun) {
                continue;
            }

            if (!@unlink($file['path'])) {
                $io->warning('Could not delete: ' . $file['path']);
                $this->logger->warning('PruneBackups: failed to delete file', ['path' => $file['path']]);
                $pruned--;
            } else {
                $this->logger->info('PruneBackups: deleted file', [
                    'path'    => $file['path'],
                    'age_days' => $ageDays,
                ]);
            }
        }

        $io->table(['Filename', 'Size', 'Age (days)', 'Action'], $rows);

        if ($dryRun) {
            $io->note(sprintf('DRY RUN — %d file(s) would be deleted.', $pruned));
        } else {
            $io->success(sprintf('Pruned %d file(s).', $pruned));
        }

        return Command::SUCCESS;
    }

    /** @return array<int, array{path: string, basename: string, size: int, mtime: int}> */
    private function collectFiles(string $backupDir): array
    {
        $result = [];

        foreach (self::FILE_PATTERNS as $pattern) {
            foreach (glob($backupDir . '/' . $pattern) ?: [] as $path) {
                if (!is_file($path)) {
                    continue;
                }
                $result[] = [
                    'path'     => $path,
                    'basename' => basename($path),
                    'size'     => (int) filesize($path),
                    'mtime'    => (int) filemtime($path),
                ];
            }
        }

        // Deduplicate by path (patterns may overlap, e.g. *.gz and *.json)
        $unique = [];
        foreach ($result as $item) {
            $unique[$item['path']] = $item;
        }

        return array_values($unique);
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
