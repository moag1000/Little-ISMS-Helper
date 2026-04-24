<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\BackupRepairService;
use App\Service\RepairReport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command: app:backup:repair <filepath>
 *
 * Analyses (and optionally repairs) a potentially malformed backup file.
 * The cleaned output can then be fed to the restore command.
 *
 * Usage examples:
 *   app:backup:repair damaged.zip
 *   app:backup:repair damaged.zip --output=/var/backups/cleaned.zip
 *   app:backup:repair damaged.json --dry-run
 *   app:backup:repair damaged.zip --json
 *
 * Exit codes:
 *   0 — isRecoverable = true
 *   1 — isRecoverable = false (broken JSON, empty data, file not found)
 */
#[AsCommand(
    name: 'app:backup:repair',
    description: 'Analyse and repair a potentially malformed backup file (salvage-what-you-can semantics).',
)]
class RepairBackupCommand extends Command
{
    public function __construct(
        private readonly BackupRepairService $repairService,
    ) {
        parent::__construct();
    }

    // ------------------------------------------------------------------

    protected function configure(): void
    {
        $this
            ->addArgument(
                'filepath',
                InputArgument::REQUIRED,
                'Absolute or relative path to the backup file to analyse/repair (.zip or .json).',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Destination path for the cleaned backup file. '
                . 'Defaults to <filepath>.repaired.zip or <filepath>.repaired.json.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Analyse only — do not write any output file.',
            )
            ->addOption(
                'json',
                null,
                InputOption::VALUE_NONE,
                'Emit the RepairReport as JSON instead of human-readable output.',
            );
    }

    // ------------------------------------------------------------------

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $filePath */
        $filePath  = $input->getArgument('filepath');
        $isDryRun  = (bool) $input->getOption('dry-run');
        $jsonMode  = (bool) $input->getOption('json');
        $outputOpt = $input->getOption('output');

        // ---- Validate source file exists --------------------------------
        if (!file_exists($filePath)) {
            if ($jsonMode) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error'   => "File not found: {$filePath}",
                ]));
            } else {
                $io->error("File not found: {$filePath}");
            }
            return Command::FAILURE;
        }

        // ---- Derive output path -----------------------------------------
        $outputPath = $this->resolveOutputPath($filePath, $outputOpt);

        // ---- Run analyze or repair --------------------------------------
        try {
            if ($isDryRun) {
                $report = $this->repairService->analyze($filePath);
            } else {
                $report = $this->repairService->repair($filePath, $outputPath);
            }
        } catch (\Throwable $e) {
            if ($jsonMode) {
                $output->writeln(json_encode([
                    'success' => false,
                    'error'   => $e->getMessage(),
                ]));
            } else {
                $io->error('Repair failed: ' . $e->getMessage());
            }
            return Command::FAILURE;
        }

        // ---- Render output ----------------------------------------------
        if ($jsonMode) {
            $result = $report->toArray();
            $result['dry_run']     = $isDryRun;
            $result['output_path'] = $isDryRun ? null : $outputPath;
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderHumanOutput($io, $report, $isDryRun, $outputPath);
        }

        return $report->isRecoverable ? Command::SUCCESS : Command::FAILURE;
    }

    // ------------------------------------------------------------------
    // Rendering helpers
    // ------------------------------------------------------------------

    private function renderHumanOutput(
        SymfonyStyle $io,
        RepairReport $report,
        bool $isDryRun,
        string $outputPath,
    ): void {
        $io->title($isDryRun ? 'Backup Analysis Report (dry-run)' : 'Backup Repair Report');

        // ---- Metadata issues section ------------------------------------
        if ($report->metadataIssues !== []) {
            $io->section('Metadata Issues');
            foreach ($report->metadataIssues as $issue) {
                $io->text(' • ' . $issue);
            }
        } else {
            $io->text('<info>No metadata issues detected.</info>');
        }

        // ---- Per-entity recovery table ----------------------------------
        if ($report->perEntity !== []) {
            $io->section('Per-entity Recovery');

            $rows = [];
            foreach ($report->perEntity as $entityName => $stats) {
                $pct    = $stats['total'] > 0
                    ? round($stats['recovered'] / $stats['total'] * 100) . '%'
                    : 'n/a';
                $topIssue = !empty($stats['issues'])
                    ? mb_strimwidth($stats['issues'][0], 0, 60, '…')
                    : '—';

                $rows[] = [
                    $entityName,
                    (string) $stats['total'],
                    (string) $stats['recovered'],
                    (string) $stats['lost'],
                    $pct,
                    $topIssue,
                ];
            }

            $io->table(
                ['Entity', 'Total', 'Recovered', 'Lost', '%', 'First Issue'],
                $rows,
            );
        }

        // ---- Summary box -------------------------------------------------
        $summaryLines = [
            sprintf('Entity types scanned : %d', $report->totalEntities),
            sprintf('Total rows           : %d', $report->totalRows),
            sprintf('Recovered rows       : %d', $report->recoveredRows),
            sprintf('Lost rows            : %d', $report->lostRows),
        ];

        if ($report->recomputedSha256 !== null) {
            $summaryLines[] = sprintf('Recomputed SHA-256   : %s', $report->recomputedSha256);
        }

        if ($report->isRecoverable) {
            $summaryLines[] = sprintf(
                'Result: %d of %d rows recoverable',
                $report->recoveredRows,
                $report->totalRows,
            );
        } else {
            $summaryLines[] = 'Result: UNRECOVERABLE — no valid rows found.';
        }

        if ($report->isRecoverable) {
            $io->success($summaryLines);
        } else {
            $io->error($summaryLines);
        }

        // ---- Output path info (non-dry-run only) ------------------------
        if (!$isDryRun && $report->isRecoverable) {
            $io->note(sprintf('Cleaned backup written to: %s', $outputPath));
        } elseif ($isDryRun) {
            $io->note('Dry-run mode: no output file was written.');
        }
    }

    // ------------------------------------------------------------------
    // Path helpers
    // ------------------------------------------------------------------

    /**
     * Derive the output path for the cleaned backup file.
     *
     * Uses the --output option when provided; otherwise appends ".repaired"
     * before the extension of the source file.
     */
    private function resolveOutputPath(string $filePath, ?string $outputOpt): string
    {
        if ($outputOpt !== null && $outputOpt !== '') {
            return $outputOpt;
        }

        // Detect extension and insert .repaired before it
        if (str_ends_with(strtolower($filePath), '.zip')) {
            return preg_replace('/\.zip$/i', '.repaired.zip', $filePath) ?? $filePath . '.repaired.zip';
        }

        if (str_ends_with(strtolower($filePath), '.json.gz')) {
            return preg_replace('/\.json\.gz$/i', '.repaired.json', $filePath) ?? $filePath . '.repaired.json';
        }

        if (str_ends_with(strtolower($filePath), '.json')) {
            return preg_replace('/\.json$/i', '.repaired.json', $filePath) ?? $filePath . '.repaired.json';
        }

        // Unknown extension — just append
        return $filePath . '.repaired.json';
    }
}
