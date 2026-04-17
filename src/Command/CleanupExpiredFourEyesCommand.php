<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\FourEyesApprovalService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Nightly cron: marks pending four-eyes approval requests as expired after TTL.
 * Suggested schedule: hourly or once per day at 02:00.
 */
#[AsCommand(
    name: 'app:four-eyes:cleanup-expired',
    description: 'Mark expired four-eyes approval requests as expired',
)]
final class CleanupExpiredFourEyesCommand extends Command
{
    public function __construct(private readonly FourEyesApprovalService $service)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = $this->service->cleanupExpired();
        $io->success(sprintf('Marked %d expired four-eyes approval request(s).', $count));
        return Command::SUCCESS;
    }
}
