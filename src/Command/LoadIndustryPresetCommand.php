<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Service\Setup\IndustryPresetApplier;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Audit V3 C4 — Industry-Preset Loader.
 *
 * Loads pre-curated module-activation + framework-subset + risk-categories +
 * initial controls + initial documents from a preset YAML.
 *
 * Usage:
 *   php bin/console app:load-industry-preset --preset=de-mittelstand-nis2
 *   php bin/console app:load-industry-preset --preset=kritis-energie --tenant-id=42
 */
#[AsCommand(
    name: 'app:load-industry-preset',
    description: 'Apply a curated industry preset (modules + frameworks + initial controls/documents) to a tenant',
)]
class LoadIndustryPresetCommand
{
    private const PRESET_DIR = __DIR__ . '/../../fixtures/library/presets';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly IndustryPresetApplier $presetApplier,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(name: 'preset', description: 'Preset id (filename without .yaml)')] ?string $preset = null,
        #[Option(name: 'tenant-id', description: 'Target tenant id (defaults to current context)')] ?int $tenantId = null,
        #[Option(name: 'dry-run', description: 'Preview only')] bool $dryRun = false,
    ): int {
        if ($preset === null) {
            $io->writeln('Available presets:');
            foreach (glob(self::PRESET_DIR . '/*.yaml') ?: [] as $path) {
                $io->writeln('  - ' . basename($path, '.yaml'));
            }
            return Command::SUCCESS;
        }

        $path = self::PRESET_DIR . '/' . $preset . '.yaml';
        if (!is_file($path)) {
            $io->error(sprintf('Preset not found: %s', $path));
            return Command::FAILURE;
        }

        $config = Yaml::parseFile($path);
        $io->title('Industry Preset: ' . ($config['name'] ?? $preset));
        $io->section('Description');
        $io->writeln(trim($config['description'] ?? ''));

        $tenant = null;
        if ($tenantId !== null) {
            $tenant = $this->entityManager->getRepository(Tenant::class)->find($tenantId);
        } else {
            $tenant = $this->tenantContext->getCurrentTenant();
        }

        if (!$tenant instanceof Tenant) {
            $io->warning('No tenant resolved — controls/documents will be skipped. Pass --tenant-id=N to apply.');
        }

        $io->section('Modules to enable');
        foreach ($config['modules'] ?? [] as $module) {
            $io->writeln('  - ' . $module);
        }

        $io->section('Frameworks');
        foreach ($config['frameworks'] ?? [] as $framework) {
            $io->writeln(sprintf(
                '  - %s (%s, %s)',
                $framework['code'] ?? '?',
                $framework['priority'] ?? '?',
                ($framework['activate'] ?? false) ? 'activate' : 'optional',
            ));
        }

        $io->section('Risk categories');
        foreach ($config['risk_categories'] ?? [] as $rc) {
            $io->writeln('  - ' . ($rc['name'] ?? '?'));
        }

        $result = ['modules_activated' => [], 'frameworks_activated' => 0, 'controls_created' => 0, 'documents_created' => 0];
        if (!$dryRun) {
            $result = $this->presetApplier->apply($preset, $config, $tenant instanceof Tenant ? $tenant : null);
        }
        $modulesActivated = $result['modules_activated'];
        $frameworksActivated = $result['frameworks_activated'];
        $controlsCreated = $result['controls_created'];
        $documentsCreated = $result['documents_created'];

        $io->success(sprintf(
            'Preset "%s" %s. Activated %d modules, %d frameworks. Created %d controls, %d documents.',
            $preset,
            $dryRun ? 'previewed' : 'applied',
            count($modulesActivated),
            $frameworksActivated,
            $controlsCreated,
            $documentsCreated,
        ));

        if (!$dryRun && count($modulesActivated) > 0) {
            $io->note('Module list updated in config/active_modules.yaml — run
                "php bin/console cache:clear" if running in production.');
        }

        return Command::SUCCESS;
    }
}
