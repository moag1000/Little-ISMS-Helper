<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Service\EmailNotificationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Junior-ISB-Audit C3-02 (S14 Cluster C, 2026-05-23) — Awareness recurrence
 * + reminder cron.
 *
 * ISO 27001 A.6.3 expects security-awareness training to recur on a
 * defined cadence (typically annually). The two new Training fields
 *   - {@see Training::$recurrenceMonths}      (cadence in months, NULL = one-off)
 *   - {@see Training::$lastReminderSentAt}    (timestamp, NULL = never sent)
 * drive this command. Re-fire logic:
 *
 *   trigger = (lastReminderSentAt IS NULL)
 *           OR (lastReminderSentAt + recurrenceMonths months <= now())
 *
 * On every fired training the command:
 *   1. Pulls the participant audience from TrainingParticipation
 *      (status = pending or completed) — the canonical §7.3 audience.
 *      Falls back to the legacy {@see Training::$participantUsers}
 *      transient collection when participation rows are absent.
 *   2. Sends a single reminder email per recipient via
 *      {@see EmailNotificationService::sendGenericNotification()}.
 *   3. Updates `lastReminderSentAt` to now() so the next cron pass
 *      respects the cadence.
 *
 * Designed to run daily under cron:
 *
 *   # /etc/cron.d/training-reminders
 *   0 8 * * *  www-data  cd /var/www/isms && php bin/console app:training-send-reminders
 */
#[AsCommand(
    name: 'app:training-send-reminders',
    description: 'Send recurrence reminders for trainings whose cadence has elapsed (ISO 27001 A.6.3)',
    help: <<<'TXT'
The <info>%command.name%</info> command fires recurrence reminders for trainings.

A training is eligible when:
  • `recurrenceMonths` is set (NULL disables the cadence)
  • `lastReminderSentAt + recurrenceMonths` is in the past — or NULL

For each eligible training the command:
  1. Resolves the participant audience from TrainingParticipation rows.
  2. Sends a reminder email per recipient.
  3. Updates `lastReminderSentAt` to now().

<info>Examples:</info>

  # Preview without sending or updating
  <info>php bin/console %command.name% --dry-run</info>

  # Limit to a single training (debug)
  <info>php bin/console %command.name% --training=42</info>

<info>Recommended Cron:</info>
  <comment>0 8 * * *  cd /var/www/isms && php bin/console %command.name%</comment>
TXT
)]
final class TrainingSendRemindersCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        private readonly ?EmailNotificationService $emailNotifier = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(name: 'dry-run', description: 'Preview only, no emails sent and no timestamps updated')]
        bool $dryRun = false,
        #[Option(name: 'training', description: 'Limit to a single training ID (debug)')]
        ?int $trainingId = null,
    ): int {
        $now = new DateTimeImmutable('now');

        $qb = $this->entityManager->getRepository(Training::class)
            ->createQueryBuilder('t')
            ->where('t.recurrenceMonths IS NOT NULL')
            ->andWhere('t.recurrenceMonths >= 1');

        if ($trainingId !== null) {
            $qb->andWhere('t.id = :tid')->setParameter('tid', $trainingId);
        }

        /** @var list<Training> $trainings */
        $trainings = $qb->getQuery()->getResult();

        if ($trainings === []) {
            $io->success('No trainings with recurrence configured — nothing to do.');
            return Command::SUCCESS;
        }

        $io->title('Training Recurrence Reminder Cron (ISO 27001 A.6.3)');

        $fired = 0;
        $skipped = 0;
        $totalRecipients = 0;
        $rows = [];

        foreach ($trainings as $training) {
            $months = $training->getRecurrenceMonths();
            $lastSent = $training->getLastReminderSentAt();
            if ($months === null || $months < 1) {
                $skipped++;
                continue;
            }

            $dueAt = $this->dueAt($lastSent, $months);
            if ($dueAt > $now) {
                $skipped++;
                $rows[] = [
                    $training->getId(),
                    $training->getTitle() ?? '—',
                    $months,
                    $lastSent?->format('Y-m-d') ?? 'never',
                    'pending (' . $dueAt->format('Y-m-d') . ')',
                ];
                continue;
            }

            $recipients = $this->resolveAudience($training);
            $recipientCount = count($recipients);
            $totalRecipients += $recipientCount;

            if (!$dryRun) {
                $this->sendReminders($training, $recipients);
                $training->setLastReminderSentAt($now);
                $this->entityManager->persist($training);
            }
            $fired++;
            $rows[] = [
                $training->getId(),
                $training->getTitle() ?? '—',
                $months,
                $lastSent?->format('Y-m-d') ?? 'never',
                ($dryRun ? 'WOULD FIRE → ' : 'FIRED → ') . $recipientCount,
            ];
        }

        if (!$dryRun && $fired > 0) {
            $this->entityManager->flush();
        }

        $io->table(
            ['Training ID', 'Title', 'Cadence (months)', 'Last sent', 'Action'],
            $rows
        );

        $io->note(sprintf(
            '%d training(s) fired, %d skipped, %d total recipients%s.',
            $fired,
            $skipped,
            $totalRecipients,
            $dryRun ? ' (dry-run — no emails sent)' : ''
        ));

        if ($fired > 0) {
            $this->logger->info('Training reminder cron run', [
                'fired' => $fired,
                'recipients' => $totalRecipients,
                'dry_run' => $dryRun,
            ]);
        }

        return Command::SUCCESS;
    }

    /**
     * Compute the next due-date for a training reminder.
     * NULL `lastSent` → epoch-equivalent (fires immediately).
     */
    private function dueAt(?\DateTimeInterface $lastSent, int $months): DateTimeImmutable
    {
        if ($lastSent === null) {
            return new DateTimeImmutable('@0');
        }
        $immutable = $lastSent instanceof DateTimeImmutable
            ? $lastSent
            : DateTimeImmutable::createFromInterface($lastSent);
        return $immutable->modify(sprintf('+%d months', $months));
    }

    /**
     * Audience resolution:
     *   1. TrainingParticipation rows (canonical §7.3 audit-trail).
     *   2. Fall-back: transient {@see Training::$participantUsers}.
     *
     * Pending + completed users both receive reminders (completed users
     * are reminded of the next round; the recurrence is the whole point).
     *
     * @return list<\App\Entity\User>
     */
    private function resolveAudience(Training $training): array
    {
        $participationRepo = $this->entityManager->getRepository(TrainingParticipation::class);
        $rows = $participationRepo->findBy(['training' => $training]);

        $users = [];
        foreach ($rows as $row) {
            /** @var TrainingParticipation $row */
            $user = $row->getUser();
            if ($user !== null && $user->getEmail() !== null && $user->getEmail() !== '') {
                $users[$user->getId()] = $user;
            }
        }

        if ($users === []) {
            // Fallback to transient collection (typically only populated
            // during form-submit; absent on reloaded entities).
            foreach ($training->getParticipantUsers() as $user) {
                if ($user->getEmail() !== null && $user->getEmail() !== '') {
                    $users[$user->getId()] = $user;
                }
            }
        }

        return array_values($users);
    }

    /**
     * @param list<\App\Entity\User> $recipients
     */
    private function sendReminders(Training $training, array $recipients): void
    {
        if ($this->emailNotifier === null || $recipients === []) {
            return;
        }
        try {
            $this->emailNotifier->sendGenericNotification(
                sprintf(
                    'Reminder: %s',
                    (string) ($training->getTitle() ?? 'Awareness training')
                ),
                'emails/training_due_notification.html.twig',
                [
                    'training' => $training,
                ],
                $recipients,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Training reminder send failed', [
                'training_id' => $training->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
