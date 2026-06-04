<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ConsentRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off backfill that reconciles the two historically-divergent consent
 * withdrawal paths (GDPR Art. 7(3), audit finding M-1):
 *
 *   - the revoke() action set isRevoked/revokedAt/revocationMethod only,
 *   - the (now removed) edit form set withdrawnAt/withdrawalChannel/reason only.
 *
 * Either path could leave a consent half-withdrawn. Going forward
 * Consent::recordWithdrawal() sets both groups atomically; this command repairs
 * the legacy rows where exactly one group was set.
 *
 * Usage:
 *   php bin/console app:consent:sync-withdrawal --dry-run
 *   php bin/console app:consent:sync-withdrawal
 */
#[AsCommand(
    name: 'app:consent:sync-withdrawal',
    description: 'Reconcile legacy consent rows where only one of the revocation/withdrawal field groups was set (GDPR Art. 7(3), M-1)',
)]
class ConsentWithdrawalSyncCommand
{
    public function __construct(
        private readonly ConsentRepository $consentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(
        #[Option(description: 'Show what would change without writing', name: 'dry-run')]
        bool $dryRun = false,
        ?SymfonyStyle $symfonyStyle = null,
    ): int {
        $symfonyStyle->title('Consent withdrawal reconciliation (M-1)');

        $fixed = 0;
        $rows = [];

        foreach ($this->consentRepository->findAll() as $consent) {
            $revoked = $consent->isRevoked();
            $withdrawn = $consent->getWithdrawnAt() !== null;

            // Consistent (both set or neither) → nothing to do.
            if ($revoked === $withdrawn) {
                continue;
            }

            if ($revoked && !$withdrawn) {
                // revoke() path only → mirror into the withdrawal group.
                $consent->setWithdrawnAt($consent->getRevokedAt());
                if ($consent->getWithdrawalChannel() === null) {
                    $consent->setWithdrawalChannel($consent->getRevocationMethod());
                }
                $direction = 'revocation→withdrawal';
            } else {
                // form path only → mirror into the revocation group. Status is
                // left to the lifecycle (a repair script must not bypass it);
                // setting isRevoked + the isValid() withdrawnAt check already
                // make the consent correctly count as invalid.
                $consent->setIsRevoked(true);
                if ($consent->getRevokedAt() === null) {
                    $consent->setRevokedAt($consent->getWithdrawnAt());
                }
                if ($consent->getRevocationMethod() === null) {
                    $consent->setRevocationMethod($consent->getWithdrawalChannel());
                }
                $direction = 'withdrawal→revocation';
            }

            ++$fixed;
            $rows[] = [$consent->getId(), $direction, $consent->getRevokedAt()?->format('Y-m-d') ?? '-'];
        }

        if ($fixed === 0) {
            $symfonyStyle->success('All consent rows are already consistent.');
            return Command::SUCCESS;
        }

        $symfonyStyle->table(['Consent ID', 'Repaired', 'Withdrawn at'], $rows);

        if ($dryRun) {
            $symfonyStyle->note(sprintf('DRY RUN: %d inconsistent consent row(s) would be repaired. Re-run without --dry-run to apply.', $fixed));
            return Command::SUCCESS;
        }

        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'consent_withdrawal_sync',
            entityType: 'Consent',
            entityId: null,
            newValues: ['repaired_count' => $fixed],
            description: sprintf('Reconciled %d legacy consent row(s) with divergent withdrawal/revocation state (M-1)', $fixed),
            userName: 'system:consent:sync-withdrawal',
        );

        $symfonyStyle->success(sprintf('Repaired %d inconsistent consent row(s).', $fixed));
        return Command::SUCCESS;
    }
}
