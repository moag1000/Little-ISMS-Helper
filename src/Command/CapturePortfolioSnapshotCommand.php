<?php

namespace App\Command;

use App\Entity\PortfolioSnapshot;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\PortfolioSnapshotRepository;
use App\Repository\TenantRepository;
use App\Service\PortfolioReportService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Capture Portfolio Snapshot Command
 *
 * Persists one row per (tenant, framework, NIST CSF category) into portfolio_snapshot
 * so PortfolioReportService can compute real trend-deltas instead of the
 * placeholder "0" documented in CM-3.
 *
 * Idempotent — a second run on the same day is a no-op.
 *
 * Usage:
 *   php bin/console app:portfolio:capture-snapshot
 *   php bin/console app:portfolio:capture-snapshot --dry-run
 *   php bin/console app:portfolio:capture-snapshot --tenant=ACME
 *
 * Recommended cron (daily, shortly after the KPI snapshot):
 *   15 1 * * * cd /path/to/project && php bin/console app:portfolio:capture-snapshot
 */
#[AsCommand(
    name: 'app:portfolio:capture-snapshot',
    description: 'Capture daily portfolio (NIST CSF x framework) snapshots per tenant for trend deltas',
)]
class CapturePortfolioSnapshotCommand
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly PortfolioSnapshotRepository $snapshotRepository,
        private readonly PortfolioReportService $portfolioReportService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Show what would be captured without writing', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Restrict to a single tenant by tenant.code', name: 'tenant')]
        ?string $tenantCode = null,
        ?SymfonyStyle $symfonyStyle = null,
    ): int {
        $symfonyStyle ??= new SymfonyStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\ConsoleOutput(),
        );

        $today = new DateTimeImmutable('today');
        $symfonyStyle->title('Portfolio Snapshot');
        $symfonyStyle->writeln(sprintf('Snapshot date: <info>%s</info>', $today->format('Y-m-d')));
        $symfonyStyle->writeln(sprintf('Mode: %s', $dryRun ? 'DRY RUN' : 'PRODUCTION'));

        $tenants = $tenantCode !== null && $tenantCode !== ''
            ? array_filter([$this->tenantRepository->findByCode($tenantCode)])
            : $this->tenantRepository->findActive();

        if ($tenants === []) {
            $symfonyStyle->warning('No active tenants found (or --tenant did not match).');
            return Command::SUCCESS;
        }

        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        if ($frameworks === []) {
            $symfonyStyle->warning('No active compliance frameworks configured — nothing to snapshot.');
            return Command::SUCCESS;
        }

        $created = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($tenants as $tenant) {
            if (!$tenant instanceof Tenant) {
                continue;
            }
            $tenantLabel = sprintf('%s (%s)', (string) $tenant->getName(), (string) $tenant->getCode());

            if ($this->snapshotRepository->existsForDate($tenant, $today)) {
                $symfonyStyle->writeln(sprintf('  [SKIP] %s — snapshot already exists', $tenantLabel));
                $skipped++;
                continue;
            }

            $tenantRows = 0;
            try {
                foreach ($frameworks as $framework) {
                    foreach (PortfolioReportService::CATEGORIES as $category) {
                        $agg = $this->portfolioReportService->computeCellAggregate(
                            $framework,
                            $tenant,
                            $category,
                        );

                        if ($dryRun) {
                            $tenantRows++;
                            continue;
                        }

                        $snapshot = new PortfolioSnapshot();
                        $snapshot->setTenant($tenant);
                        $snapshot->setSnapshotDate($today);
                        $snapshot->setFrameworkCode((string) $framework->getCode());
                        $snapshot->setNistCsfCategory($category);
                        $snapshot->setFulfillmentPercentage($agg['pct']);
                        $snapshot->setRequirementCount($agg['count']);
                        $snapshot->setGapCount($agg['gaps']);

                        $this->entityManager->persist($snapshot);
                        $tenantRows++;
                    }
                }

                if (!$dryRun) {
                    $this->entityManager->flush();
                    $this->entityManager->clear(PortfolioSnapshot::class);
                }

                $symfonyStyle->writeln(sprintf(
                    '  [%s] %s — %d rows',
                    $dryRun ? 'DRY' : 'OK',
                    $tenantLabel,
                    $tenantRows,
                ));
                $created += $tenantRows;
            } catch (\Throwable $e) {
                $symfonyStyle->writeln(sprintf('  [ERR] %s — %s', $tenantLabel, $e->getMessage()));
                $errors++;
                if (!$this->entityManager->isOpen()) {
                    $symfonyStyle->warning('EntityManager closed after error. Aborting.');
                    break;
                }
            }
        }

        $symfonyStyle->newLine();
        $symfonyStyle->section('Summary');
        $symfonyStyle->table(
            ['Metric', 'Count'],
            [
                ['Rows captured' . ($dryRun ? ' (dry)' : ''), $created],
                ['Tenants skipped (already snapshot)', $skipped],
                ['Errors', $errors],
            ],
        );

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
