<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\FrameworkVersionMigrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migrate mappings from an old framework version to its successor.
 *
 * Examples:
 *   # Explicit version pair
 *   php bin/console app:migrate-framework-version \
 *        --from=ISO27001-2013 --to=ISO27001 --strategy=id
 *
 *   # Use the ComplianceFramework.successor relation (M-04)
 *   php bin/console app:migrate-framework-version --from=ISO27001-2013
 *
 *   # Dry-run shows matched/unmatched without touching the DB
 *   php bin/console app:migrate-framework-version \
 *        --from=BSI-C5 --to=BSI-C5-2026 --dry-run
 */
#[AsCommand(
    name: 'app:migrate-framework-version',
    description: 'Bridge mappings from an old framework version to its successor (Sprint 2 / B6).'
)]
class MigrateFrameworkVersionCommand extends Command
{
    public function __construct(
        private readonly FrameworkVersionMigrator $migrator,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', 'f', InputOption::VALUE_REQUIRED, 'Old framework code (e.g. ISO27001-2013)')
            ->addOption('to', 't', InputOption::VALUE_REQUIRED, 'New framework code (defaults to old.successor).')
            ->addOption(
                'strategy',
                's',
                InputOption::VALUE_REQUIRED,
                'Matching strategy: id | title | both (default id).',
                FrameworkVersionMigrator::MATCH_STRATEGY_ID
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report matches without writing bridge mappings.')
            ->addOption('list-unmatched', null, InputOption::VALUE_NONE, 'Print the full list of requirements with no successor (cropped to 50 by default).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fromCode = (string) $input->getOption('from');
        if ($fromCode === '') {
            $io->error('--from is required.');
            return Command::FAILURE;
        }
        $old = $this->frameworkRepository->findOneBy(['code' => $fromCode]);
        if (!$old instanceof ComplianceFramework) {
            $io->error(sprintf('Source framework %s not loaded.', $fromCode));
            return Command::FAILURE;
        }

        $toCode = (string) $input->getOption('to');
        if ($toCode === '') {
            $successor = $old->getSuccessor();
            if (!$successor instanceof ComplianceFramework) {
                $io->error(sprintf(
                    'No --to supplied and framework %s has no successor relation. Set one via the admin UI or pass --to=<code>.',
                    $fromCode
                ));
                return Command::FAILURE;
            }
            $new = $successor;
        } else {
            $new = $this->frameworkRepository->findOneBy(['code' => $toCode]);
            if (!$new instanceof ComplianceFramework) {
                $io->error(sprintf('Target framework %s not loaded.', $toCode));
                return Command::FAILURE;
            }
        }

        if ($old->id === $new->id) {
            $io->error('Source and target framework are identical — nothing to migrate.');
            return Command::FAILURE;
        }

        $strategy = (string) $input->getOption('strategy');
        if (!in_array($strategy, [
            FrameworkVersionMigrator::MATCH_STRATEGY_ID,
            FrameworkVersionMigrator::MATCH_STRATEGY_TITLE,
            FrameworkVersionMigrator::MATCH_STRATEGY_BOTH,
        ], true)) {
            $io->error(sprintf('Unknown strategy "%s". Use id | title | both.', $strategy));
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        $io->info(sprintf(
            'Migrating %s → %s (strategy=%s, dry-run=%s)',
            $old->getCode() ?? '',
            $new->getCode() ?? '',
            $strategy,
            $dryRun ? 'yes' : 'no'
        ));

        $result = $this->migrator->migrate($old, $new, $strategy, persist: !$dryRun);

        $io->table(
            ['Matched', 'Bridges created', 'Bridges skipped (existing)', 'Unmatched'],
            [[
                count($result['matched']),
                $result['bridges_created'],
                $result['bridges_skipped_existing'],
                count($result['unmatched']),
            ]]
        );

        if (count($result['unmatched']) > 0) {
            $limit = (bool) $input->getOption('list-unmatched') ? count($result['unmatched']) : min(20, count($result['unmatched']));
            $io->section(sprintf(
                '%d Requirement(s) ohne Nachfolger in %s:',
                count($result['unmatched']),
                $new->getCode() ?? ''
            ));
            foreach (array_slice($result['unmatched'], 0, $limit) as $row) {
                $io->text(sprintf(' - %s — %s', $row['source_id'], $row['source_title']));
            }
            if ($limit < count($result['unmatched'])) {
                $io->text(sprintf(' … %d weitere ausgeblendet (--list-unmatched für Vollansicht)', count($result['unmatched']) - $limit));
            }
        }

        $io->success(sprintf(
            '%s complete. %d bridge mapping(s) %s.',
            $dryRun ? 'Dry-run' : 'Migration',
            $result['bridges_created'],
            $dryRun ? 'would be created' : 'created'
        ));

        return Command::SUCCESS;
    }
}
