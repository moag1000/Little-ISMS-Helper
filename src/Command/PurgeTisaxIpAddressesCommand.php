<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\TisaxLicenseConfirmationRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * GDPR IP-address retention purge for tisax_license_confirmation.
 *
 * The `ip_address` column records the client IP at ENX-licence confirmation
 * time for ISO 27001 Clause 7.5.3 audit-trail purposes.  Under GDPR Art. 5(1)(e)
 * the data must not be kept longer than necessary.  After the retention window
 * the column is NULL-ed out (the confirmation row itself is retained as the
 * audit-trail footprint; only the personal data point is removed).
 *
 * Default retention: 365 days (configurable via `app.tisax.ip_retention_days`
 * container parameter or `--days` CLI override).
 *
 * Recommended cron (weekly, off-peak):
 *   0 2 * * 0 cd /path/to/project && php bin/console app:tisax:purge-old-ip-addresses >> /var/log/tisax-ip-purge.log 2>&1
 */
#[AsCommand(
    name: 'app:tisax:purge-old-ip-addresses',
    description: 'NULL-out ip_address on tisax_license_confirmation rows older than the retention period (GDPR Art. 5(1)(e)).',
    help: <<<'TXT'
The <info>%command.name%</info> command anonymises the IP address recorded at
TISAX ENX-licence confirmation time once the configurable retention window has
passed.

The confirmation row is <comment>not</comment> deleted — it remains as a
lightweight audit-trail footprint (user, tenant, timestamp).  Only the
personal data point (ip_address) is cleared.

<info>Examples:</info>

  # Preview which rows would be affected
  <info>php bin/console %command.name% --dry-run</info>

  # Execute with the default 365-day retention
  <info>php bin/console %command.name%</info>

  # Override retention (e.g. 90 days)
  <info>php bin/console %command.name% --days=90</info>

<info>Recommended cron (weekly, Sunday 02:00):</info>
  <comment>0 2 * * 0 cd /path && php bin/console %command.name% >> /var/log/tisax-ip-purge.log 2>&1</comment>
TXT
)]
final class PurgeTisaxIpAddressesCommand
{
    public function __construct(
        private readonly TisaxLicenseConfirmationRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $ipRetentionDays,
    ) {}

    public function __invoke(
        #[Option(description: 'Show which rows would be updated without writing any changes', name: 'dry-run')]
        bool $dryRun = false,
        #[Option(description: 'Retention window in days (overrides app.tisax.ip_retention_days)', name: 'days')]
        ?int $days = null,
        SymfonyStyle $io = new SymfonyStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput(),
        ),
    ): int {
        $retention = $days ?? $this->ipRetentionDays;

        if ($retention < 1) {
            $io->error(sprintf('Retention must be >= 1 day, %d given.', $retention));
            return Command::FAILURE;
        }

        $cutoff = new DateTimeImmutable(sprintf('-%d days', $retention));

        $io->title('TISAX IP-Address Retention Purge');
        $io->table(
            ['Parameter', 'Value'],
            [
                ['Retention Window', sprintf('%d days', $retention)],
                ['Cutoff (UTC)', $cutoff->format('Y-m-d H:i:s')],
                ['Mode', $dryRun ? 'DRY RUN (no writes)' : 'EXECUTE'],
            ],
        );

        $candidates = $this->repository->findWithIpAddressOlderThan($cutoff);
        $count      = count($candidates);

        if ($count === 0) {
            $io->success('No rows with retained IP addresses older than the cut-off — nothing to do.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Found %d row(s) eligible for IP anonymisation', $count));

        if ($io->isVerbose()) {
            $rows = [];
            foreach ($candidates as $confirmation) {
                $rows[] = [
                    $confirmation->getId(),
                    $confirmation->getTenant()?->getName() ?? 'n/a',
                    $confirmation->getUser()?->getEmail() ?? 'n/a',
                    $confirmation->getConfirmedAt()?->format('Y-m-d H:i:s') ?? 'n/a',
                ];
            }
            $io->table(['ID', 'Tenant', 'User Email', 'Confirmed At'], $rows);
        }

        if ($dryRun) {
            $io->note(sprintf(
                '%d row(s) would have their ip_address NULL-ed. Re-run without --dry-run to execute.',
                $count,
            ));
            return Command::SUCCESS;
        }

        foreach ($candidates as $confirmation) {
            $confirmation->setIpAddress('');
        }
        $this->entityManager->flush();

        $io->success(sprintf(
            'Anonymised IP address on %d confirmation row(s) confirmed before %s.',
            $count,
            $cutoff->format('Y-m-d'),
        ));

        return Command::SUCCESS;
    }
}
