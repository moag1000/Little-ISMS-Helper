<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\TenantContext;
use App\Template\SystemTemplate;
use App\Template\SystemTemplateApplier;
use App\Template\SystemTemplateRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `app:seed-templates` — Foundation P-14 CLI applier.
 *
 * Filters the SystemTemplateRegistry by key pattern and applies matching
 * templates to a target tenant. Supports `--dry-run` to inspect what would
 * happen without writing anything.
 *
 * Usage:
 *   app:seed-templates --registry='vvt.*' --tenant=acme --language=de
 *   app:seed-templates --registry='risk.iso27005.catalog.*' --tenant-id=42
 *   app:seed-templates --registry='*' --dry-run
 */
#[AsCommand(
    name: 'app:seed-templates',
    description: 'Apply SystemTemplates (Foundation P-14) to a tenant, optionally filtered by key pattern.',
)]
final class SeedTemplatesCommand extends Command
{
    public function __construct(
        private readonly SystemTemplateRegistry $registry,
        private readonly SystemTemplateApplier $applier,
        private readonly TenantContext $tenantContext,
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('registry', 'r', InputOption::VALUE_REQUIRED,
                'Glob-style key filter (e.g. `vvt.*`, `risk.bsi.*`). Default: all.',
                '*')
            ->addOption('tenant', 't', InputOption::VALUE_REQUIRED,
                'Tenant slug / name to apply templates to.')
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED,
                'Tenant ID (alternative to --tenant).')
            ->addOption('language', 'l', InputOption::VALUE_REQUIRED,
                'Filter by template language (`de` or `en`). Default: de.',
                'de')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,
                'Preview only — do not persist any entities.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pattern = (string) $input->getOption('registry');
        $language = (string) $input->getOption('language');
        $dryRun = (bool) $input->getOption('dry-run');

        $tenant = $this->resolveTenant($input, $io);
        if ($tenant === null && !$dryRun) {
            return Command::FAILURE;
        }
        if ($tenant !== null) {
            $this->tenantContext->setCurrentTenant($tenant);
        }

        $matches = $this->registry->filter(
            fn (SystemTemplate $t): bool =>
                $t->language === $language
                && $this->matches($pattern, $t->key),
        );

        if ($matches === []) {
            $io->warning(sprintf('No templates match pattern "%s" for language "%s".', $pattern, $language));
            return Command::SUCCESS;
        }

        $io->title(sprintf('Seeding %d template(s) [%s, lang=%s]', count($matches), $dryRun ? 'dry-run' : 'apply', $language));

        $rows = [];
        $totalRecords = 0;
        foreach ($matches as $template) {
            try {
                $entities = $this->applier->apply($template, dryRun: $dryRun);
                $info = $this->applier->lastResult();
                $count = $info['records'] ?? count($entities);
                $totalRecords += $count;
                $rows[] = [
                    $template->key,
                    $this->shortenClass($template->entityClass),
                    $template->module ?? '—',
                    $count,
                    $info['profile_applied'] ?? '',
                ];
            } catch (\Throwable $e) {
                $rows[] = [$template->key, 'ERROR', $template->module ?? '—', 0, $e->getMessage()];
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->table(['Key', 'Entity', 'Module', 'Records', 'Note'], $rows);
        $io->success(sprintf(
            '%s — applied %d template(s), %d entity record(s)%s.',
            $dryRun ? 'DRY-RUN' : 'DONE',
            count($matches),
            $totalRecords,
            $dryRun ? ' (nothing persisted)' : '',
        ));

        return Command::SUCCESS;
    }

    private function resolveTenant(InputInterface $input, SymfonyStyle $io): ?Tenant
    {
        $tenantId = $input->getOption('tenant-id');
        $tenantName = $input->getOption('tenant');

        if ($tenantId !== null) {
            $tenant = $this->tenantRepository->find((int) $tenantId);
            if (!$tenant instanceof Tenant) {
                $io->error(sprintf('Tenant with ID %s not found.', $tenantId));
                return null;
            }
            return $tenant;
        }

        if ($tenantName === null) {
            // dry-run is allowed without tenant; otherwise warn
            return null;
        }

        $tenant = $this->tenantRepository->findOneBy(['name' => $tenantName]);

        if (!$tenant instanceof Tenant) {
            $io->error(sprintf('Tenant "%s" not found (looked up by name + slug).', $tenantName));
            return null;
        }

        return $tenant;
    }

    private function matches(string $pattern, string $key): bool
    {
        if ($pattern === '' || $pattern === '*') {
            return true;
        }

        // Translate simple glob to regex: * → .*
        $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/i';

        return (bool) preg_match($regex, $key);
    }

    private function shortenClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }
}
