<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Control;
use App\Repository\ControlRepository;
use App\Service\Notification\Event\DomainEventNotifier;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Emits the one genuinely time-driven notification event — control.overdue —
 * which has no Doctrine change-set to hang off (a control becomes overdue by the
 * passage of time, not by a save).
 *
 * Idempotency without a marker column: only controls whose nextReviewDate fell
 * into the past within the last --lookback-hours window are considered "newly
 * overdue", so a daily run fires each control once when it crosses its review
 * date. Default lookback 25h gives a daily cron an hour of jitter tolerance.
 *
 * Cron: run daily, e.g. `php bin/console app:notify-overdue-controls`.
 */
#[AsCommand(
    name: 'app:notify-overdue-controls',
    description: 'Fire control.overdue notifications for controls that just passed their review date.',
)]
final class DispatchOverdueControlNotificationsCommand extends Command
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly DomainEventNotifier $notifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('lookback-hours', null, InputOption::VALUE_REQUIRED, 'Window of "just became overdue" to consider.', '25')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would fire without dispatching.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lookback = max(1, (int) $input->getOption('lookback-hours'));
        $dryRun = (bool) $input->getOption('dry-run');

        $now = new DateTimeImmutable();
        $from = $now->modify(sprintf('-%d hours', $lookback));

        $controls = $this->controlRepository->findBecameOverdueBetween($from, $now);

        $fired = 0;
        foreach ($controls as $control) {
            $tenant = $control->getTenant();
            if ($tenant === null) {
                continue;
            }

            $io->writeln(sprintf(
                ' - Control #%d (%s) review due %s',
                (int) $control->getId(),
                (string) $control->getControlId(),
                $control->getNextReviewDate()?->format('Y-m-d') ?? '?',
            ));

            if (!$dryRun) {
                $this->notifier->fire('control.overdue', $tenant, [
                    'id' => $control->getId(),
                    'status' => $control->getImplementationStatus(),
                    'evidenceOutdated' => $control->isEvidenceOutdated(),
                ]);
            }
            ++$fired;
        }

        $io->success(sprintf(
            '%s %d control.overdue event(s) for controls newly overdue in the last %dh.',
            $dryRun ? 'Would fire' : 'Fired',
            $fired,
            $lookback,
        ));

        return Command::SUCCESS;
    }
}
