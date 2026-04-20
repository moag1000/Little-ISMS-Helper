<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ReuseTrendSnapshot;
use App\Entity\Tenant;
use App\Repository\ReuseTrendSnapshotRepository;
use App\Repository\TenantRepository;
use App\Service\InheritanceMetricsService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Captures today's Reuse-Metrics-Snapshot per Tenant (Sprint 4 / R3).
 *
 * Usage:
 *   bin/console app:reuse:capture-snapshot          # alle aktiven Tenants
 *   bin/console app:reuse:capture-snapshot --tenant=42
 *
 * Idempotent: Existiert bereits ein Snapshot für (tenant, today), wird
 * er aktualisiert statt dupliziert. Cron-tauglich (täglich einmal).
 */
#[AsCommand(
    name: 'app:reuse:capture-snapshot',
    description: 'Schreibt den heutigen Reuse-Trend-Snapshot pro Tenant (für 12-Monats-Chart).'
)]
class CaptureReuseSnapshotCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly InheritanceMetricsService $metricsService,
        private readonly ReuseTrendSnapshotRepository $snapshotRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('tenant', 't', InputOption::VALUE_REQUIRED, 'Nur für diesen Tenant (ID).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new DateTimeImmutable();
        $today = $now->setTime(0, 0);

        $tenantId = $input->getOption('tenant');
        $tenants = $tenantId !== null
            ? array_filter([$this->tenantRepository->find((int) $tenantId)])
            : $this->tenantRepository->findAll();

        if ($tenants === []) {
            $io->warning('Keine Tenants gefunden.');
            return Command::SUCCESS;
        }

        $created = 0;
        $updated = 0;

        foreach ($tenants as $tenant) {
            if (!$tenant instanceof Tenant) {
                continue;
            }

            $metrics = $this->metricsService->metricsForTenant($tenant);
            $totals = $metrics['total'] ?? [];
            $inherited = (int) (($totals['fulfillments_from_inheritance_confirmed'] ?? 0)
                + ($totals['fulfillments_from_inheritance_overridden'] ?? 0));
            $fulfillmentsTotal = (int) ($totals['fulfillments_total'] ?? 0);
            $rate = (int) ($totals['inheritance_rate_percent'] ?? 0);
            $fteSaved = $this->metricsService->fteSavedForTenant($tenant);

            $existing = $this->snapshotRepository->findByTenantAndDay($tenant, $today);
            if ($existing instanceof ReuseTrendSnapshot) {
                $existing->setFteSavedTotal((float) $fteSaved);
                $existing->setInheritedCount($inherited);
                $existing->setFulfillmentsTotal($fulfillmentsTotal);
                $existing->setInheritanceRatePct($rate);
                $updated++;
            } else {
                $snapshot = new ReuseTrendSnapshot($tenant, $now);
                $snapshot->setFteSavedTotal((float) $fteSaved);
                $snapshot->setInheritedCount($inherited);
                $snapshot->setFulfillmentsTotal($fulfillmentsTotal);
                $snapshot->setInheritanceRatePct($rate);
                $this->entityManager->persist($snapshot);
                $created++;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Reuse-Snapshots: %d neu, %d aktualisiert (Tenants: %d).',
            $created,
            $updated,
            count($tenants)
        ));
        return Command::SUCCESS;
    }
}
