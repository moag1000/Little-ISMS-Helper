<?php

declare(strict_types=1);

namespace App\Command\Bsi;

use App\Repository\ComplianceFrameworkRepository;
use App\Service\Bsi\PanelVerdictApplier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * WS-5b Stage 2 — Apply 4-persona expert panel verdicts to residual heuristic ComplianceMappings.
 *
 * ## Defaults (ISO 27001 → BSI IT-Grundschutz)
 * Loads the ISO panel verdict fixture (144 entries: 133 ki_validiert, 2 reject, 9 needs_review)
 * and applies each verdict to the matching ISO 27001 → BSI IT-Grundschutz ComplianceMapping.
 *
 * ## NIS2 → BSI IT-Grundschutz (Task 4 / WS NIS2)
 * Use --fixture and --source-framework / --target-framework to apply the NIS2 panel verdicts:
 *   php bin/console app:bsi:apply-panel-verdicts \
 *     --fixture=fixtures/library/mappings/panel_verdicts/nis2-art21_to_bsi-grundschutz_panel_v1.json \
 *     --source-framework=NIS2 \
 *     --target-framework=BSI_GRUNDSCHUTZ
 *
 * ## Verdict semantics
 *   ki_validiert  → provenanceSource='panel', lifecycleState='approved', reviewStatus='approved',
 *                   analysisConfidence (4 votes→90, 3→70, ≤2→60),
 *                   mappingPercentage = verdict value.
 *                   IsoToBsiGapService::trustOf() returns ki_validiert.
 *
 *   reject        → lifecycleState='deprecated' (NOT hard-deleted — audit trail preserved).
 *                   The mapping drops out of OPERATIONAL_STATES and no longer counts as coverage.
 *
 *   needs_review  → reviewStatus='needs_review', requiresReview=true.
 *                   Stays visible but in the inheritance review queue.
 *
 *   panel_discovered → creates a new ComplianceMapping if no existing row matches.
 *                      Skipped (with log) if source or target requirement row not found in DB.
 *
 * ## Idempotency
 * Re-running is safe. Rows already in the target state are counted as `already_applied`
 * and not re-written.
 *
 * ## Usage
 *   php bin/console app:bsi:apply-panel-verdicts                                 # ISO defaults
 *   php bin/console app:bsi:apply-panel-verdicts --dry-run                       # preview, no writes
 *   php bin/console app:bsi:apply-panel-verdicts --fixture=... --source-framework=NIS2 --target-framework=BSI_GRUNDSCHUTZ
 */
#[AsCommand(
    name: 'app:bsi:apply-panel-verdicts',
    description: 'WS-5b stage 2 — Apply 4-persona panel verdicts to heuristic ComplianceMappings (ISO default; parametrized via --fixture/--source-framework/--target-framework)'
)]
final class ApplyPanelVerdictsCommand extends Command
{
    private const BSI_FRAMEWORK_CODE = 'BSI_GRUNDSCHUTZ';
    private const ISO_FRAMEWORK_CODE = 'ISO27001';

    public function __construct(
        private readonly PanelVerdictApplier $verdictApplier,
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
                'fixture',
                null,
                InputOption::VALUE_OPTIONAL,
                'Relative path from project root to the panel verdict fixture JSON.',
                PanelVerdictApplier::FIXTURE_PATH,
            )
            ->addOption(
                'source-framework',
                null,
                InputOption::VALUE_OPTIONAL,
                'Framework code for the source framework (default: ISO27001).',
                self::ISO_FRAMEWORK_CODE,
            )
            ->addOption(
                'target-framework',
                null,
                InputOption::VALUE_OPTIONAL,
                'Framework code for the target framework (default: BSI_GRUNDSCHUTZ).',
                self::BSI_FRAMEWORK_CODE,
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var string $fixturePath */
        $fixturePath = $input->getOption('fixture') ?? PanelVerdictApplier::FIXTURE_PATH;
        /** @var string $sourceCode */
        $sourceCode = $input->getOption('source-framework') ?? self::ISO_FRAMEWORK_CODE;
        /** @var string $targetCode */
        $targetCode = $input->getOption('target-framework') ?? self::BSI_FRAMEWORK_CODE;

        if ($dryRun) {
            $io->note('Dry-run mode — no database writes will be performed.');
        }

        $io->info(sprintf(
            'Fixture: %s | Source: %s | Target: %s',
            $fixturePath,
            $sourceCode,
            $targetCode,
        ));

        $source = $this->frameworkRepository->findOneBy(['code' => $sourceCode]);
        $target = $this->frameworkRepository->findOneBy(['code' => $targetCode]);

        if ($source === null) {
            $io->error(sprintf(
                'Source framework (%s) not found. Run the relevant loader first.',
                $sourceCode,
            ));
            return Command::FAILURE;
        }

        if ($target === null) {
            $io->error(sprintf(
                'Target framework (%s) not found. Run the relevant loader first.',
                $targetCode,
            ));
            return Command::FAILURE;
        }

        $io->info('Loading panel verdict fixture…');

        try {
            $counts = $this->verdictApplier->apply($fixturePath, $source, $target, $dryRun);
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // ── Summary table ──────────────────────────────────────────────────────
        $io->table(
            ['Verdict', 'Count'],
            [
                ['ki_validiert         (elevated to panel/approved)',   $counts['ki_validiert']],
                ['reject               (deprecated, audit-safe)',        $counts['rejected']],
                ['needs_review         (flagged for human review)',      $counts['needs_review']],
                ['panel_discovered     (new mapping created)',           $counts['panel_discovered']],
                ['panel_discovered_skipped (req row not found — skip)', $counts['panel_discovered_skipped']],
                ['already_applied      (idempotent — no-op)',            $counts['already_applied']],
                ['not_matched          (no DB row found)',               $counts['not_matched']],
                ['Total verdicts in fixture',                            $counts['total']],
            ]
        );

        if ($dryRun) {
            $io->note('Dry-run complete — no changes were persisted. Remove --dry-run to apply.');
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Panel verdict application complete. '
            . '%d mapping(s) elevated to ki_validiert; '
            . '%d deprecated; '
            . '%d flagged for review; '
            . '%d new panel_discovered mapping(s) created.',
            $counts['ki_validiert'],
            $counts['rejected'],
            $counts['needs_review'],
            $counts['panel_discovered'],
        ));

        return Command::SUCCESS;
    }
}
