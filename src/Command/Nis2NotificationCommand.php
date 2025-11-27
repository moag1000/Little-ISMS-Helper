<?php

namespace App\Command;

use DateTimeImmutable;
use Exception;
use App\Entity\Incident;
use App\Repository\IncidentRepository;
use App\Service\EmailNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * NIS2 Incident Reporting Deadline Notification Command
 *
 * Monitors incident reporting deadlines and sends automated notifications
 * to ensure compliance with NIS2 Article 23 reporting requirements.
 *
 * NIS2 Reporting Deadlines:
 * - Early Warning: 24 hours (notify at 20h, 22h, 23h)
 * - Detailed Notification: 72 hours (notify at 68h, 70h, 71h)
 * - Final Report: 1 month (notify at 7 days before, 3 days before, 1 day before)
 *
 * Usage:
 *   php bin/console app:nis2-notification-check
 *   php bin/console app:nis2-notification-check --dry-run
 */
#[AsCommand(
    name: 'app:nis2-notification-check',
    description: 'Check NIS2 incident reporting deadlines and send notifications'
)]
class Nis2NotificationCommand extends Command
{
    // Notification thresholds (hours before deadline)
    private const array EARLY_WARNING_THRESHOLDS = [4, 2, 1]; // 20h, 22h, 23h
    private const array DETAILED_NOTIFICATION_THRESHOLDS = [4, 2, 1]; // 68h, 70h, 71h
    private const array FINAL_REPORT_THRESHOLDS = [168, 72, 24]; // 7 days, 3 days, 1 day (in hours)

    public function __construct(
        private readonly IncidentRepository $incidentRepository,
        private readonly EmailNotificationService $emailNotificationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate notifications without sending')
            ->setHelp(<<<'HELP'
This command checks all active incidents for approaching NIS2 reporting deadlines
and sends automated notifications to ensure compliance.

Run this command via cron every hour:
  0 * * * * php /path/to/bin/console app:nis2-notification-check

For testing:
  php bin/console app:nis2-notification-check --dry-run
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $symfonyStyle = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $symfonyStyle->warning('DRY RUN MODE - No notifications will be sent');
        }

        $symfonyStyle->title('NIS2 Incident Reporting Deadline Check');

        // Find all incidents that require NIS2 reporting (crossBorderImpact or nis2Category set)
        $incidents = $this->incidentRepository->createQueryBuilder('i')
            ->where('i.status IN (:statuses)')
            ->andWhere('(i.crossBorderImpact = true OR i.nis2Category IS NOT NULL)')
            ->setParameter('statuses', ['new', 'investigating', 'contained', 'recovery'])
            ->getQuery()
            ->getResult();

        $symfonyStyle->info(sprintf('Found %d incidents requiring NIS2 reporting', count($incidents)));

        $notificationsSent = 0;

        foreach ($incidents as $incident) {
            $notificationsSent += $this->checkEarlyWarningDeadline($incident, $symfonyStyle, $dryRun);
            $notificationsSent += $this->checkDetailedNotificationDeadline($incident, $symfonyStyle, $dryRun);
            $notificationsSent += $this->checkFinalReportDeadline($incident, $symfonyStyle, $dryRun);
        }

        $this->entityManager->flush();

        if ($notificationsSent > 0) {
            $symfonyStyle->success(sprintf(
                '%s %d notification(s)',
                $dryRun ? 'Would send' : 'Sent',
                $notificationsSent
            ));
        } else {
            $symfonyStyle->info('No notifications needed at this time');
        }

        return Command::SUCCESS;
    }

    private function checkEarlyWarningDeadline(Incident $incident, SymfonyStyle $symfonyStyle, bool $dryRun): int
    {
        // Skip if already reported
        if ($incident->getEarlyWarningReportedAt() instanceof DateTimeImmutable) {
            return 0;
        }

        $hoursRemaining = $incident->getHoursUntilEarlyWarningDeadline();

        foreach (self::EARLY_WARNING_THRESHOLDS as $threshold) {
            if ($hoursRemaining <= $threshold && $hoursRemaining > 0) {
                $message = $this->translator->trans('nis2.notification.early_warning', [
                    '%incident%' => $incident->getTitle(),
                    '%hours%' => $hoursRemaining,
                    '%deadline%' => $incident->getEarlyWarningDeadline()->format('d.m.Y H:i'),
                ]);

                if (!$dryRun) {
                    $this->sendNotification($incident, 'Early Warning Deadline', $message, 'critical');
                }

                $symfonyStyle->warning(sprintf(
                    '[Early Warning] Incident #%d "%s" - %d hours remaining',
                    $incident->getId(),
                    $incident->getTitle(),
                    $hoursRemaining
                ));

                return 1;
            }
        }

        // Check if overdue
        if ($incident->isEarlyWarningOverdue()) {
            $message = $this->translator->trans('nis2.notification.early_warning_overdue', [
                '%incident%' => $incident->getTitle(),
                '%deadline%' => $incident->getEarlyWarningDeadline()->format('d.m.Y H:i'),
            ]);

            if (!$dryRun) {
                $this->sendNotification($incident, 'Early Warning OVERDUE', $message, 'critical');
            }

            $symfonyStyle->error(sprintf(
                '[OVERDUE] Incident #%d "%s" - Early Warning deadline missed!',
                $incident->getId(),
                $incident->getTitle()
            ));

            return 1;
        }

        return 0;
    }

    private function checkDetailedNotificationDeadline(Incident $incident, SymfonyStyle $symfonyStyle, bool $dryRun): int
    {
        // Skip if already reported
        if ($incident->getDetailedNotificationReportedAt() instanceof DateTimeImmutable) {
            return 0;
        }

        $hoursRemaining = $incident->getHoursUntilDetailedNotificationDeadline();

        foreach (self::DETAILED_NOTIFICATION_THRESHOLDS as $threshold) {
            if ($hoursRemaining <= $threshold && $hoursRemaining > 0) {
                $message = $this->translator->trans('nis2.notification.detailed_notification', [
                    '%incident%' => $incident->getTitle(),
                    '%hours%' => $hoursRemaining,
                    '%deadline%' => $incident->getDetailedNotificationDeadline()->format('d.m.Y H:i'),
                ]);

                if (!$dryRun) {
                    $this->sendNotification($incident, 'Detailed Notification Deadline', $message, 'high');
                }

                $symfonyStyle->warning(sprintf(
                    '[Detailed Notification] Incident #%d "%s" - %d hours remaining',
                    $incident->getId(),
                    $incident->getTitle(),
                    $hoursRemaining
                ));

                return 1;
            }
        }

        // Check if overdue
        if ($incident->isDetailedNotificationOverdue()) {
            $message = $this->translator->trans('nis2.notification.detailed_notification_overdue', [
                '%incident%' => $incident->getTitle(),
                '%deadline%' => $incident->getDetailedNotificationDeadline()->format('d.m.Y H:i'),
            ]);

            if (!$dryRun) {
                $this->sendNotification($incident, 'Detailed Notification OVERDUE', $message, 'critical');
            }

            $symfonyStyle->error(sprintf(
                '[OVERDUE] Incident #%d "%s" - Detailed Notification deadline missed!',
                $incident->getId(),
                $incident->getTitle()
            ));

            return 1;
        }

        return 0;
    }

    private function checkFinalReportDeadline(Incident $incident, SymfonyStyle $symfonyStyle, bool $dryRun): int
    {
        // Skip if already reported
        if ($incident->getFinalReportSubmittedAt() instanceof DateTimeImmutable) {
            return 0;
        }

        $hoursRemaining = $incident->getHoursUntilFinalReportDeadline();

        foreach (self::FINAL_REPORT_THRESHOLDS as $threshold) {
            if ($hoursRemaining <= $threshold && $hoursRemaining > 0) {
                $daysRemaining = ceil($hoursRemaining / 24);

                $message = $this->translator->trans('nis2.notification.final_report', [
                    '%incident%' => $incident->getTitle(),
                    '%days%' => $daysRemaining,
                    '%deadline%' => $incident->getFinalReportDeadline()->format('d.m.Y H:i'),
                ]);

                if (!$dryRun) {
                    $this->sendNotification($incident, 'Final Report Deadline', $message, 'medium');
                }

                $symfonyStyle->warning(sprintf(
                    '[Final Report] Incident #%d "%s" - %d days remaining',
                    $incident->getId(),
                    $incident->getTitle(),
                    $daysRemaining
                ));

                return 1;
            }
        }

        // Check if overdue
        if ($incident->isFinalReportOverdue()) {
            $message = $this->translator->trans('nis2.notification.final_report_overdue', [
                '%incident%' => $incident->getTitle(),
                '%deadline%' => $incident->getFinalReportDeadline()->format('d.m.Y H:i'),
            ]);

            if (!$dryRun) {
                $this->sendNotification($incident, 'Final Report OVERDUE', $message, 'critical');
            }

            $symfonyStyle->error(sprintf(
                '[OVERDUE] Incident #%d "%s" - Final Report deadline missed!',
                $incident->getId(),
                $incident->getTitle()
            ));

            return 1;
        }

        return 0;
    }

    private function sendNotification(Incident $incident, string $subject, string $message, string $priority): void
    {
        try {
            // Send to incident owner and CISO/admins
            $recipients = [];

            if ($incident->getReportedBy()) {
                $recipients[] = $incident->getReportedBy()->getEmail();
            }

            // TODO: Add logic to fetch CISO/admin emails from User repository

            foreach ($recipients as $recipient) {
                $this->emailNotificationService->sendEmail(
                    to: $recipient,
                    subject: sprintf('[NIS2 Alert] %s', $subject),
                    template: 'email/nis2_deadline_alert.html.twig',
                    context: [
                        'incident' => $incident,
                        'message' => $message,
                        'priority' => $priority,
                    ]
                );
            }

            $this->logger->info('NIS2 notification sent', [
                'incident_id' => $incident->getId(),
                'subject' => $subject,
                'priority' => $priority,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to send NIS2 notification', [
                'incident_id' => $incident->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
