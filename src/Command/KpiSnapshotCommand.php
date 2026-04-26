<?php

namespace App\Command;

use App\Entity\KpiSnapshot;
use App\Entity\Tenant;
use App\Repository\KpiSnapshotRepository;
use App\Repository\SystemSettingsRepository;
use App\Repository\TenantRepository;
use App\Service\DashboardStatisticsService;
use App\Service\MrisKpiService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * KPI Snapshot Command
 *
 * Takes a daily snapshot of key performance indicators for all active tenants.
 * Designed to run as a daily cron job (e.g., 1 AM) to build trend data that
 * allows CISOs and boards to see compliance improvement or deterioration.
 *
 * Idempotent: skips tenants that already have a snapshot for the target date.
 *
 * Usage:
 *   php bin/console app:kpi-snapshot
 *   php bin/console app:kpi-snapshot --dry-run
 *
 * Cron Setup (recommended: daily at 1 AM):
 *   0 1 * * * cd /path/to/project && php bin/console app:kpi-snapshot >> /var/log/kpi-snapshot.log 2>&1
 */
#[AsCommand(
    name: 'app:kpi-snapshot',
    description: 'Take a daily KPI snapshot for all tenants',
    help: <<<'TXT'
The <info>%command.name%</info> command captures a snapshot of key performance indicators
for every active tenant. These snapshots are used to calculate KPI trends on the dashboard.

<info>Examples:</info>

  # Take snapshots for today
  <info>php bin/console %command.name%</info>

  # Preview without saving (dry run)
  <info>php bin/console %command.name% --dry-run</info>

<info>Recommended Cron Setup (daily at 1 AM):</info>
  <comment>0 1 * * * cd /path/to/project && php bin/console %command.name% >> /var/log/kpi-snapshot.log 2>&1</comment>
TXT
)]
class KpiSnapshotCommand
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly KpiSnapshotRepository $kpiSnapshotRepository,
        private readonly DashboardStatisticsService $dashboardStatisticsService,
        private readonly EntityManagerInterface $entityManager,
        private readonly MrisKpiService $mrisKpiService,
        private readonly ?SystemSettingsRepository $systemSettingsRepository = null,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Show what would be captured without saving', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Also cleanup old snapshots (keep 30 daily + 12 monthly)', name: 'cleanup')]
        bool $cleanup = false,
        ?SymfonyStyle $symfonyStyle = null
    ): int {
        $symfonyStyle->title('KPI Snapshot');
        $today = new DateTimeImmutable('today');
        $symfonyStyle->writeln(sprintf('Snapshot date: <info>%s</info>', $today->format('Y-m-d')));
        $symfonyStyle->writeln(sprintf('Mode: %s', $dryRun ? 'DRY RUN' : 'PRODUCTION'));
        $symfonyStyle->newLine();

        $tenants = $this->tenantRepository->findActive();

        if ($tenants === []) {
            $symfonyStyle->warning('No active tenants found.');
            return Command::SUCCESS;
        }

        $symfonyStyle->writeln(sprintf('Found <info>%d</info> active tenant(s)', count($tenants)));
        $symfonyStyle->newLine();

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            $tenantLabel = sprintf('%s (%s)', $tenant->getName(), $tenant->getCode());

            // Idempotent: skip if snapshot already exists for today
            if ($this->kpiSnapshotRepository->existsForDate($tenant, $today)) {
                $symfonyStyle->writeln(sprintf('  [SKIP] %s - snapshot already exists', $tenantLabel));
                $skipped++;
                continue;
            }

            try {
                $kpiData = $this->extractFlatKpiData($tenant);

                if ($dryRun) {
                    $symfonyStyle->writeln(sprintf('  [DRY]  %s - would capture %d KPIs', $tenantLabel, count($kpiData)));
                    if ($symfonyStyle->isVerbose()) {
                        foreach ($kpiData as $key => $value) {
                            $symfonyStyle->writeln(sprintf('         %s: %s', $key, $value));
                        }
                    }
                    $created++;
                    continue;
                }

                $snapshot = new KpiSnapshot();
                $snapshot->setTenant($tenant);
                $snapshot->setSnapshotDate($today);
                $snapshot->setKpiData($kpiData);

                $this->entityManager->persist($snapshot);
                $this->entityManager->flush();

                $symfonyStyle->writeln(sprintf('  [OK]   %s - captured %d KPIs', $tenantLabel, count($kpiData)));
                $created++;
            } catch (\Throwable $e) {
                $symfonyStyle->writeln(sprintf(
                    '  [ERR]  %s - %s',
                    $tenantLabel,
                    $e->getMessage()
                ));
                $errors++;

                // Re-open EntityManager if it was closed by a DB error
                if (!$this->entityManager->isOpen()) {
                    $symfonyStyle->warning('EntityManager closed after error. Remaining tenants will be skipped.');
                    break;
                }
            }
        }

        $symfonyStyle->newLine();
        $symfonyStyle->section('Summary');
        $symfonyStyle->table(
            ['Metric', 'Count'],
            [
                ['Snapshots created' . ($dryRun ? ' (would create)' : ''), $created],
                ['Skipped (already exists)', $skipped],
                ['Errors', $errors],
            ]
        );

        if ($errors > 0) {
            $symfonyStyle->warning(sprintf('%d tenant(s) failed. Check the errors above.', $errors));
        } else {
            $symfonyStyle->success($dryRun
                ? sprintf('Dry run complete. %d snapshot(s) would be created.', $created)
                : sprintf('Successfully captured %d snapshot(s).', $created)
            );
        }

        // Cleanup old snapshots if requested
        if ($cleanup && !$dryRun) {
            $symfonyStyle->section('Snapshot Cleanup');

            // Read retention settings from admin panel (SystemSettings)
            $dailyRetention = 30;
            $monthlyRetention = 12;
            if ($this->systemSettingsRepository !== null) {
                $dailyRetention = (int) ($this->systemSettingsRepository->getSetting('kpi', 'snapshot_daily_retention_days', 30));
                $monthlyRetention = (int) ($this->systemSettingsRepository->getSetting('kpi', 'snapshot_monthly_retention_months', 12));
            }

            $symfonyStyle->writeln(sprintf('Retention: <info>%d</info> days daily, <info>%d</info> months monthly', $dailyRetention, $monthlyRetention));
            $symfonyStyle->writeln('(Configurable via Admin Panel → System Settings → KPI category)');

            $totalDeleted = 0;
            foreach ($tenants as $tenant) {
                try {
                    $deleted = $this->kpiSnapshotRepository->cleanupOldSnapshots($tenant, $dailyRetention, $monthlyRetention);
                    if ($deleted > 0) {
                        $symfonyStyle->writeln(sprintf('  [OK] %s — %d old snapshot(s) deleted', $tenant->getName(), $deleted));
                        $totalDeleted += $deleted;
                    }
                } catch (\Throwable $e) {
                    $symfonyStyle->writeln(sprintf('  [ERR] %s — %s', $tenant->getName(), $e->getMessage()));
                }
            }

            $symfonyStyle->success(sprintf('Cleanup complete. %d snapshot(s) deleted.', $totalDeleted));
        }

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Extract flat KPI values from the structured management KPIs for a given tenant.
     *
     * @param Tenant $tenant The tenant to extract KPIs for
     * @return array<string, int|float> Flat key-value map of KPI values
     */
    private function extractFlatKpiData(Tenant $tenant): array
    {
        $kpis = $this->dashboardStatisticsService->getManagementKPIs($tenant);

        $data = [];

        // Core KPIs
        if (isset($kpis['core']['control_compliance']['value'])) {
            $data['control_compliance'] = $kpis['core']['control_compliance']['value'];
        }

        // Risk management KPIs
        if (isset($kpis['risk_management'])) {
            $riskKpis = $kpis['risk_management'];
            if (isset($riskKpis['risk_treatment_rate']['value'])) {
                $data['risk_treatment_rate'] = $riskKpis['risk_treatment_rate']['value'];
            }
            if (isset($riskKpis['total_risks']['value'])) {
                $data['total_risks'] = $riskKpis['total_risks']['value'];
            }
            if (isset($riskKpis['high_risks']['value'])) {
                $data['high_risks'] = $riskKpis['high_risks']['value'];
            }
            if (isset($riskKpis['critical_risks']['value'])) {
                $data['critical_risks'] = $riskKpis['critical_risks']['value'];
            }
        }

        // Incident KPIs
        if (isset($kpis['incident_management']['open_incidents']['value'])) {
            $data['open_incidents'] = $kpis['incident_management']['open_incidents']['value'];
        }

        // Training KPIs
        if (isset($kpis['training']['training_completion_rate']['value'])) {
            $data['training_completion'] = $kpis['training']['training_completion_rate']['value'];
        }

        // Supplier KPIs
        if (isset($kpis['supplier_management']['supplier_assessment_rate']['value'])) {
            $val = $kpis['supplier_management']['supplier_assessment_rate']['value'];
            if (is_numeric($val)) {
                $data['supplier_assessment'] = $val;
            }
        }

        // Health score
        if (isset($kpis['health']['isms_health_score']['value'])) {
            $data['isms_health_score'] = $kpis['health']['isms_health_score']['value'];
        }

        // MRIS-Mythos-KPIs (3 automatisch berechenbar). Manuelle KPIs werden
        // nicht gesnapshotted — sie ändern sich nur per Hand-Eingabe.
        // Nur berücksichtigen wenn Mandant MRIS aktiviert hat.
        $settings = $tenant->getSettings() ?? [];
        $mrisEnabled = $settings['mris']['kpis_enabled'] ?? true;
        if ($mrisEnabled) {
            try {
                foreach ($this->mrisKpiService->computeAll($tenant) as $kpi) {
                    if ($kpi['computable'] === true && $kpi['value'] !== null) {
                        $data['mris_' . $kpi['id']] = $kpi['value'];
                    }
                }
            } catch (\Throwable) {
                // MRIS-Tabellen evtl. nicht migriert — Snapshot bleibt ohne MRIS-Daten.
            }
        }

        return $data;
    }
}
