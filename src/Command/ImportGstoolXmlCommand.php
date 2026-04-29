<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TenantRepository;
use App\Service\Import\GstoolXmlImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-gstool-xml',
    description: 'Import GSTOOL XML export (Phase 1: Zielobjekte → Asset).',
)]
final class ImportGstoolXmlCommand extends Command
{
    public function __construct(
        private readonly GstoolXmlImporter $importer,
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'tenant',
                't',
                InputOption::VALUE_REQUIRED,
                'Tenant id (numeric) or slug to import into.',
            )
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Absolute path to the GSTOOL v1 XML export.',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Parse and report only — no DB writes.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenantArg = (string) $input->getOption('tenant');
        $file = (string) $input->getOption('file');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($tenantArg === '' || $file === '') {
            $io->error('Both --tenant and --file are required.');
            return Command::INVALID;
        }

        $tenant = is_numeric($tenantArg)
            ? $this->tenantRepository->find((int) $tenantArg)
            : $this->tenantRepository->findOneBy(['slug' => $tenantArg]);

        if ($tenant === null) {
            $io->error(sprintf('Tenant not found: %s', $tenantArg));
            return Command::FAILURE;
        }

        if (!is_file($file)) {
            $io->error(sprintf('File not found: %s', $file));
            return Command::FAILURE;
        }

        $io->section(sprintf('GSTOOL XML import → tenant "%s"', $tenant->getName()));

        if ($dryRun) {
            $result = $this->importer->analyse($file, $tenant);
        } else {
            $result = $this->importer->apply(
                path: $file,
                tenant: $tenant,
                user: null,
                originalFilename: basename($file),
            );
        }

        if ($result['header_error'] !== null) {
            $io->error($result['header_error']);
            return Command::FAILURE;
        }

        $rows = $result['rows'];
        $summary = $result['summary'];

        $io->table(
            ['Line', 'Action', 'GSTOOL ID', 'Name', 'Asset-Type', 'C', 'I', 'A', 'Error'],
            array_map(static fn (array $r) => [
                $r['line'] ?? '',
                $r['action'],
                $r['id'] ?? '',
                $r['name'] ?? '',
                $r['assetType'] ?? '',
                $r['confidentiality'] ?? '',
                $r['integrity'] ?? '',
                $r['availability'] ?? '',
                $r['error'] ?? '',
            ], $rows),
        );

        $label = $dryRun ? 'preview' : 'committed';
        $io->success(sprintf(
            '%s: new=%d, update=%d, error=%d%s',
            ucfirst($label),
            $summary['new'],
            $summary['update'],
            $summary['error'],
            $dryRun ? '' : sprintf(' (session #%s)', $result['session_id'] ?? '?'),
        ));

        return $summary['error'] > 0 && !$dryRun
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
