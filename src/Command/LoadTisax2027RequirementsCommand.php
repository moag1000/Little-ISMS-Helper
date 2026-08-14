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
 * Loads the VDA-ISA 2027 control-number catalogue (78 controls, numbers only).
 *
 * Separate from {@see LoadTisaxRequirementsCommand} on purpose: FrameworkLoaderRegistry
 * resolves exactly one loader per framework code, and ISA 2027 is its own framework
 * ('TISAX-2027') because ENX certifies against ISA 6 and ISA 2027 in parallel. A single
 * loader with a version switch would seed two different frameworks under one code and
 * stay invisible to the registry — the generic framework-loading paths would never find
 * the 2027 catalogue.
 *
 * Both commands are thin wrappers around {@see TisaxCatalogueProvider}, which owns the
 * framework row, the metadata and the catalogue fixtures.
 */
#[AsCommand(
    name: 'app:load-tisax-2027-requirements',
    description: 'Load the VDA-ISA 2027 control-number catalogue (78 controls, numbers only)'
)]
class LoadTisax2027RequirementsCommand extends Command implements FrameworkLoaderInterface
{
    public function __construct(private readonly TisaxCatalogueProvider $catalogue)
    {
        parent::__construct();
    }

    public function getFrameworkCode(): string
    {
        return 'TISAX-2027';
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
    {
        $r = $this->catalogue->loadCatalogue($update, TisaxCatalogueProvider::VERSION_ISA2027);
        $io?->success(sprintf(
            'TISAX ISA 2027 catalogue (numbers only): %d created, %d updated, %d skipped (of %d).',
            $r['created'],
            $r['updated'],
            $r['skipped'],
            $r['total'],
        ));
        $io?->note('Requirement text arrives via the BYO workbook upload (ENX-licensed).');

        return Command::SUCCESS;
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('update', 'u', InputOption::VALUE_NONE, 'Update existing requirements instead of skipping them');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return $this->loadRequirements(
            (bool) $input->getOption('update'),
            new SymfonyStyle($input, $output),
        );
    }
}
