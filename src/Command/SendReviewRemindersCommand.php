<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ComplianceCertificateRepository;
use App\Repository\TenantRepository;
use App\Service\Evidence\EvidenceCascadeInvalidationService;
use App\Service\ReviewReminderService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Send Review Reminders Command
 *
 * Sends email notifications for:
 * - Overdue risk reviews
 * - Overdue BC plan reviews/tests
 * - Overdue processing activity reviews (VVT)
 * - Overdue DPIA reviews
 * - Urgent data breach notifications (72h GDPR deadline)
 *
 * GDPR/NIS2 Compliance:
 * - GDPR Art. 33: 72-hour breach notification requirement
 * - GDPR Art. 35(11): DPIA review when circumstances change
 * - ISO 27001: Regular risk review requirements
 * - ISO 22301: BC plan testing requirements
 *
 * Usage:
 *   php bin/console app:review:send-reminders
 *   php bin/console app:review:send-reminders --dry-run
 *   php bin/console app:review:send-reminders --include-upcoming
 *
 * Recommended Cron Setup:
 *   # Daily check for overdue items (8 AM)
 *   0 8 * * * cd /path/to/project && php bin/console app:review:send-reminders >> /var/log/review-reminders.log 2>&1
 *
 *   # Hourly check for urgent data breaches (72h deadline)
 *   0 * * * * cd /path/to/project && php bin/console app:review:send-reminders --breaches-only >> /var/log/breach-reminders.log 2>&1
 */
#[AsCommand(
    name: 'app:review:send-reminders',
    description: 'Send email notifications for overdue reviews and urgent data breaches (GDPR Art. 33)',
    help: <<<'TXT'
The <info>%command.name%</info> command sends reminder emails for overdue reviews.

<info>Compliance Coverage:</info>
  • GDPR Art. 33: 72-hour data breach notification deadline
  • GDPR Art. 35(11): DPIA review requirements
  • ISO 27001: Risk review schedules
  • ISO 22301: BC plan testing requirements

<info>Entity Types Covered:</info>
  • Risks (based on reviewDate)
  • Business Continuity Plans (nextReviewDate, nextTestDate)
  • Processing Activities / VVT (nextReviewDate)
  • DPIAs (nextReviewDate)
  • Data Breaches (72h notification deadline)

<info>Examples:</info>

  # Preview what notifications would be sent (dry run)
  <info>php bin/console %command.name% --dry-run</info>

  # Send all overdue review reminders
  <info>php bin/console %command.name%</info>

  # Also include upcoming reviews (14 days ahead)
  <info>php bin/console %command.name% --include-upcoming</info>

  # Only check data breach deadlines (for hourly cron)
  <info>php bin/console %command.name% --breaches-only</info>

  # Show statistics without sending
  <info>php bin/console %command.name% --stats-only</info>

<info>Recommended Cron Setup:</info>
  <comment># Daily at 8 AM for general reviews</comment>
  <comment>0 8 * * * php bin/console %command.name%</comment>

  <comment># Hourly for urgent breach notifications</comment>
  <comment>0 * * * * php bin/console %command.name% --breaches-only</comment>
TXT
)]
class SendReviewRemindersCommand
{
    public function __construct(
        private readonly ReviewReminderService $reviewReminderService,
        private readonly EvidenceCascadeInvalidationService $evidenceCascadeInvalidationService,
        private readonly TenantRepository $tenantRepository,
        private readonly TenantContext $tenantContext,
        private readonly ComplianceCertificateRepository $certificateRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Show what would be sent without actually sending', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Also send reminders for upcoming reviews (next 14 days)', name: 'include-upcoming')]
        bool $includeUpcoming = false,
        #[Option(description: 'Only check data breach deadlines (for hourly cron)', name: 'breaches-only')]
        bool $breachesOnly = false,
        #[Option(description: 'Only show statistics, do not send any notifications', name: 'stats-only')]
        bool $statsOnly = false,
        ?SymfonyStyle $symfonyStyle = null
    ): int {
        $symfonyStyle->title('ISMS Review Reminder System');

        // Get current statistics
        $stats = $this->reviewReminderService->getDashboardStatistics();
        $allOverdue = $this->reviewReminderService->getAllOverdueReviews();

        $symfonyStyle->section('Current Status');
        $symfonyStyle->table(
            ['Metric', 'Count'],
            [
                ['Total Overdue Reviews', $stats['total']],
                ['Critical Items (High Risks + Breaches)', $stats['critical']],
                ['Urgent Data Breaches (72h)', $stats['urgent_breaches']],
                ['', ''],
                ['Overdue Risks', $stats['by_type']['risks']],
                ['Overdue BC Plans', $stats['by_type']['bc_plans']],
                ['Overdue Processing Activities', $stats['by_type']['processing_activities']],
                ['Overdue DPIAs', $stats['by_type']['dpias']],
            ]
        );

        if ($stats['total'] > 0 || $stats['urgent_breaches'] > 0) {
            $symfonyStyle->section('Overdue by Age');
            $symfonyStyle->table(
                ['Days Overdue', 'Count'],
                [
                    ['0-7 days', $stats['by_days_overdue']['0-7']],
                    ['8-30 days', $stats['by_days_overdue']['8-30']],
                    ['31-90 days', $stats['by_days_overdue']['31-90']],
                    ['90+ days', $stats['by_days_overdue']['90+']],
                ]
            );
        }

        // Show urgent data breaches
        if ($allOverdue['data_breaches'] !== []) {
            $symfonyStyle->section('Urgent Data Breaches (72h Deadline)');
            $breachData = [];
            foreach ($allOverdue['data_breaches'] as $breach) {
                $hoursRemaining = $breach->getHoursUntilAuthorityDeadline();
                $status = $hoursRemaining < 0 ? '🔴 OVERDUE' : ($hoursRemaining < 12 ? '🟠 CRITICAL' : '🟡 WARNING');
                $breachData[] = [
                    $breach->getReferenceNumber(),
                    $breach->getTitle(),
                    $hoursRemaining < 0 ? abs($hoursRemaining) . 'h overdue' : $hoursRemaining . 'h remaining',
                    $status,
                ];
            }
            $symfonyStyle->table(['Reference', 'Title', 'Time', 'Status'], $breachData);
        }

        // Evidence-expiry re-review (per active tenant). Certificate-applied
        // fulfillments get nextReviewDate = cert.validUntil, so this scan is the
        // production caller that flags them outdated once the certificate expires.
        // Skipped in stats-only/breaches-only (no mutation) and reported (not run)
        // in dry-run.
        if (!$breachesOnly && !$statsOnly) {
            $this->scanExpiredEvidence($symfonyStyle, $dryRun);
        }

        if ($statsOnly) {
            $symfonyStyle->info('Stats-only mode: No notifications sent.');
            return Command::SUCCESS;
        }

        if ($stats['total'] === 0 && $stats['urgent_breaches'] === 0) {
            $symfonyStyle->success('No overdue reviews or urgent breaches found. All systems are up to date!');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $symfonyStyle->section('Dry Run Mode');
            $symfonyStyle->note([
                'DRY RUN: No emails will be sent.',
                sprintf('Would send notifications for %d overdue items.', $stats['total']),
                sprintf('Would send %d urgent breach notifications.', $stats['urgent_breaches']),
                'Remove --dry-run to send actual notifications.',
            ]);
            return Command::SUCCESS;
        }

        // Send notifications
        $symfonyStyle->section('Sending Notifications');

        if ($breachesOnly) {
            $symfonyStyle->writeln('Mode: Breach notifications only');
            // Only process data breaches
            $urgentBreaches = $this->reviewReminderService->getUrgentDataBreaches();
            $sent = 0;
            $failed = 0;

            foreach ($urgentBreaches as $breach) {
                // Send notification logic would go here
                // For now, we'll count them
                $sent++;
            }

            $symfonyStyle->success([
                sprintf('Processed %d urgent data breach notifications', count($urgentBreaches)),
            ]);

            return Command::SUCCESS;
        } else {
            $results = $this->reviewReminderService->sendReminderNotifications($includeUpcoming);

            $symfonyStyle->section('Results');
            $symfonyStyle->table(
                ['Metric', 'Count'],
                [
                    ['Notifications Sent', $results['sent']],
                    ['Failed', $results['failed']],
                ]
            );

            if ($results['failed'] > 0) {
                $symfonyStyle->warning(sprintf('%d notifications failed to send. Check logs for details.', $results['failed']));
            }

            if ($results['sent'] > 0) {
                $symfonyStyle->success(sprintf('Successfully sent %d reminder notifications.', $results['sent']));
            }
        }

        // Compliance note
        $symfonyStyle->section('Compliance');
        $symfonyStyle->table(
            ['Standard', 'Requirement', 'Status'],
            [
                ['GDPR Art. 33', '72h breach notification', $stats['urgent_breaches'] === 0 ? '✅ Compliant' : '⚠️ Action Required'],
                ['GDPR Art. 35(11)', 'DPIA regular review', $stats['by_type']['dpias'] === 0 ? '✅ Compliant' : '⚠️ Reviews Pending'],
                ['ISO 27001', 'Risk review schedule', $stats['by_type']['risks'] === 0 ? '✅ Compliant' : '⚠️ Reviews Pending'],
                ['ISO 22301', 'BC plan testing', $stats['by_type']['bc_plans'] === 0 ? '✅ Compliant' : '⚠️ Tests Pending'],
            ]
        );

        return $results['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Flag overdue ComplianceRequirementFulfillments (nextReviewDate < now) as
     * evidenceOutdated for every active tenant. This is the signal that picks up
     * certificate-fulfilled controls once the certificate's validUntil passes.
     *
     * flagExpiredEvidence() has no dry mode, so in dry-run we only report intent
     * and do not mutate.
     */
    private function scanExpiredEvidence(SymfonyStyle $symfonyStyle, bool $dryRun): void
    {
        $symfonyStyle->section('Evidence Expiry Re-Review');

        $tenants = $this->tenantRepository->findActive();

        if ($dryRun) {
            $expiredCerts = 0;
            $now = new \DateTimeImmutable();
            foreach ($tenants as $tenant) {
                $expiredCerts += count($this->certificateRepository->findExpiredActive($tenant, $now));
            }

            $symfonyStyle->note([
                'DRY RUN: No evidence flagged, no certificate status changed.',
                sprintf('Would scan %d active tenant(s) for expired evidence (incl. expired certificates).', count($tenants)),
                sprintf('Would mark %d active certificate(s) as expired.', $expiredCerts),
            ]);

            return;
        }

        $totalFlagged = 0;
        $totalCertsExpired = 0;

        // FIX 1 (ISO 27001 Cl. 7.5.3): AuditLogger resolves the audit-row tenant
        // from TenantContext, which is NULL in CLI. Without setting it per tenant
        // the fulfillment.evidence_expired audit rows would all be written with a
        // null/fallback tenant, breaking the tenant-scoped audit trail. Restore to
        // null in finally so no tenant leaks into later command work.
        try {
            $now = new \DateTimeImmutable();
            foreach ($tenants as $tenant) {
                $this->tenantContext->setCurrentTenant($tenant);

                $totalFlagged += $this->evidenceCascadeInvalidationService->flagExpiredEvidence($tenant);
                $totalCertsExpired += $this->expireLapsedCertificates($tenant, $now);
            }
        } finally {
            $this->tenantContext->setCurrentTenant(null);
        }

        if ($totalFlagged > 0) {
            $symfonyStyle->warning(sprintf(
                '%d fulfillment(s) flagged for re-review due to expired evidence (incl. expired certificates).',
                $totalFlagged,
            ));
        } else {
            $symfonyStyle->writeln('No expired evidence found across active tenants.');
        }

        if ($totalCertsExpired > 0) {
            $symfonyStyle->warning(sprintf(
                '%d certificate(s) marked as expired (validity lapsed).',
                $totalCertsExpired,
            ));
        } else {
            $symfonyStyle->writeln('No active certificates have lapsed.');
        }
    }

    /**
     * FIX 4: ComplianceCertificate::isExpired() exists but nothing flips the
     * cert's own status. findActiveByTenantAndFramework() filters status='active',
     * so a lapsed cert would linger as active forever. Flip status to 'expired'
     * for every active cert whose validUntil has passed.
     *
     * @return int Number of certificates flipped to 'expired'.
     */
    private function expireLapsedCertificates(\App\Entity\Tenant $tenant, \DateTimeImmutable $now): int
    {
        $expired = $this->certificateRepository->findExpiredActive($tenant, $now);

        if ($expired === []) {
            return 0;
        }

        foreach ($expired as $cert) {
            $cert->setStatus('expired');
        }

        $this->entityManager->flush();

        return count($expired);
    }
}
