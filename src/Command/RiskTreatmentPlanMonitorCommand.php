<?php

namespace App\Command;

use DateTime;
use Exception;
use App\Repository\TenantRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Service\EmailNotificationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console Command for Risk Treatment Plan Monitoring
 *
 * Monitors treatment plans for overdue and approaching deadlines.
 * Sends email notifications to responsible persons.
 *
 * Priority 2.4 - Treatment Plan Monitoring (Medium Impact, Medium Effort)
 * ISO 27001:2022 Clause 6.1.3 (Information security risk treatment)
 *
 * Features:
 * - Identifies overdue treatment plans
 * - Identifies plans approaching deadline (default: 7 days)
 * - Sends email notifications to responsible persons
 * - Provides statistics on plan status
 *
 * Usage:
 *   php bin/console app:risk:monitor-treatment-plans
 *   php bin/console app:risk:monitor-treatment-plans --tenant=1
 *   php bin/console app:risk:monitor-treatment-plans --days=14
 *   php bin/console app:risk:monitor-treatment-plans --send-notifications
 *
 * Scheduling (crontab):
 *   # Run daily at 9:00 AM
 *   0 9 * * * cd /path/to/project && php bin/console app:risk:monitor-treatment-plans --send-notifications
 */
#[AsCommand(
    name: 'app:risk:monitor-treatment-plans',
    description: 'Monitor risk treatment plans and send deadline notifications (ISO 27001:2022 Clause 6.1.3)'
)]
class RiskTreatmentPlanMonitorCommand extends Command
{
    public function __construct(
        private readonly RiskTreatmentPlanRepository $riskTreatmentPlanRepository,
        private readonly TenantRepository $tenantRepository,
        private readonly EmailNotificationService $emailNotificationService
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
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Days ahead to check for approaching deadlines',
                7
            )
            ->addOption(
                'send-notifications',
                null,
                InputOption::VALUE_NONE,
                'Actually send email notifications (default: dry-run)'
            )
            ->setHelp(<<<'HELP'
This command monitors risk treatment plans and identifies:
  1. Overdue plans (past target completion date)
  2. Plans approaching deadline (within specified days)

By default, this runs in dry-run mode (no emails sent). Use --send-notifications to actually send emails.

Priority levels checked:
  - Critical priority plans (always reported)
  - High priority plans (always reported)
  - Medium/Low priority plans (reported if overdue or approaching deadline)

Examples:
  # Check all tenants (dry-run, no emails)
  php bin/console app:risk:monitor-treatment-plans

  # Check specific tenant with 14-day lookahead
  php bin/console app:risk:monitor-treatment-plans --tenant=1 --days=14

  # Actually send notifications
  php bin/console app:risk:monitor-treatment-plans --send-notifications

  # Production use (scheduled daily)
  0 9 * * * php /var/www/isms/bin/console app:risk:monitor-treatment-plans --send-notifications
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);

        $symfonyStyle->title('Risk Treatment Plan Monitoring (ISO 27001:2022 Clause 6.1.3)');

        // Get options
        $tenantId = $input->getOption('tenant');
        $days = (int) $input->getOption('days');
        $sendNotifications = $input->getOption('send-notifications');

        if (!$sendNotifications) {
            $symfonyStyle->note('Running in DRY-RUN mode. Use --send-notifications to actually send emails.');
        }

        // Get tenants to process
        $tenants = [];
        if ($tenantId) {
            $tenant = $this->tenantRepository->find($tenantId);
            if (!$tenant) {
                $symfonyStyle->error("Tenant with ID {$tenantId} not found.");
                return Command::FAILURE;
            }
            $tenants = [$tenant];
            $symfonyStyle->info("Processing tenant: {$tenant->getName()}");
        } else {
            $tenants = $this->tenantRepository->findAll();
            $symfonyStyle->info('Processing all ' . count($tenants) . ' tenants');
        }

        $totalOverdue = 0;
        $totalApproaching = 0;
        $notificationsSent = 0;

        // Process each tenant
        foreach ($tenants as $tenant) {
            $symfonyStyle->section("Tenant: {$tenant->getName()} (ID: {$tenant->getId()})");

            // Find overdue plans
            $overduePlans = $this->riskTreatmentPlanRepository->findOverdueForTenant($tenant);
            $approachingPlans = $this->riskTreatmentPlanRepository->findDueWithinDays($days, $tenant);

            $totalOverdue += count($overduePlans);
            $totalApproaching += count($approachingPlans);

            if (empty($overduePlans) && empty($approachingPlans)) {
                $symfonyStyle->success('✓ No overdue or approaching deadline plans');
                continue;
            }

            // Display overdue plans
            if (!empty($overduePlans)) {
                $symfonyStyle->warning(count($overduePlans) . ' OVERDUE plans');

                $overdueData = [];
                foreach ($overduePlans as $overduePlan) {
                    $daysOverdue = new DateTime()->diff($overduePlan->getTargetCompletionDate())->days;

                    $overdueData[] = [
                        $overduePlan->getId(),
                        substr((string) $overduePlan->getTitle(), 0, 40),
                        $overduePlan->getPriority(),
                        $overduePlan->getTargetCompletionDate()->format('Y-m-d'),
                        $daysOverdue . ' days',
                        $overduePlan->getCompletionPercentage() . '%',
                        $overduePlan->getResponsiblePerson()?->getFullName() ?? 'Unassigned'
                    ];

                    // Send notification
                    if ($sendNotifications && $overduePlan->getResponsiblePerson()) {
                        try {
                            $this->sendOverdueNotification($overduePlan);
                            $notificationsSent++;
                        } catch (Exception $e) {
                            $symfonyStyle->error("Failed to send notification for plan {$overduePlan->getId()}: " . $e->getMessage());
                        }
                    }
                }

                $symfonyStyle->table(
                    ['ID', 'Title', 'Priority', 'Due Date', 'Overdue', 'Progress', 'Responsible'],
                    $overdueData
                );
            }

            // Display approaching deadline plans
            if (!empty($approachingPlans)) {
                $symfonyStyle->note(count($approachingPlans) . " plans approaching deadline (within {$days} days)");

                $approachingData = [];
                foreach ($approachingPlans as $approachingPlan) {
                    $daysRemaining = new DateTime()->diff($approachingPlan->getTargetCompletionDate())->days;

                    $approachingData[] = [
                        $approachingPlan->getId(),
                        substr((string) $approachingPlan->getTitle(), 0, 40),
                        $approachingPlan->getPriority(),
                        $approachingPlan->getTargetCompletionDate()->format('Y-m-d'),
                        $daysRemaining . ' days',
                        $approachingPlan->getCompletionPercentage() . '%',
                        $approachingPlan->getResponsiblePerson()?->getFullName() ?? 'Unassigned'
                    ];

                    // Send notification for approaching deadlines
                    if ($sendNotifications && $approachingPlan->getResponsiblePerson()) {
                        try {
                            $this->sendApproachingDeadlineNotification($approachingPlan, $daysRemaining);
                            $notificationsSent++;
                        } catch (Exception $e) {
                            $symfonyStyle->error("Failed to send notification for plan {$approachingPlan->getId()}: " . $e->getMessage());
                        }
                    }
                }

                $symfonyStyle->table(
                    ['ID', 'Title', 'Priority', 'Due Date', 'Remaining', 'Progress', 'Responsible'],
                    $approachingData
                );
            }

            $symfonyStyle->newLine();
        }

        // Summary
        $symfonyStyle->section('Summary');
        $symfonyStyle->definitionList(
            ['Total Overdue Plans' => $totalOverdue],
            ['Plans Approaching Deadline' => $totalApproaching],
            ['Notifications Sent' => $sendNotifications ? $notificationsSent : 'N/A (dry-run)']
        );

        if ($totalOverdue > 0 || $totalApproaching > 0) {
            $symfonyStyle->warning('Action required: ' . ($totalOverdue + $totalApproaching) . ' plans need attention');
            return Command::SUCCESS; // Not a failure, but needs attention
        }

        $symfonyStyle->success('All treatment plans are on track!');
        return Command::SUCCESS;
    }

    /**
     * Send notification for overdue plan
     */
    private function sendOverdueNotification($plan): void
    {
        $this->emailNotificationService->sendGenericNotification(
            subject: '[ISMS Alert] Overdue Treatment Plan: ' . $plan->getTitle(),
            template: 'emails/treatment_plan_overdue.html.twig',
            context: [
                'plan' => $plan,
                'risk' => $plan->getRisk(),
                'days_overdue' => new DateTime()->diff($plan->getTargetCompletionDate())->days
            ],
            recipients: [$plan->getResponsiblePerson()]
        );
    }

    /**
     * Send notification for approaching deadline
     */
    private function sendApproachingDeadlineNotification($plan, int $daysRemaining): void
    {
        $this->emailNotificationService->sendGenericNotification(
            subject: '[ISMS Reminder] Treatment Plan Due in ' . $daysRemaining . ' days: ' . $plan->getTitle(),
            template: 'emails/treatment_plan_approaching.html.twig',
            context: [
                'plan' => $plan,
                'risk' => $plan->getRisk(),
                'days_remaining' => $daysRemaining
            ],
            recipients: [$plan->getResponsiblePerson()]
        );
    }
}
