<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\Import\DoraRegisterOfInformationImporter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import a DORA Register of Information CSV (ITS format).
 *
 * Usage:
 *   php bin/console app:import-dora-register path/to/roi.csv --tenant=<id>
 *   php bin/console app:import-dora-register path/to/roi.csv --tenant=<id> --dry-run
 */
#[AsCommand(
    name: 'app:import-dora-register',
    description: 'Import a DORA Register of Information CSV (ITS format) and upsert Supplier rows by LEI.'
)]
class ImportDoraRegisterCommand extends Command
{
    public function __construct(
        private readonly DoraRegisterOfInformationImporter $importer,
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the ITS-conformant CSV file.')
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Target tenant ID.')
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

        $tenantId = (int) $input->getOption('tenant');
        if ($tenantId <= 0) {
            $io->error('--tenant=<id> is required.');
            return Command::FAILURE;
        }
        $tenant = $this->tenantRepository->find($tenantId);
        if (!$tenant instanceof Tenant) {
            $io->error(sprintf('Tenant #%d not found.', $tenantId));
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');

        $csv = (string) file_get_contents($file);
        if ($csv === '') {
            $io->warning('File is empty.');
            return Command::SUCCESS;
        }

        $io->info(sprintf(
            'Importing DORA Register of Information into tenant #%d (%s)%s',
            $tenant->getId(),
            $tenant->getName(),
            $dryRun ? ' — dry run' : ''
        ));

        $result = $this->importer->import($csv, $tenant, persist: !$dryRun);

        $io->table(
            ['Processed', 'Created', 'Updated', 'Skipped', 'Errors'],
            [[
                $result['processed'],
                $result['created'],
                $result['updated'],
                $result['skipped'],
                count($result['errors']),
            ]]
        );

        if ($result['errors'] !== []) {
            $io->warning(sprintf('%d error(s) encountered:', count($result['errors'])));
            foreach ($result['errors'] as $err) {
                $io->text(sprintf(' - row %d (LEI=%s): %s', $err['row'], $err['lei'] ?? 'n/a', $err['message']));
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
            $io->success('Import applied.');
        } else {
            $io->success('Dry-run complete. No database writes.');
        }

        return Command::SUCCESS;
    }
}
