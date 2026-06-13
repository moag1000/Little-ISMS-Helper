<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Bsi\PanelVerdictAutoApplier;
use App\Service\MappingLibraryLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:mapping:library:import',
    description: 'Importiert eine Mapping-Library-YAML in die Datenbank.',
)]
class MappingLibraryImportCommand extends Command
{
    public function __construct(
        private readonly MappingLibraryLoader $loader,
        private readonly PanelVerdictAutoApplier $panelVerdictAutoApplier,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument(
            'file',
            InputArgument::OPTIONAL,
            'Pfad zur Mapping-YAML — relativ zum Projektroot oder absolut. Default: alle fixtures/library/mappings/*.yaml',
        );
    }

    #[\Override]
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

        // After the mapping library is (re)loaded, apply the build-time expert-panel
        // verdicts so the customer-facing quality UI reflects them immediately:
        //   - reject pairs become lifecycleState='deprecated' (drop out of coverage),
        //   - ki_validiert pairs get provenanceSource='panel' (ki_validiert trust-tier),
        //   - needs_review pairs land in the review queue.
        // Only runs for the full-library load (no single-file argument). Idempotent
        // and non-fatal — a verdict-apply hiccup must not fail the import itself.
        if ($file === null) {
            $this->applyPanelVerdicts($io);
        }

        return $hadError ? Command::FAILURE : Command::SUCCESS;
    }

    private function applyPanelVerdicts(SymfonyStyle $io): void
    {
        $io->section('Panel verdicts (quality grades + deprecations)');

        try {
            $summary = $this->panelVerdictAutoApplier->applyAll(false);
            $io->success(sprintf(
                'Panel verdicts: %d fixture(s) applied, %d skipped — '
                . '%d ki_validiert, %d deprecated, %d needs_review, %d new mapping(s).',
                $summary['applied'],
                $summary['skipped'],
                $summary['ki_validiert'],
                $summary['rejected'],
                $summary['needs_review'],
                $summary['panel_discovered'],
            ));
        } catch (\Throwable $e) {
            // Never fail the import because verdict application stumbled — the
            // mappings are loaded; verdicts can be re-applied via
            // `app:apply-all-panel-verdicts` at any time (idempotent).
            $io->warning('Panel verdict auto-apply skipped: ' . $e->getMessage());
        }
    }
}
