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
 * WS-5b Stage 2 — Apply 4-persona expert panel verdicts to residual heuristic ISO↔BSI mappings.
 *
 * ## What it does
 * Loads the panel verdict fixture (144 entries: 133 ki_validiert, 2 reject, 9 needs_review)
 * and applies each verdict to the matching ISO 27001 → BSI IT-Grundschutz ComplianceMapping:
 *
 *   ki_validiert  → provenanceSource='panel', lifecycleState='approved',
 *                   analysisConfidence (4 votes→90, 3→70, ≤2→50),
 *                   mappingPercentage = verdict value.
 *                   IsoToBsiGapService::trustOf() returns ki_validiert.
 *
 *   reject        → lifecycleState='deprecated' (NOT hard-deleted — audit trail preserved).
 *                   The mapping drops out of OPERATIONAL_STATES and no longer counts as coverage.
 *
 *   needs_review  → reviewStatus='needs_review', requiresReview=true.
 *                   Stays visible but in the inheritance review queue.
 *
 * ## Idempotency
 * Re-running is safe. Rows already in the target state are counted as `already_applied`
 * and not re-written.
 *
 * ## Usage
 *   php bin/console app:bsi:apply-panel-verdicts             # apply all verdicts
 *   php bin/console app:bsi:apply-panel-verdicts --dry-run   # preview, no writes
 */
#[AsCommand(
    name: 'app:bsi:apply-panel-verdicts',
    description: 'WS-5b stage 2 — Apply 4-persona panel verdicts to residual heuristic ISO↔BSI mappings (133 validated, 2 deprecated, 9 to review)'
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

        $io->info('Loading panel verdict fixture…');

        try {
            $counts = $this->verdictApplier->apply($iso, $bsi, $dryRun);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // ── Summary table ──────────────────────────────────────────────────────
        $io->table(
            ['Verdict', 'Count'],
            [
                ['ki_validiert  (elevated to panel/approved)',  $counts['ki_validiert']],
                ['reject        (deprecated, audit-safe)',       $counts['rejected']],
                ['needs_review  (flagged for human review)',     $counts['needs_review']],
                ['already_applied (idempotent — no-op)',         $counts['already_applied']],
                ['not_matched   (no DB row found)',              $counts['not_matched']],
                ['Total verdicts in fixture',                    $counts['total']],
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
            . '%d flagged for review.',
            $counts['ki_validiert'],
            $counts['rejected'],
            $counts['needs_review'],
        ));

        return Command::SUCCESS;
    }
}
