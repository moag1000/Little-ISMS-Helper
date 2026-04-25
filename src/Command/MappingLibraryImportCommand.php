<?php

namespace App\Command;

use App\Service\MappingLibraryLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mapping:library:import',
    description: 'Importiert eine Mapping-Library-YAML in die Datenbank.',
)]
class MappingLibraryImportCommand extends Command
{
    public function __construct(
        private readonly MappingLibraryLoader $loader,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'file',
            InputArgument::OPTIONAL,
            'Pfad zur Mapping-YAML — relativ zum Projektroot oder absolut. Default: alle fixtures/library/mappings/*.yaml',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = $input->getArgument('file');

        $files = $file !== null
            ? [$file]
            : (glob($this->projectDir . '/fixtures/library/mappings/*.yaml') ?: []);

        if (empty($files)) {
            $io->warning('Keine Mapping-Library-Files gefunden.');
            return Command::SUCCESS;
        }

        $hadError = false;
        foreach ($files as $f) {
            $io->section(basename($f));
            $result = $this->loader->load($f);

            if (!empty($result['warnings'])) {
                foreach ($result['warnings'] as $w) {
                    $io->warning($w);
                }
            }
            if (!$result['success']) {
                foreach ($result['errors'] as $e) {
                    $io->error($e);
                }
                $hadError = true;
                continue;
            }
            $io->success(sprintf(
                'Imported %d, updated %d, skipped %d',
                $result['imported'],
                $result['updated'],
                $result['skipped'],
            ));
        }

        return $hadError ? Command::FAILURE : Command::SUCCESS;
    }
}
