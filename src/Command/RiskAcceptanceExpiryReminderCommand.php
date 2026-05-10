<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Risk;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * V3 W2-M8 / WS-2 — Risk-Acceptance-Expiry Reminder Cron.
 *
 * Scans risks with `acceptanceExpiryDate` <= today + N days, logs hits and
 * (when available) emits notifications via the standard logger.
 * Designed to run daily under cron / scheduler:
 *
 *   php bin/console app:risk:acceptance-expiry-reminder --days=30
 *
 * ISO 27001 Cl. 9.3 — Mgmt-Review needs visibility on expiring risk
 * acceptances. Without this command, expirations remain theoretical.
 */
#[AsCommand(
    name: 'app:risk:acceptance-expiry-reminder',
    description: 'Surface risks whose acceptance expires within N days (default 30)',
)]
class RiskAcceptanceExpiryReminderCommand
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(name: 'days', description: 'Days-ahead window to flag (default 30)')] int $days = 30,
        #[Option(name: 'dry-run', description: 'Preview only, no logging')] bool $dryRun = false,
    ): int {
        if ($days < 1 || $days > 365) {
            $io->error('--days must be between 1 and 365.');
            return Command::FAILURE;
        }

        $cutoff = new \DateTimeImmutable(sprintf('+%d days', $days));
        $today = new \DateTimeImmutable('today');

        $qb = $this->entityManager->getRepository(Risk::class)
            ->createQueryBuilder('r')
            ->where('r.acceptanceExpiryDate IS NOT NULL')
            ->andWhere('r.acceptanceExpiryDate <= :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->orderBy('r.acceptanceExpiryDate', 'ASC');

        $risks = $qb->getQuery()->getResult();

        if ($risks === []) {
            $io->success(sprintf('No risk acceptances expiring within %d days.', $days));
            return Command::SUCCESS;
        }

        $expired = 0;
        $expiring = 0;
        $rows = [];
        foreach ($risks as $risk) {
            /** @var Risk $risk */
            $expiry = $risk->getAcceptanceExpiryDate();
            if ($expiry === null) {
                continue;
            }
            $expiryImmutable = $expiry instanceof \DateTimeImmutable
                ? $expiry
                : \DateTimeImmutable::createFromMutable($expiry instanceof \DateTime ? $expiry : new \DateTime($expiry->format('c')));

            $isExpired = $expiryImmutable < $today;
            if ($isExpired) {
                $expired++;
            } else {
                $expiring++;
            }
            $rows[] = [
                $risk->getId(),
                $risk->getTitle(),
                $expiryImmutable->format('Y-m-d'),
                $isExpired ? 'EXPIRED' : 'expiring',
                $risk->getTenant()?->getId() ?? '—',
            ];
        }

        $io->table(['Risk ID', 'Title', 'Expiry', 'Status', 'Tenant'], $rows);
        $io->note(sprintf('Found %d expiring + %d expired risk acceptances.', $expiring, $expired));

        if (!$dryRun && ($expired + $expiring) > 0) {
            $this->logger->info('Risk-acceptance-expiry reminder run', [
                'window_days' => $days,
                'expired_count' => $expired,
                'expiring_count' => $expiring,
            ]);
        }

        return Command::SUCCESS;
    }
}
