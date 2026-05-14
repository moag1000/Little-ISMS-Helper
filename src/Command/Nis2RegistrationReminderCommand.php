<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\Authority\Nis2RegistrationProfileRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * F29 — NIS-2 BSI-Portal Registration Reminder Cron Command.
 *
 * Scans all Nis2RegistrationProfile rows for upcoming and overdue
 * yearly re-registration deadlines and emits structured log events
 * for the monitoring / alerting pipeline.
 *
 * Recommended cron: daily (once per day is sufficient for yearly reminders).
 *
 *   php bin/console app:nis2-registration-reminder
 *   php bin/console app:nis2-registration-reminder --due-within=60
 *   php bin/console app:nis2-registration-reminder --dry-run
 *
 * Log events emitted:
 *  - notification.nis2.registration.due_soon  (warning)  — within --due-within days
 *  - nis2.registration.overdue                (critical) — past nextDueAt
 */
#[AsCommand(
    name: 'app:nis2-registration-reminder',
    description: 'Check NIS-2 BSI-Portal registration deadlines and emit due-soon / overdue reminders (run daily via cron).',
)]
class Nis2RegistrationReminderCommand
{
    public function __construct(
        private readonly Nis2RegistrationProfileRepository $profileRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(name: 'due-within', description: 'Warning window in days (default 30)')] int $dueWithin = 30,
        #[Option(name: 'dry-run', description: 'Preview only — no log events emitted')] bool $dryRun = false,
    ): int {
        $io->title('NIS-2 Registration Reminder');

        if ($dryRun) {
            $io->note('Dry-run mode — no events will be emitted.');
        }

        // ── Overdue ──────────────────────────────────────────────────────────
        $overdue = $this->profileRepository->findOverdue();
        $io->section(sprintf('Overdue registrations (%d)', count($overdue)));

        foreach ($overdue as $profile) {
            $tenantName = $profile->getTenant()->getName() ?? 'unknown';
            $daysOverdue = (int) (new \DateTimeImmutable())->diff($profile->getNextDueAt())->days;

            $io->writeln(sprintf(
                '  [CRITICAL] Tenant "%s" — overdue by %d day(s) (deadline: %s)',
                $tenantName,
                $daysOverdue,
                $profile->getNextDueAt()->format('Y-m-d')
            ));

            if (!$dryRun) {
                $this->logger->critical('nis2.registration.overdue', [
                    'event' => 'nis2.registration.overdue',
                    'severity' => 'critical',
                    'tenant_id' => $profile->getTenant()->getId(),
                    'tenant_name' => $tenantName,
                    'next_due_at' => $profile->getNextDueAt()->format('Y-m-d'),
                    'days_overdue' => $daysOverdue,
                    'profile_id' => $profile->getId(),
                ]);
            }
        }

        // ── Due Soon ─────────────────────────────────────────────────────────
        $dueSoon = $this->profileRepository->findDueWithin($dueWithin);
        $io->section(sprintf('Due within %d days (%d)', $dueWithin, count($dueSoon)));

        foreach ($dueSoon as $profile) {
            $tenantName = $profile->getTenant()->getName() ?? 'unknown';
            $daysRemaining = (int) (new \DateTimeImmutable())->diff($profile->getNextDueAt())->days;

            $io->writeln(sprintf(
                '  [WARNING] Tenant "%s" — due in %d day(s) (deadline: %s)',
                $tenantName,
                $daysRemaining,
                $profile->getNextDueAt()->format('Y-m-d')
            ));

            if (!$dryRun) {
                $this->logger->warning('notification.nis2.registration.due_soon', [
                    'event' => 'notification.nis2.registration.due_soon',
                    'severity' => 'warning',
                    'tenant_id' => $profile->getTenant()->getId(),
                    'tenant_name' => $tenantName,
                    'next_due_at' => $profile->getNextDueAt()->format('Y-m-d'),
                    'days_remaining' => $daysRemaining,
                    'profile_id' => $profile->getId(),
                ]);
            }
        }

        $io->success(sprintf(
            'Done. %d overdue, %d due within %d days.',
            count($overdue),
            count($dueSoon),
            $dueWithin
        ));

        return Command::SUCCESS;
    }
}
