<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Tisax\TisaxCatalogueProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the canonical VDA-ISA 6.0 control-number catalogue (80 controls, numbers
 * only) as the single shared TISAX catalogue baseline.
 *
 * Thin wrapper around {@see TisaxCatalogueProvider} — the ONE place that owns the
 * TISAX framework row + catalogue. This command, the BYO import mapper and the
 * admin library importer all delegate to that provider, so there is exactly one
 * importer and one metadata source (the YAML).
 */
#[AsCommand(
    name: 'app:load-tisax-requirements',
    description: 'Load the canonical VDA-ISA 6.0 control-number catalogue (80 controls, numbers only)'
)]
class LoadTisaxRequirementsCommand extends Command
{
    public function __construct(private readonly TisaxCatalogueProvider $catalogue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('update', 'u', InputOption::VALUE_NONE, 'Update existing requirements instead of skipping them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $r = $this->catalogue->loadCatalogue((bool) $input->getOption('update'));
        $io->success(sprintf(
            'TISAX catalogue (numbers only): %d created, %d updated, %d skipped (of %d).',
            $r['created'],
            $r['updated'],
            $r['skipped'],
            $r['total'],
        ));
        return Command::SUCCESS;
    }
}
