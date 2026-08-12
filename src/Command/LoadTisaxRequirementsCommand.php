<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Compliance\FrameworkLoaderInterface;
use App\Service\Tisax\TisaxCatalogueProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads a canonical VDA-ISA control-number catalogue (numbers only) as the
 * shared TISAX catalogue baseline: VDA-ISA 6.0 (80 controls, default) or
 * VDA-ISA 2027 (78 controls, --catalogue-version=2027). Both are currently
 * certifiable and are kept as separate frameworks.
 *
 * Thin wrapper around {@see TisaxCatalogueProvider} — the ONE place that owns the
 * TISAX framework row + catalogue. This command, the BYO import mapper and the
 * admin library importer all delegate to that provider, so there is exactly one
 * importer and one metadata source (the YAML).
 */
#[AsCommand(
    name: 'app:load-tisax-requirements',
    description: 'Load a VDA-ISA control-number catalogue (numbers only): 6.0 (default) or 2027'
)]
class LoadTisaxRequirementsCommand extends Command implements FrameworkLoaderInterface
{
    public function __construct(private readonly TisaxCatalogueProvider $catalogue)
    {
        parent::__construct();
    }

    public function getFrameworkCode(): string
    {
        return 'TISAX';
    }

    public function loadRequirements(
        bool $update = false,
        ?SymfonyStyle $io = null,
        string $version = TisaxCatalogueProvider::VERSION_ISA6,
    ): int {
        $version = TisaxCatalogueProvider::normaliseVersion($version);
        $r = $this->catalogue->loadCatalogue($update, $version);
        $io?->success(sprintf(
            'TISAX %s catalogue (numbers only): %d created, %d updated, %d skipped (of %d).',
            $version === TisaxCatalogueProvider::VERSION_ISA2027 ? 'ISA 2027' : 'ISA 6.0',
            $r['created'],
            $r['updated'],
            $r['skipped'],
            $r['total'],
        ));
        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('update', 'u', InputOption::VALUE_NONE, 'Update existing requirements instead of skipping them');
        $this->addOption(
            'catalogue-version',
            null,
            InputOption::VALUE_REQUIRED,
            sprintf('VDA-ISA catalogue to load: %s', implode(' | ', TisaxCatalogueProvider::availableVersions())),
            TisaxCatalogueProvider::VERSION_ISA6,
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->loadRequirements(
            (bool) $input->getOption('update'),
            new SymfonyStyle($input, $output),
            (string) $input->getOption('catalogue-version'),
        );
    }
}
