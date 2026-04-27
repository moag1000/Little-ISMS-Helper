<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\SampleDataImportRepository;
use App\Repository\TenantRepository;
use App\Service\ModuleConfigurationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Debug-helper: dumps exactly what the SampleDataController index page
 * computes per row (modules_ok, count from tracking-rows, imported flag).
 * Drop-in to confirm whether the badge logic is reading the right data.
 */
#[AsCommand(
    name: 'app:debug:sample-data-status',
    description: 'Print sample-data status table as the admin index controller would compute it.',
    hidden: true,
)]
final class DebugSampleDataStatusCommand
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly SampleDataImportRepository $sampleImportRepository,
    ) {
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenant = $this->tenantRepository->findOneBy(['code' => 'default'])
            ?? $this->tenantRepository->findOneBy([]);
        if ($tenant === null) {
            $io->error('No tenant in DB.');
            return Command::FAILURE;
        }
        $io->writeln(sprintf('<info>Tenant: %s (id=%d)</info>', $tenant->getName(), $tenant->getId()));

        $samples = $this->moduleConfigurationService->getSampleData();
        $active  = $this->moduleConfigurationService->getActiveModules();
        $counts  = $this->sampleImportRepository->countsByKey($tenant);

        $io->writeln('Active modules: ' . implode(',', $active));
        $io->writeln('countsByKey raw keys: ' . implode(',', array_map(static fn($k) => var_export($k, true), array_keys($counts))));
        $io->writeln('');

        $rows = [];
        foreach ($samples as $key => $data) {
            $required = $data['required_modules'] ?? [];
            $modulesOk = empty(array_diff($required, $active));
            $cnt = $counts[$key] ?? 0;
            $rows[] = [
                'idx' => sprintf('%s (%s)', var_export($key, true), gettype($key)),
                'name' => $data['name'] ?? '?',
                'kind' => isset($data['command']) ? 'cmd' : 'file',
                'modules_ok' => $modulesOk ? 'yes' : 'no',
                'count' => $cnt,
                'imported' => $cnt > 0 ? 'YES' : 'no',
            ];
        }
        $io->table(['idx (type)', 'name', 'kind', 'modules_ok', 'count', 'imported'], $rows);

        return Command::SUCCESS;
    }
}
