<?php

declare(strict_types=1);

namespace App\Command\Bsi;

use App\Repository\ComplianceFrameworkRepository;
use App\Service\Bsi\MappingCorroborationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * WS-5b Stage 1 — Raise trust of heuristic ISO↔BSI mappings via CRT corroboration.
 *
 * This command is the build-time step that deterministically elevates heuristic
 * ISO↔BSI Anforderung-level mappings to the `amtlich_gestuetzt` trust tier by
 * corroborating them against the official BSI Cross-Reference-Table (CRT).
 *
 * ## What it does
 * For every non-official (heuristic / unreviewed) mapping from ISO 27001 to BSI
 * IT-Grundschutz, it checks whether the official CRT contains a row with the same
 * (Baustein, ISO control) pair. If yes, the mapping's `provenanceSource` is set to
 * `crt_corroborated`. `IsoToBsiGapService::trustOf()` then returns `amtlich_gestuetzt`
 * for that mapping, placing it in the TRUSTED bucket rather than the "prüfen" bucket.
 *
 * ## Idempotency
 * The command is safe to re-run at any time. Rows already at `crt_corroborated` are
 * counted but not re-written. Official CRT rows (`official_bsi_crosswalk`) are never
 * modified.
 *
 * ## Prerequisites
 * - BSI IT-Grundschutz framework must be loaded (`app:load-bsi-grundschutz-requirements`)
 * - ISO 27001 framework must be loaded
 * - Official CRT mappings must be seeded (`app:seed-bsi-iso27001-mappings`)
 * - Heuristic mappings must be present in the DB
 *
 * ## Usage
 *   php bin/console app:bsi:corroborate-mappings             # apply elevation
 *   php bin/console app:bsi:corroborate-mappings --dry-run   # preview, no writes
 */
#[AsCommand(
    name: 'app:bsi:corroborate-mappings',
    description: 'WS-5b stage 1 — Elevate heuristic ISO↔BSI mappings corroborated by the official BSI CRT to the amtlich_gestuetzt trust tier'
)]
final class CorroborateBsiMappingsCommand extends Command
{
    private const BSI_FRAMEWORK_CODE = 'BSI_GRUNDSCHUTZ';
    private const ISO_FRAMEWORK_CODE = 'ISO27001';

    public function __construct(
        private readonly MappingCorroborationService $corroborationService,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Compute and print counts without writing any DB changes.'
            )
            ->addOption(
                'verbose-details',
                null,
                InputOption::VALUE_NONE,
                'Print per-mapping detail table (can be long).'
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry-run mode — no database writes will be performed.');
        }

        $iso = $this->frameworkRepository->findOneBy(['code' => self::ISO_FRAMEWORK_CODE]);
        $bsi = $this->frameworkRepository->findOneBy(['code' => self::BSI_FRAMEWORK_CODE]);

        if ($iso === null) {
            $io->error(sprintf(
                'ISO 27001 framework (%s) not found. Run the ISO loader first.',
                self::ISO_FRAMEWORK_CODE
            ));
            return Command::FAILURE;
        }

        if ($bsi === null) {
            $io->error(sprintf(
                'BSI IT-Grundschutz framework (%s) not found. Run app:load-bsi-grundschutz-requirements first.',
                self::BSI_FRAMEWORK_CODE
            ));
            return Command::FAILURE;
        }

        $io->info(sprintf(
            'Corroborating %s → %s heuristic mappings against official CRT…',
            self::ISO_FRAMEWORK_CODE,
            self::BSI_FRAMEWORK_CODE
        ));

        $result = $this->corroborationService->corroborate($iso, $bsi, $dryRun);

        // ── Summary table ──────────────────────────────────────────────────────
        $total = $result['corroborated'] + $result['residual'];
        $rate  = $total > 0 ? round(($result['corroborated'] / $total) * 100, 1) : 0.0;

        $io->table(
            ['Metric', 'Count'],
            [
                ['Corroborated (amtlich_gestuetzt)', $result['corroborated']],
                ['Residual (still heuristisch)',     $result['residual']],
                ['Official CRT rows (unchanged)',    $result['already_official']],
                ['Corroboration rate',               $rate . '%'],
            ]
        );

        // ── Per-Baustein breakdown ─────────────────────────────────────────────
        if ($result['by_baustein'] !== []) {
            $rows = [];
            foreach ($result['by_baustein'] as $baustein => $counts) {
                $rows[] = [
                    $baustein,
                    $counts['corroborated'],
                    $counts['residual'],
                ];
            }
            $io->table(['Baustein', 'Corroborated', 'Residual'], $rows);
        }

        // ── Optional per-mapping details ───────────────────────────────────────
        $showDetails = (bool) $input->getOption('verbose-details');
        if ($showDetails && $result['details'] !== []) {
            $detailRows = [];
            foreach ($result['details'] as $d) {
                $detailRows[] = [
                    $d['mapping_id'] ?? '(new)',
                    $d['baustein'],
                    $d['iso_control'],
                    $d['was_elevated'] ? 'YES' : 'already elevated',
                ];
            }
            $io->table(['Mapping ID', 'Baustein', 'ISO Control', 'Elevated?'], $detailRows);
        }

        if ($dryRun) {
            $io->note('Dry-run complete — no changes were persisted. Remove --dry-run to apply.');
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'CRT corroboration complete. %d mapping(s) elevated to amtlich_gestuetzt; %d residual (need panel review for WS-5b stage 2).',
            $result['corroborated'],
            $result['residual']
        ));

        return Command::SUCCESS;
    }
}
