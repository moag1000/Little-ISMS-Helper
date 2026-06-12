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
 * WS-5b Stage 1 — Raise trust of heuristic framework mappings via official-crosswalk corroboration.
 *
 * Generalised command that supports any source↔target framework pair.
 * Default pair is ISO27001 → BSI_GRUNDSCHUTZ (original WS-5b use-case).
 *
 * Additional pairs:
 *   - ISO27701_2025 → GDPR (Annex D, P3 Tier-A quality finalisation):
 *     php bin/console app:bsi:corroborate-mappings \
 *       --source=ISO27701_2025 --target=GDPR \
 *       --official-provenance=official_iso27701_gdpr_annex
 *
 * ## What it does
 * For every non-official (heuristic / unreviewed) mapping for the given pair,
 * it checks whether the official crosswalk contains a row with the same
 * (targetKey, sourceControl) pair. If yes, the mapping's `provenanceSource` is set to
 * `crt_corroborated`. `IsoToBsiGapService::trustOf()` then returns `amtlich_gestuetzt`
 * for that mapping, placing it in the TRUSTED bucket rather than the "prüfen" bucket.
 *
 * ## Idempotency
 * The command is safe to re-run at any time. Rows already at `crt_corroborated` are
 * counted but not re-written. Official crosswalk rows are never modified.
 *
 * ## Usage
 *   php bin/console app:bsi:corroborate-mappings             # apply elevation (ISO27001→BSI)
 *   php bin/console app:bsi:corroborate-mappings --dry-run   # preview, no writes
 *   php bin/console app:bsi:corroborate-mappings \
 *     --source=ISO27701_2025 --target=GDPR \
 *     --official-provenance=official_iso27701_gdpr_annex     # GDPR Annex D pair
 */
#[AsCommand(
    name: 'app:bsi:corroborate-mappings',
    description: 'WS-5b stage 1 — Elevate heuristic mappings corroborated by an official crosswalk to the amtlich_gestuetzt trust tier'
)]
final class CorroborateBsiMappingsCommand extends Command
{
    private const BSI_FRAMEWORK_CODE      = 'BSI_GRUNDSCHUTZ';
    private const ISO_FRAMEWORK_CODE      = 'ISO27001';
    private const DEFAULT_BSI_PROVENANCE  = 'official_bsi_crosswalk';

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
                'source',
                null,
                InputOption::VALUE_REQUIRED,
                'Source framework code (default: ' . self::ISO_FRAMEWORK_CODE . ').',
                self::ISO_FRAMEWORK_CODE,
            )
            ->addOption(
                'target',
                null,
                InputOption::VALUE_REQUIRED,
                'Target framework code (default: ' . self::BSI_FRAMEWORK_CODE . ').',
                self::BSI_FRAMEWORK_CODE,
            )
            ->addOption(
                'official-provenance',
                null,
                InputOption::VALUE_REQUIRED,
                'provenanceSource sentinel that identifies official crosswalk rows (default: ' . self::DEFAULT_BSI_PROVENANCE . ').',
                self::DEFAULT_BSI_PROVENANCE,
            )
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

        /** @var string $sourceCode */
        $sourceCode = (string) $input->getOption('source');
        /** @var string $targetCode */
        $targetCode = (string) $input->getOption('target');
        /** @var string $officialProvenance */
        $officialProvenance = (string) $input->getOption('official-provenance');

        if ($dryRun) {
            $io->note('Dry-run mode — no database writes will be performed.');
        }

        $iso = $this->frameworkRepository->findOneBy(['code' => $sourceCode]);
        $bsi = $this->frameworkRepository->findOneBy(['code' => $targetCode]);

        if ($iso === null) {
            $io->error(sprintf(
                'Source framework (%s) not found in the database.',
                $sourceCode,
            ));
            return Command::FAILURE;
        }

        if ($bsi === null) {
            $io->error(sprintf(
                'Target framework (%s) not found in the database.',
                $targetCode,
            ));
            return Command::FAILURE;
        }

        $io->info(sprintf(
            'Corroborating %s → %s heuristic mappings against official crosswalk (provenance: %s)…',
            $sourceCode,
            $targetCode,
            $officialProvenance,
        ));

        $result = $this->corroborationService->corroborate($iso, $bsi, $officialProvenance, dryRun: $dryRun);

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
            'Corroboration complete (%s → %s). %d mapping(s) elevated to amtlich_gestuetzt; %d residual.',
            $sourceCode,
            $targetCode,
            $result['corroborated'],
            $result['residual'],
        ));

        return Command::SUCCESS;
    }
}
