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
            ->orderBy('a.id', 'ASC');

        $count = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $io->title(sprintf('Re-signing %d audit-log rows%s', $count, $dryRun ? ' (dry-run)' : ''));

        // Anchor previousHmac: when --after > 0 we need the HMAC of row#$after
        // itself so the next row links correctly to the existing chain. When
        // starting from the top, previous is null (chain anchor). ISB MINOR
        // finding — without this, sign() would pull findLatestSignedHmac()
        // which is the last row in the table, breaking the chain.
        $previousHmac = null;
        if ($after > 0) {
            $anchor = $this->repository->find($after);
            $previousHmac = $anchor?->getHmac();
        }

        $iter = $qb->getQuery()->toIterable();
        $processed = 0;
        $changed = 0;
        foreach ($iter as $row) {
            /** @var AuditLog $row */
            $oldHmac = $row->getHmac();
            $this->integrity->signWithPrevious($row, $previousHmac);
            if ($oldHmac !== $row->getHmac()) {
                $changed++;
            }
            $previousHmac = $row->getHmac() ?? $previousHmac;
            $processed++;
            if ($processed % $batchSize === 0) {
                if (!$dryRun) {
                    $this->entityManager->flush();
                }
                // NOTE: cannot clear() here — $previousHmac must stay live
                // across the boundary so the next batch links correctly.
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
