<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;
use App\Service\AuditLogIntegrityService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-sign existing audit-log rows after a signing-payload change (e.g. new
 * columns added to AuditLog::getSigningPayload()). Idempotent — running it
 * multiple times produces the same result.
 *
 * Used once after the Version20260418190000 migration adds `actor_role` to
 * the signing payload; existing rows then carry a NULL actor_role in their
 * HMAC, which matches what AuditLogIntegrityService::verify() computes.
 */
#[AsCommand(
    name: 'app:audit-log:resign',
    description: 'Re-sign audit-log rows after a signing-payload change (idempotent)',
)]
final class ReSignAuditLogCommand
{
    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly AuditLogIntegrityService $integrity,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(name: 'after', description: 'Only re-sign rows with id greater than this', shortcut: 'a')]
        int $after = 0,
        #[Option(name: 'dry-run', description: 'Compute new HMACs without persisting')]
        bool $dryRun = false,
        #[Option(name: 'batch-size', description: 'Flush every N rows to keep memory bounded', shortcut: 'b')]
        int $batchSize = 500,
    ): int {
        $qb = $this->repository->createQueryBuilder('a')
            ->where('a.id > :after')
            ->setParameter('after', $after)
            ->orderBy('a.id', \SortDirection::Ascending);

        $count = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $io->title(sprintf('Re-signing %d audit-log rows%s', $count, $dryRun ? ' (dry-run)' : ''));

        // Anchor previousHmac per chain (one chain per organisation, B3): when
        // --after > 0 each chain continues from its newest row with id <= $after,
        // otherwise every chain starts fresh (anchor null). ISB MINOR finding —
        // sign() would pull the chain's newest row, which is the terminal row,
        // not the predecessor of the row being re-signed.
        /** @var array<string, ?string> $previousByChain */
        $previousByChain = [];

        $iter = $qb->getQuery()->toIterable();
        $processed = 0;
        $changed = 0;
        foreach ($iter as $row) {
            /** @var AuditLog $row */
            $tenantId = $row->getTenant()?->getId();
            $chain = $tenantId === null ? 'instance' : 'tenant-' . $tenantId;
            if (!array_key_exists($chain, $previousByChain)) {
                $previousByChain[$chain] = $after > 0 ? $this->integrity->latestHmac($tenantId, $after) : null;
            }
            $oldHmac = $row->getHmac();
            $this->integrity->signWithPrevious($row, $previousByChain[$chain]);
            if ($oldHmac !== $row->getHmac()) {
                $changed++;
            }
            $previousByChain[$chain] = $row->getHmac() ?? $previousByChain[$chain];
            $processed++;
            if ($processed % $batchSize === 0) {
                if (!$dryRun) {
                    $this->entityManager->flush();
                }
                // The previous HMACs are plain strings, so clear() keeps memory flat.
                $this->entityManager->clear();
                $io->writeln(sprintf('  … processed %d', $processed));
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%s %d rows (%d HMACs changed, %d untouched)',
            $dryRun ? 'Would re-sign' : 'Re-signed',
            $processed,
            $changed,
            $processed - $changed,
        ));

        return Command::SUCCESS;
    }
}
