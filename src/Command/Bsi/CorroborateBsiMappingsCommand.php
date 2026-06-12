<?php

declare(strict_types=1);

namespace App\Command\Bsi;

use App\Repository\ComplianceFrameworkRepository;
use App\Service\Bsi\IsoToBsiGapService;
use App\Service\Bsi\MappingCorroborationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * WS-5b Stage 1 — Raise trust of heuristic framework mappings via CRT corroboration.
 *
 * This command is the build-time step that deterministically elevates heuristic
 * framework mappings to the `amtlich_gestuetzt` trust tier by corroborating them
 * against an official BSI Cross-Reference-Table (CRT).
 *
 * ## Default mode (ISO → BSI IT-Grundschutz)
 * For every non-official (heuristic / unreviewed) mapping from ISO 27001 to BSI
 * IT-Grundschutz, checks whether the official CRT contains a row with the same
 * (Baustein, ISO control) pair. If yes, the mapping's `provenanceSource` is set to
 * `crt_corroborated`. `IsoToBsiGapService::trustOf()` then returns `amtlich_gestuetzt`
 * for that mapping, placing it in the TRUSTED bucket rather than the "prüfen" bucket.
 *
 * ## Generalized mode (BSI C5 → ISO 27001 and other pairs)
 * Use --source-framework / --target-framework / --official-provenance to run the
 * corroboration for other pairs, e.g.:
 *   php bin/console app:bsi:corroborate-mappings \
 *     --source-framework=BSI-C5 \
 *     --target-framework=ISO27001 \
 *     --official-provenance=official_bsi_c5_iso_crosswalk
 *
 * ## Idempotency
 * The command is safe to re-run at any time. Rows already at `crt_corroborated` are
 * counted but not re-written. Official CRT rows are never modified.
 *
 * ## Prerequisites
 * - Source and target frameworks must be loaded
 * - Official CRT mappings must be seeded for the pair
 * - Heuristic mappings must be present in the DB
 *
 * ## Usage
 *   php bin/console app:bsi:corroborate-mappings             # ISO→BSI defaults, apply
 *   php bin/console app:bsi:corroborate-mappings --dry-run   # preview, no writes
 *   php bin/console app:bsi:corroborate-mappings \
 *     --source-framework=BSI-C5 --target-framework=ISO27001 \
 *     --official-provenance=official_bsi_c5_iso_crosswalk
 */
#[AsCommand(
    name: 'app:bsi:corroborate-mappings',
    description: 'WS-5b stage 1 — Elevate heuristic framework mappings corroborated by an official BSI CRT to the amtlich_gestuetzt trust tier (generalised: --source-framework / --target-framework / --official-provenance)'
)]
final class CorroborateBsiMappingsCommand extends Command
{
    /** Default framework codes (ISO 27001 → BSI IT-Grundschutz) */
    private const DEFAULT_SOURCE_CODE = 'ISO27001';
    private const DEFAULT_TARGET_CODE = 'BSI_GRUNDSCHUTZ';

    /** Default official-provenance sentinel for the ISO→BSI pair */
    private const DEFAULT_OFFICIAL_PROVENANCE = IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT;

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
            )
            ->addOption(
                'source-framework',
                null,
                InputOption::VALUE_OPTIONAL,
                'Source framework code (default: ISO27001).',
                self::DEFAULT_SOURCE_CODE,
            )
            ->addOption(
                'target-framework',
                null,
                InputOption::VALUE_OPTIONAL,
                'Target framework code (default: BSI_GRUNDSCHUTZ).',
                self::DEFAULT_TARGET_CODE,
            )
            ->addOption(
                'official-provenance',
                null,
                InputOption::VALUE_OPTIONAL,
                'The provenanceSource sentinel that identifies official CRT rows (default: official_bsi_crosswalk).',
                self::DEFAULT_OFFICIAL_PROVENANCE,
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var string $sourceCode */
        $sourceCode = $input->getOption('source-framework') ?? self::DEFAULT_SOURCE_CODE;
        /** @var string $targetCode */
        $targetCode = $input->getOption('target-framework') ?? self::DEFAULT_TARGET_CODE;
        /** @var string $officialProvenance */
        $officialProvenance = $input->getOption('official-provenance') ?? self::DEFAULT_OFFICIAL_PROVENANCE;

        if ($dryRun) {
            $io->note('Dry-run mode — no database writes will be performed.');
        }

        $source = $this->frameworkRepository->findOneBy(['code' => $sourceCode]);
        $target = $this->frameworkRepository->findOneBy(['code' => $targetCode]);

        if ($source === null) {
            $io->error(sprintf(
                'Source framework (%s) not found. Run the relevant loader first.',
                $sourceCode
            ));
            return Command::FAILURE;
        }

        if ($target === null) {
            $io->error(sprintf(
                'Target framework (%s) not found. Run the relevant loader first.',
                $targetCode
            ));
            return Command::FAILURE;
        }

        $io->info(sprintf(
            'Corroborating %s → %s heuristic mappings against official CRT (provenance: %s)…',
            $sourceCode,
            $targetCode,
            $officialProvenance,
        ));

        $result = $this->corroborationService->corroborate($source, $target, $officialProvenance, dryRun: $dryRun);

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

        // ── Per-target-key breakdown ───────────────────────────────────────────
        if ($result['by_baustein'] !== []) {
            $rows = [];
            foreach ($result['by_baustein'] as $targetKey => $counts) {
                $rows[] = [
                    $targetKey,
                    $counts['corroborated'],
                    $counts['residual'],
                ];
            }
            $io->table(['Target requirement', 'Corroborated', 'Residual'], $rows);
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
            $io->table(['Mapping ID', 'Target requirement', 'Source requirement', 'Elevated?'], $detailRows);
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
