<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TenantRepository;
use App\Service\MrisBaselineService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Wendet eine Branchen-Baseline (Soll-Stufen pro MHC) auf einen Mandanten an.
 *
 * Quelle MRIS-Konzepte: Peddi, R. (2026). MRIS v1.5. CC BY 4.0.
 */
#[AsCommand(
    name: 'app:mris:apply-baseline',
    description: 'Setzt MRIS-MHC-Soll-Stufen aus einer Branchen-Baseline (kritis|finance|automotive_tisax|saas_cra).',
)]
final class MrisApplyBaselineCommand extends Command
{
    public function __construct(
        private readonly MrisBaselineService $baselineService,
        private readonly TenantRepository $tenantRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Mandanten-ID.');
        $this->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Baseline-ID oder Filename (z. B. "kritis-essential" oder "kritis").');
        $this->addOption('list', null, InputOption::VALUE_NONE, 'Verfügbare Baselines listen.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur Vorschau.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            $io->title('Verfügbare MRIS-Baselines');
            $rows = [];
            foreach ($this->baselineService->listBaselines() as $b) {
                $rows[] = [$b['id'], $b['name'], $b['industry'], $b['file']];
            }
            $io->table(['ID', 'Name', 'Branche', 'Datei'], $rows);
            $io->writeln('Quelle: Peddi (2026) MRIS v1.5 — CC BY 4.0.');
            return Command::SUCCESS;
        }

        $tenantId = $input->getOption('tenant');
        $baselineId = $input->getOption('baseline');
        if (!$tenantId || !$baselineId) {
            $io->error('Beide --tenant und --baseline erforderlich (oder --list zum Auflisten).');
            return Command::FAILURE;
        }

        $tenant = $this->tenantRepository->find((int) $tenantId);
        if ($tenant === null) {
            $io->error(sprintf('Mandant %s nicht gefunden.', $tenantId));
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $io->title(sprintf('Baseline "%s" → Mandant "%s"', $baselineId, $tenant->getName() ?? $tenant->getId()));
        if ($dryRun) {
            $io->note('Dry-Run aktiv — keine DB-Änderungen.');
        }

        try {
            $result = $this->baselineService->applyBaseline($tenant, $baselineId, $dryRun);
        } catch (\DomainException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->writeln(sprintf('Baseline-ID:        <info>%s</info>', $result['baseline']));
        $io->writeln(sprintf('Soll-Stufen gesetzt: <info>%d</info>', $result['applied']));
        $io->writeln(sprintf('Übersprungen:       <comment>%d</comment>', $result['skipped']));
        if (!empty($result['missing_mhcs'])) {
            $io->warning('Folgende MHCs sind im Framework nicht vorhanden: ' . implode(', ', $result['missing_mhcs']));
        }

        $io->success($dryRun ? 'Dry-Run abgeschlossen.' : 'Baseline angewendet.');
        return Command::SUCCESS;
    }
}
