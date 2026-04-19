<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\CrossFrameworkMappingImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import a consultant-delivered cross-framework mapping table.
 *
 * Usage:
 *   php bin/console app:import-cross-framework-mappings <file.csv> \
 *        --source=ISO27001 --target=NIS2UMSUCG
 *   php bin/console app:import-cross-framework-mappings <file.csv> \
 *        --source=ISO27001 --target=NIS2UMSUCG --dry-run
 *
 * CSV contract documented on `CrossFrameworkMappingImporter`. All
 * mapping rows are recorded with `verified_by = 'consultant_template_
 * import'` unless `--verified-by=<handle>` is supplied.
 */
#[AsCommand(
    name: 'app:import-cross-framework-mappings',
    description: 'Import a consultant CSV mapping table between two compliance frameworks.'
)]
class ImportCrossFrameworkMappingsCommand extends Command
{
    public function __construct(
        private readonly CrossFrameworkMappingImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV file.')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED, 'Source framework code (e.g. ISO27001).')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Target framework code (e.g. NIS2UMSUCG).')
            ->addOption('verified-by', null, InputOption::VALUE_REQUIRED, 'Optional label for audit trail.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and report only — no database writes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = (string) $input->getArgument('file');
        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('CSV file not readable: %s', $file));
            return Command::FAILURE;
        }

        $source = (string) $input->getOption('source');
        $target = (string) $input->getOption('target');
        if ($source === '' || $target === '') {
            $io->error('Both --source and --target framework codes are required.');
            return Command::FAILURE;
        }
        if ($source === $target) {
            $io->error('Source and target frameworks must differ.');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $verifiedBy = $input->getOption('verified-by');

        $csv = (string) file_get_contents($file);
        if ($csv === '') {
            $io->warning('File is empty.');
            return Command::SUCCESS;
        }

        $io->info(sprintf(
            'Importing %s → %s (dry-run=%s) from %s',
            $source,
            $target,
            $dryRun ? 'yes' : 'no',
            $file
        ));

        $result = $this->importer->import(
            $csv,
            $source,
            $target,
            persist: !$dryRun,
            verifiedBy: is_string($verifiedBy) ? $verifiedBy : null,
        );

        $io->table(
            ['Processed', 'Created', 'Skipped (existing)', 'Skipped (missing)', 'Warnings', 'Errors'],
            [[
                $result['processed'],
                $result['created'],
                $result['skipped_existing'],
                $result['skipped_missing'],
                count($result['warnings']),
                count($result['errors']),
            ]]
        );

        if ($result['errors'] !== []) {
            $io->error(sprintf('%d error(s):', count($result['errors'])));
            foreach ($result['errors'] as $err) {
                $io->text(sprintf(' - row %d: %s', $err['row'], $err['message']));
            }
            return Command::FAILURE;
        }

        if ($result['warnings'] !== []) {
            $io->warning(sprintf('%d warning(s):', count($result['warnings'])));
            foreach (array_slice($result['warnings'], 0, 20) as $w) {
                $io->text(sprintf(' - row %d (%s → %s): %s', $w['row'], $w['source'], $w['target'], $w['reason']));
            }
            if (count($result['warnings']) > 20) {
                $io->text(sprintf(' … and %d more', count($result['warnings']) - 20));
            }
        }

        $io->success(sprintf(
            '%s complete. %d mapping(s) %s.',
            $dryRun ? 'Dry-run' : 'Import',
            $result['created'],
            $dryRun ? 'would be created' : 'created'
        ));
        return Command::SUCCESS;
    }
}
