<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prüft Reciprocity zwischen allen Framework-Paaren mit Mappings:
 * Wenn A→B-Mappings existieren UND B→A-Mappings existieren, ist deren
 * Coherence der Anteil reziproker Pairs.
 *
 * Output:
 *   ISO27001:2022 ↔ NIS2-Art21:    forward 47 / reverse 41 / coherence 87.2%
 *   ISO27001:2022 ↔ DORA:           forward 65 / reverse 0  / no reverse mapping
 */
#[AsCommand(
    name: 'app:mapping:check-reciprocity',
    description: 'Prüft Bidirectional Coherence aller Cross-Framework-Mappings.',
)]
class MappingCheckReciprocityCommand extends Command
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('threshold', 't', InputOption::VALUE_REQUIRED, 'Coherence-% Schwelle für Warnung', '80');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $threshold = (float) $input->getOption('threshold');
        $frameworks = $this->frameworkRepository->findAll();

        $rows = [];
        $warnings = 0;
        foreach ($frameworks as $source) {
            foreach ($frameworks as $target) {
                if ($source === $target) {
                    continue;
                }
                $coverage = $this->mappingRepository->coverageBetweenFrameworks($source, $target);
                if ($coverage['source_with_mapping'] === 0) {
                    continue;  // Kein A→B-Mapping → nicht relevant
                }
                $coherence = $this->mappingRepository->reciprocityCoherence($source, $target) * 100;
                $reverseCoverage = $this->mappingRepository->coverageBetweenFrameworks($target, $source);
                $hasReverse = $reverseCoverage['source_with_mapping'] > 0;

                $status = '';
                if (!$hasReverse) {
                    $status = '<fg=yellow>missing reverse</>';
                    $warnings++;
                } elseif ($coherence < $threshold) {
                    $status = sprintf('<fg=red>%.1f%% (below %.0f%%)</>', $coherence, $threshold);
                    $warnings++;
                } else {
                    $status = sprintf('<fg=green>%.1f%%</>', $coherence);
                }

                $rows[] = [
                    sprintf('%s → %s', $source->getName(), $target->getName()),
                    $coverage['source_with_mapping'],
                    $hasReverse ? $reverseCoverage['source_with_mapping'] : '—',
                    $status,
                ];
            }
        }

        if (empty($rows)) {
            $io->success('Keine Cross-Framework-Mappings vorhanden.');
            return Command::SUCCESS;
        }

        $io->title('Mapping Reciprocity Check');
        $io->table(['Direction', 'Forward', 'Reverse', 'Coherence'], $rows);

        if ($warnings > 0) {
            $io->warning(sprintf('%d Mappings haben Reciprocity-Probleme.', $warnings));
            return Command::FAILURE;
        }
        $io->success('Alle Mappings haben kohärente Rückrichtung.');
        return Command::SUCCESS;
    }
}
