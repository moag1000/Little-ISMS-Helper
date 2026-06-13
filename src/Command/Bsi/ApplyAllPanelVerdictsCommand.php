<?php

declare(strict_types=1);

namespace App\Command\Bsi;

use App\Service\Bsi\PanelVerdictAutoApplier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Apply ALL build-time expert-panel verdicts to the ComplianceMapping rows.
 *
 * Thin idempotent wrapper over {@see PanelVerdictAutoApplier}. Discovers every
 * `fixtures/library/mappings/panel_verdicts/*_panel_v1.json`, resolves each
 * fixture's source + target framework, and applies the verdicts:
 *   - ki_validiert   → provenanceSource='panel', lifecycleState='approved'
 *                      (→ IsoToBsiGapService::trustOf() returns ki_validiert)
 *   - reject         → lifecycleState='deprecated' (drops out of coverage)
 *   - needs_review   → reviewStatus='needs_review', requiresReview=true
 *   - panel_discovered → new mapping created (if requirement rows exist)
 *
 * Fixtures whose frameworks/requirements are not yet loaded are skipped (logged),
 * never fatal. Re-running is safe (idempotent).
 *
 * This is the ops/re-run entrypoint; the same orchestrator runs automatically at
 * the end of `app:mapping:library:import` so a fresh install surfaces the grades
 * without an operator step.
 *
 * ## Usage
 *   php bin/console app:apply-all-panel-verdicts            # apply everything
 *   php bin/console app:apply-all-panel-verdicts --dry-run  # preview, no writes
 */
#[AsCommand(
    name: 'app:apply-all-panel-verdicts',
    description: 'Apply all 4-persona panel verdicts (*_panel_v1.json) to ComplianceMappings — idempotent, all framework pairs.',
)]
final class ApplyAllPanelVerdictsCommand extends Command
{
    public function __construct(
        private readonly PanelVerdictAutoApplier $autoApplier,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Compute and print per-fixture counts without writing any DB changes.',
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

        $summary = $this->autoApplier->applyAll($dryRun);

        $rows = [];
        foreach ($summary['per_fixture'] as $row) {
            $detail = $row['status'] === 'applied' && isset($row['counts'])
                ? sprintf(
                    'ki=%d rej=%d rev=%d new=%d (already=%d)',
                    $row['counts']['ki_validiert'],
                    $row['counts']['rejected'],
                    $row['counts']['needs_review'],
                    $row['counts']['panel_discovered'],
                    $row['counts']['already_applied'],
                )
                : ($row['reason'] ?? '');
            $rows[] = [basename($row['fixture']), $row['status'], $detail];
        }

        $io->table(['Fixture', 'Status', 'Detail'], $rows);

        $io->definitionList(
            ['Fixtures discovered' => $summary['fixtures_total']],
            ['Applied'             => $summary['applied']],
            ['Skipped'             => $summary['skipped']],
            ['ki_validiert'        => $summary['ki_validiert']],
            ['deprecated (reject)' => $summary['rejected']],
            ['needs_review'        => $summary['needs_review']],
            ['panel_discovered'    => $summary['panel_discovered']],
            ['already_applied'     => $summary['already_applied']],
        );

        if ($dryRun) {
            $io->note('Dry-run complete — no changes were persisted. Remove --dry-run to apply.');
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Panel verdicts applied across %d fixture(s) (%d skipped): '
            . '%d ki_validiert, %d deprecated, %d flagged for review, %d new mappings.',
            $summary['applied'],
            $summary['skipped'],
            $summary['ki_validiert'],
            $summary['rejected'],
            $summary['needs_review'],
            $summary['panel_discovered'],
        ));

        return Command::SUCCESS;
    }
}
