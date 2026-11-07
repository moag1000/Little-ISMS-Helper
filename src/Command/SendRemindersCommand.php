<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Reminder\ObligationReminderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The one reminder command (replaces the nine single reminder commands).
 * Runs every 15 minutes from the scheduler: urgent notices for statutory hour
 * deadlines on every run, the daily digest at each organisation's digest hour.
 */
#[AsCommand(
    name: 'app:send-reminders',
    description: 'Send deadline reminders: urgent notices for hour deadlines, daily digest at the organisation hour',
    help: <<<'TXT'
The <info>%command.name%</info> command reminds every person of the duties on
their My Day. Who gets a duty is decided by the record (owner, assignee, deputy),
then the role, then the admins.

  • Urgent notice: GDPR Art. 33 (72 h), NIS-2 Art. 23 (24/72 h) — once per deadline.
  • Daily digest: at the organisation's digest hour (Administration → Organisation
    settings, default 07:00); people switch it off in their profile.

The scheduler runs it every 15 minutes; no own cron line is needed.

  <info>php bin/console %command.name% --dry-run</info>
  <info>php bin/console %command.name% --digest-now</info>   send today's digest now
TXT
)]
final class SendRemindersCommand
{
    public function __construct(private readonly ObligationReminderService $reminders)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Count what would be sent without sending', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Send the digest now, regardless of the organisation hour (once per day)', name: 'digest-now')]
        bool $digestNow = false,
    ): int {
        $urgent = $this->reminders->sendUrgent(dryRun: $dryRun);
        $digests = $this->reminders->sendDigests(force: $digestNow, dryRun: $dryRun);

        $io->success(sprintf(
            '%s %d urgent notice(s) and %d digest(s).',
            $dryRun ? 'Would send' : 'Sent',
            $urgent,
            $digests,
        ));

        return Command::SUCCESS;
    }
}
