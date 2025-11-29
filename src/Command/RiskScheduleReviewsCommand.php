<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\Option;
use App\Repository\TenantRepository;
use App\Service\RiskReviewService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
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
#[AsCommand(name: 'app:risk:schedule-reviews', description: 'Schedule review dates for risks without review date (ISO 27001:2022 Clause 6.1.3.d)', help: <<<'TXT'
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
TXT)]
class RiskScheduleReviewsCommand
{
    public function __construct(private readonly RiskReviewService $riskReviewService, private readonly TenantRepository $tenantRepository)
    {
    }

    public function __invoke(#[Option(name: 'tenant', shortcut: 't', mode: InputOption::VALUE_OPTIONAL, description: 'Process only specific tenant ID')]
    $tenant, SymfonyStyle $symfonyStyle): int
    {
        $symfonyStyle->title('Risk Review Scheduling (ISO 27001:2022 Clause 6.1.3.d)');

        // Get tenants to process
        $tenantId = $tenant;
        if ($tenantId) {
            $tenant = $this->tenantRepository->find($tenantId);
            if (!$tenant) {
                $symfonyStyle->error("Tenant with ID {$tenantId} not found.");
                return Command::FAILURE;
            }
            $tenants = [$tenant];
            $symfonyStyle->info("Processing tenant: {$tenant->getName()} (ID: {$tenant->getId()})");
        } else {
            $tenants = $this->tenantRepository->findAll();
            $symfonyStyle->info('Processing all tenants: ' . count($tenants));
        }

        $totalScheduled = 0;

        foreach ($tenants as $tenant) {
            $symfonyStyle->section("Tenant: {$tenant->getName()} (ID: {$tenant->getId()})");

            // Get statistics before scheduling
            $statsBefore = $this->riskReviewService->getReviewStatistics($tenant);

            $symfonyStyle->text([
                "Total risks: {$statsBefore['total']}",
                "Risks without review date: {$statsBefore['never_reviewed']}",
                "Overdue reviews: {$statsBefore['overdue']}",
            ]);

            if ($statsBefore['never_reviewed'] === 0) {
                $symfonyStyle->success('All risks already have review dates scheduled.');
                continue;
            }

            // Schedule reviews
            $symfonyStyle->text('Scheduling reviews based on risk levels...');
            $scheduled = $this->riskReviewService->bulkScheduleReviews($tenant);

            $symfonyStyle->success("✓ Scheduled {$scheduled} risk reviews");

            // Show statistics after scheduling
            $statsAfter = $this->riskReviewService->getReviewStatistics($tenant);

            $symfonyStyle->table(
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

        $symfonyStyle->newLine();
        $symfonyStyle->success("Total reviews scheduled across all tenants: {$totalScheduled}");

        $symfonyStyle->note([
            'Review Schedule (ISO 27001:2022):',
            '  • Critical risks: Every 90 days (quarterly)',
            '  • High risks: Every 180 days (semi-annually)',
            '  • Medium risks: Every 365 days (annually)',
            '  • Low risks: Every 730 days (bi-annually)',
        ]);

        return Command::SUCCESS;
    }
}
