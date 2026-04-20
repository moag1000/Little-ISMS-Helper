<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Import\BsiKompendiumXmlImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importiert Anforderungen aus einem BSI-Grundschutz-XML-Profil.
 *
 * Schema siehe {@see BsiKompendiumXmlImporter}. Für volle Kompendium-
 * 2023-Parität wird das vom BSI offiziell publizierte Profil verwendet
 * (oder ein hauseigenes, das demselben Schema folgt — z. B. intern
 * gepflegte Consultant-Dateien).
 *
 * Usage:
 *   bin/console app:import-bsi-kompendium-xml <file.xml>
 *   bin/console app:import-bsi-kompendium-xml <file.xml> --dry-run
 */
#[AsCommand(
    name: 'app:import-bsi-kompendium-xml',
    description: 'Importiert BSI IT-Grundschutz-Anforderungen aus einem XML-Profil (Verinice-kompatibel).'
)]
class ImportBsiKompendiumXmlCommand extends Command
{
    public function __construct(
        private readonly BsiKompendiumXmlImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Pfad zur BSI-Grundschutz-XML-Datei.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur parsen und zählen — keine DB-Writes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = (string) $input->getArgument('file');
        if (!is_file($file) || !is_readable($file)) {
            $io->error(sprintf('XML-Datei nicht lesbar: %s', $file));
            return Command::FAILURE;
        }

        $xml = (string) file_get_contents($file);
        if ($xml === '') {
            $io->warning('Datei ist leer.');
            return Command::SUCCESS;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $io->info(sprintf('Importiere BSI-Kompendium aus %s (dry-run=%s)', $file, $dryRun ? 'yes' : 'no'));

        $result = $this->importer->import($xml, persist: !$dryRun);

        $io->table(
            ['Bausteine gelesen', 'Anforderungen gelesen', 'Neu erstellt', 'Übersprungen (bereits da)', 'Fehler'],
            [[
                $result['bausteine_read'],
                $result['requirements_read'],
                $result['created'],
                $result['skipped_existing'],
                count($result['errors']),
            ]]
        );

        if ($result['errors'] !== []) {
            $io->warning(sprintf('%d Fehler/Hinweise:', count($result['errors'])));
            foreach (array_slice($result['errors'], 0, 20) as $err) {
                $io->text(sprintf(' - [%s] %s', $err['context'], $err['message']));
            }
            if (count($result['errors']) > 20) {
                $io->text(sprintf(' … %d weitere', count($result['errors']) - 20));
            }
        }

        if ($result['errors'] !== [] && $result['created'] === 0 && !$dryRun) {
            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s abgeschlossen: %d Anforderungen %s.',
            $dryRun ? 'Dry-Run' : 'Import',
            $result['created'],
            $dryRun ? 'würden angelegt' : 'angelegt'
        ));

        return Command::SUCCESS;
    }
}
