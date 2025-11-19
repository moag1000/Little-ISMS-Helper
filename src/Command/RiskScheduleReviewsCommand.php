<?php

namespace App\Command;

use App\Entity\Tenant;
use App\Repository\TenantRepository;
use App\Service\RiskReviewService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console Command for Bulk Risk Review Scheduling
 *
 * Schedules review dates for all risks without a review date set.
 * Useful for:
 * - Initial setup after Priority 1.5 implementation
 * - After importing risks from external sources
 * - Manual triggering of review scheduling
 *
 * Usage:
 *   php bin/console app:risk:schedule-reviews
 *   php bin/console app:risk:schedule-reviews --tenant=1
 */
#[AsCommand(
    name: 'app:risk:schedule-reviews',
    description: 'Schedule review dates for risks without review date (ISO 27001:2022 Clause 6.1.3.d)'
)]
class RiskScheduleReviewsCommand extends Command
{
    public function __construct(
        private RiskReviewService $riskReviewService,
        private TenantRepository $tenantRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'tenant',
                't',
                InputOption::VALUE_OPTIONAL,
                'Process only specific tenant ID'
            )
            ->setHelp(<<<'HELP'
This command schedules review dates for all risks that don't have a review date set.

Review intervals are based on risk level (ISO 27001:2022):
  - Critical risks: 90 days (quarterly)
  - High risks: 180 days (semi-annually)
  - Medium risks: 365 days (annually)
  - Low risks: 730 days (bi-annually)

Examples:
  # Schedule reviews for all tenants
  php bin/console app:risk:schedule-reviews

  # Schedule reviews for specific tenant
  php bin/console app:risk:schedule-reviews --tenant=1
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Risk Review Scheduling (ISO 27001:2022 Clause 6.1.3.d)');

        // Get tenants to process
        $tenantId = $input->getOption('tenant');
        if ($tenantId) {
            $tenant = $this->tenantRepository->find($tenantId);
            if (!$tenant) {
                $io->error("Tenant with ID {$tenantId} not found.");
                return Command::FAILURE;
            }
            $tenants = [$tenant];
            $io->info("Processing tenant: {$tenant->getName()} (ID: {$tenant->getId()})");
        } else {
            $tenants = $this->tenantRepository->findAll();
            $io->info('Processing all tenants: ' . count($tenants));
        }

        $totalScheduled = 0;

        foreach ($tenants as $tenant) {
            $io->section("Tenant: {$tenant->getName()} (ID: {$tenant->getId()})");

            // Get statistics before scheduling
            $statsBefore = $this->riskReviewService->getReviewStatistics($tenant);

            $io->text([
                "Total risks: {$statsBefore['total']}",
                "Risks without review date: {$statsBefore['never_reviewed']}",
                "Overdue reviews: {$statsBefore['overdue']}",
            ]);

            if ($statsBefore['never_reviewed'] === 0) {
                $io->success('All risks already have review dates scheduled.');
                continue;
            }

            // Schedule reviews
            $io->text('Scheduling reviews based on risk levels...');
            $scheduled = $this->riskReviewService->bulkScheduleReviews($tenant);

            $io->success("✓ Scheduled {$scheduled} risk reviews");

            // Show statistics after scheduling
            $statsAfter = $this->riskReviewService->getReviewStatistics($tenant);

            $io->table(
                ['Metric', 'Value'],
                [
                    ['Total Risks', $statsAfter['total']],
                    ['Overdue Reviews', $statsAfter['overdue']],
                    ['Reviews Due (7 days)', $statsAfter['upcoming_7']],
                    ['Reviews Due (30 days)', $statsAfter['upcoming_30']],
                    ['Never Reviewed', $statsAfter['never_reviewed']],
                ]
            );

            $totalScheduled += $scheduled;
        }

        $io->newLine();
        $io->success("Total reviews scheduled across all tenants: {$totalScheduled}");

        $io->note([
            'Review Schedule (ISO 27001:2022):',
            '  • Critical risks: Every 90 days (quarterly)',
            '  • High risks: Every 180 days (semi-annually)',
            '  • Medium risks: Every 365 days (annually)',
            '  • Low risks: Every 730 days (bi-annually)',
        ]);

        return Command::SUCCESS;
    }
}
