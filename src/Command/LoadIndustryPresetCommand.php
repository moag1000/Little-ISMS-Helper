<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Tenant;
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

        // Modules
        $io->section('Modules to enable');
        foreach ($config['modules'] ?? [] as $module) {
            $io->writeln('  - ' . $module);
        }

        // Frameworks
        $io->section('Frameworks');
        foreach ($config['frameworks'] ?? [] as $framework) {
            $io->writeln(sprintf(
                '  - %s (%s, %s)',
                $framework['code'] ?? '?',
                $framework['priority'] ?? '?',
                ($framework['activate'] ?? false) ? 'activate' : 'optional',
            ));
        }

        // Risk categories
        $io->section('Risk categories');
        foreach ($config['risk_categories'] ?? [] as $rc) {
            $io->writeln('  - ' . ($rc['name'] ?? '?'));
        }

        // Initial controls
        $controlsCreated = 0;
        if ($tenant && !$dryRun) {
            $controlRepo = $this->entityManager->getRepository(Control::class);
            foreach ($config['initial_controls'] ?? [] as $cdef) {
                $existing = $controlRepo->findOneBy([
                    'tenant' => $tenant,
                    'controlId' => $cdef['identifier'] ?? null,
                ]);
                if ($existing instanceof Control) {
                    continue;
                }
                $control = new Control();
                if (method_exists($control, 'setTenant')) {
                    $control->setTenant($tenant);
                }
                if (method_exists($control, 'setControlId')) {
                    $control->setControlId((string) ($cdef['identifier'] ?? ''));
                }
                if (method_exists($control, 'setName')) {
                    $control->setName((string) ($cdef['name'] ?? ''));
                }
                if (method_exists($control, 'setImplementationStatus')) {
                    $control->setImplementationStatus((string) ($cdef['implementation_status'] ?? 'not_started'));
                }
                if (method_exists($control, 'setApplicable')) {
                    $control->setApplicable(true);
                }
                $this->entityManager->persist($control);
                $controlsCreated++;
            }
        }

        // Initial documents
        $documentsCreated = 0;
        if ($tenant && !$dryRun) {
            $docRepo = $this->entityManager->getRepository(Document::class);
            foreach ($config['initial_documents'] ?? [] as $ddef) {
                $existing = $docRepo->findOneBy([
                    'tenant' => $tenant,
                    'title' => $ddef['title'] ?? null,
                ]);
                if ($existing instanceof Document) {
                    continue;
                }
                $doc = new Document();
                if (method_exists($doc, 'setTenant')) {
                    $doc->setTenant($tenant);
                }
                if (method_exists($doc, 'setTitle')) {
                    $doc->setTitle((string) ($ddef['title'] ?? ''));
                }
                if (method_exists($doc, 'setDocumentType')) {
                    $doc->setDocumentType((string) ($ddef['type'] ?? 'policy'));
                }
                if (method_exists($doc, 'setStatus')) {
                    $doc->setStatus((string) ($ddef['status'] ?? 'draft'));
                }
                $this->entityManager->persist($doc);
                $documentsCreated++;
            }
        }

        if (!$dryRun && ($controlsCreated > 0 || $documentsCreated > 0)) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            'Preset "%s" %s. Created %d controls, %d documents.',
            $preset,
            $dryRun ? 'previewed' : 'applied',
            $controlsCreated,
            $documentsCreated,
        ));

        $io->note('Module activation and framework activation are surfaced in the
            setup wizard / module-management UI. This command persists the
            initial control/document skeletons only; admins must explicitly
            enable modules in active_modules.yaml + activate frameworks via
            the Compliance-Wizard.');

        return Command::SUCCESS;
    }
}
