<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TenantRepository;
use App\Service\AssetSubTypeSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * S18 B2 — Seed industry-preset AssetSubType rows for a tenant.
 *
 * Usage:
 *   php bin/console app:seed-asset-sub-types --preset=bsi-grundschutz --tenant-id=1
 *   php bin/console app:seed-asset-sub-types --preset=tisax --tenant-id=1
 *   php bin/console app:seed-asset-sub-types --preset=production-de-mittelstand --tenant-id=1
 *
 * Idempotent (uniq constraint tenant_id + top_type + name) — existing rows are
 * skipped and reported.
 */
#[AsCommand(
    name: 'app:seed-asset-sub-types',
    description: 'Seeds AssetSubType rows from an industry preset (bsi-grundschutz | tisax | production-de-mittelstand).',
)]
final class SeedAssetSubTypesCommand extends Command
{
    public function __construct(
        private readonly AssetSubTypeSeeder $seeder,
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'preset',
                null,
                InputOption::VALUE_REQUIRED,
                'Preset to seed: ' . implode(' | ', AssetSubTypeSeeder::PRESETS),
            )
            ->addOption(
                'tenant-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Tenant ID to seed for (required).',
            )
            ->addOption(
                'list',
                null,
                InputOption::VALUE_NONE,
                'List available presets and exit.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('list')) {
            $io->title('Available AssetSubType presets');
            $io->listing($this->seeder->availablePresets());
            return Command::SUCCESS;
        }

        $preset = (string) ($input->getOption('preset') ?? '');
        $tenantIdOpt = $input->getOption('tenant-id');

        if ($preset === '' || $tenantIdOpt === null) {
            $io->error('--preset and --tenant-id are required. Use --list to see available presets.');
            return Command::INVALID;
        }

        if (!in_array($preset, AssetSubTypeSeeder::PRESETS, true)) {
            $io->error(sprintf(
                'Unknown preset "%s". Available: %s',
                $preset,
                implode(', ', AssetSubTypeSeeder::PRESETS),
            ));
            return Command::INVALID;
        }

        $tenantId = (int) $tenantIdOpt;
        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            $io->error(sprintf('Tenant id=%d not found.', $tenantId));
            return Command::FAILURE;
        }

        $io->title(sprintf('Seeding "%s" preset for tenant #%d (%s)', $preset, $tenantId, $tenant->getName()));

        $result = $this->seeder->applyPreset($tenant, $preset);

        $io->success(sprintf(
            'Preset "%s" applied: %d created, %d skipped (already present), %d total entries.',
            $result['preset'],
            $result['created'],
            $result['skipped'],
            $result['total'],
        ));

        return Command::SUCCESS;
    }
}
