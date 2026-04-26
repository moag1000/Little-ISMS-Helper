<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ControlRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lädt MRIS-v1.5-Klassifikation aus fixtures/seeds/mris_annex_a_classification.csv
 * und setzt mythos_resilience + mythos_flanking_mhcs auf der Control-Entität.
 *
 * Quelle: Peddi, R. (2026). MRIS — Mythos-resistente Informationssicherheit, v1.5.
 * Lizenz: CC BY 4.0 — https://creativecommons.org/licenses/by/4.0/
 *
 * KEIN INSERT — die 93 Annex-A-Controls existieren bereits in der control-Tabelle.
 * Dieses Command aktualisiert nur die zwei MRIS-Felder per Lookup auf control_id.
 */
#[AsCommand(
    name: 'app:mris:seed-classification',
    description: 'Setzt MRIS-v1.5-Klassifikation (Standfest/Degradiert/Reibung/Nicht-betroffen) und flankierende MHCs auf existierenden Annex-A-Controls.',
)]
class MrisSeedClassificationCommand extends Command
{
    private const CATEGORY_MAP = [
        'S' => 'standfest',
        'T' => 'degradiert',
        'R' => 'reibung',
        'N' => 'nicht_betroffen',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ControlRepository $controlRepository,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'file',
            null,
            InputOption::VALUE_OPTIONAL,
            'Pfad zur CSV — relativ zum Projektroot oder absolut.',
            'fixtures/seeds/mris_annex_a_classification.csv',
        );
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur Vorschau, keine DB-Änderungen.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $relPath = (string) $input->getOption('file');
        $absPath = str_starts_with($relPath, '/') ? $relPath : $this->projectDir . '/' . $relPath;

        if (!is_file($absPath)) {
            $io->error(sprintf('CSV-Datei nicht gefunden: %s', $absPath));
            return Command::FAILURE;
        }

        $io->title('MRIS v1.5 — Annex-A-Klassifikation seeden');
        $io->writeln(sprintf('Quelle: <info>%s</info>', $absPath));
        $io->writeln('Lizenz: <info>CC BY 4.0 (Peddi, R. (2026). MRIS v1.5)</info>');
        if ($dryRun) {
            $io->note('Dry-Run aktiv — keine DB-Änderungen.');
        }

        $handle = fopen($absPath, 'r');
        if ($handle === false) {
            $io->error('CSV konnte nicht gelesen werden.');
            return Command::FAILURE;
        }

        $header = fgetcsv($handle, 0, ';');
        if ($header === false || count($header) < 4) {
            fclose($handle);
            $io->error('CSV-Header ungültig. Erwartet: control_id;title;category;flanking_mhcs');
            return Command::FAILURE;
        }

        $updated = 0;
        $missing = 0;
        $invalidCategory = 0;
        $missingControls = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) < 3) {
                continue;
            }
            [$controlId, $title, $rawCategory] = $row;
            $flankingRaw = $row[3] ?? '';
            $category = self::CATEGORY_MAP[$rawCategory] ?? null;

            if ($category === null) {
                $invalidCategory++;
                $io->warning(sprintf('Ungültige Kategorie "%s" für %s (erwartet: S/T/R/N)', $rawCategory, $controlId));
                continue;
            }

            $flanking = $flankingRaw === ''
                ? null
                : array_values(array_filter(array_map('trim', explode(',', $flankingRaw)), static fn(string $s): bool => $s !== ''));

            $controls = $this->controlRepository->findBy(['controlId' => $controlId]);
            if (empty($controls)) {
                $missing++;
                $missingControls[] = $controlId;
                continue;
            }

            foreach ($controls as $control) {
                $control->setMythosResilience($category);
                $control->setMythosFlankingMhcs($flanking);
                $updated++;
            }
        }
        fclose($handle);

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->section('Ergebnis');
        $io->writeln(sprintf('Aktualisierte Controls: <info>%d</info>', $updated));
        $io->writeln(sprintf('Nicht gefunden:         <comment>%d</comment>', $missing));
        $io->writeln(sprintf('Ungültige Kategorien:   <comment>%d</comment>', $invalidCategory));
        if ($missing > 0) {
            $io->note('Nicht gefundene control_ids: ' . implode(', ', array_slice($missingControls, 0, 20)) . ($missing > 20 ? ' …' : ''));
        }

        if ($invalidCategory > 0) {
            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry-Run abgeschlossen.' : 'Seed abgeschlossen.');
        return Command::SUCCESS;
    }
}
