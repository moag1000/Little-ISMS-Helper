<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SampleDataImport;
use App\Repository\TenantRepository;
use App\Service\DataImportService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Removes every entity that DataImportService tracked as a sample-import
 * across all sample-keys for the given tenant — clean slate before a
 * fresh re-import. Useful when partial / failed earlier imports leave
 * orphan rows the UI can no longer surface (idempotency check skips
 * them, "Entfernen" button hidden because count badge stays at 0).
 *
 * Usage:
 *   php bin/console app:sample-data:purge                # default tenant
 *   php bin/console app:sample-data:purge --tenant-id=1
 *   php bin/console app:sample-data:purge --dry-run
 */
#[AsCommand(
    name: 'app:sample-data:purge',
    description: 'Remove every entity tracked by DataImportService for one tenant.',
)]
final class SampleDataPurgeCommand
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly DataImportService $importer,
        private readonly ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Tenant ID to purge for (defaults to first tenant in DB)', name: 'tenant-id')]
        ?int $tenantId = null,
        #[Option(description: 'Show what would be deleted without actually deleting', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $tenant = $tenantId !== null
            ? $this->tenantRepository->find($tenantId)
            : ($this->tenantRepository->findOneBy(['code' => 'default']) ?? $this->tenantRepository->findOneBy([]));
        if ($tenant === null) {
            $io->error('No tenant found.');
            return Command::FAILURE;
        }
        $io->writeln(sprintf('<info>Tenant: %s (id=%d)%s</info>',
            $tenant->getName() ?? '?', $tenant->getId(),
            $dryRun ? ' — DRY-RUN' : ''));

        $repo = $this->entityManager->getRepository(SampleDataImport::class);
        $tracks = $repo->findBy(['tenant' => $tenant]);
        if ($tracks === []) {
            $io->success('No tracked sample-imports — nothing to purge.');
            return Command::SUCCESS;
        }

        // Group per sample-key so we can call removeSampleData() — same code
        // path the UI's "Entfernen" button hits, so any per-entity error
        // surfaces consistently.
        // Reverse-index order (23 → 0) so dependent samples come down first:
        // BCPlan (15) and BCExercise (16) reference BusinessProcess (2),
        // tracking-write child rows reference parent IDs in earlier samples.
        $byKey = [];
        foreach ($tracks as $t) {
            $byKey[$t->getSampleKey()][] = $t;
        }
        krsort($byKey, SORT_NATURAL);

        $io->writeln(sprintf('Found %d tracking rows across %d sample-keys.', count($tracks), count($byKey)));
        foreach ($byKey as $key => $rows) {
            $io->writeln(sprintf('  sample[%s]: %d rows', $key, count($rows)));
        }

        if ($dryRun) {
            $io->note('Dry-run — nothing deleted. Re-run without --dry-run to apply.');
            return Command::SUCCESS;
        }

        if (!$io->confirm(sprintf('Delete all %d tracked entities + tracking rows?', count($tracks)), false)) {
            $io->writeln('<comment>Aborted.</comment>');
            return Command::SUCCESS;
        }

        $totalRemoved = 0;
        $allErrors = [];
        foreach ($byKey as $key => $rows) {
            $result = $this->importer->removeSampleData((string) $key, $tenant);
            $totalRemoved += $result['removed'] ?? 0;
            $errors = $result['errors'] ?? [];
            $io->writeln(sprintf('  sample[%s]: removed=%d errors=%d',
                $key, $result['removed'] ?? 0, count($errors)));
            foreach ($errors as $err) {
                $io->writeln('    <error>' . $err . '</error>');
                $allErrors[] = $err;
            }
        }

        // Retry-pass: parse failed (Class#Id) tuples from error messages, try
        // again now that all dependent samples are processed. Catches the
        // "BCPlan referencing BP" case where sample 15 partially failed in
        // the first pass.
        $retryRemoved = 0;
        $persistentFailures = [];
        if ($allErrors !== []) {
            $io->writeln('');
            $io->writeln('<comment>Retry-pass for FK-blocked entities …</comment>');
            $retryItems = [];
            foreach ($allErrors as $err) {
                if (preg_match('/^(App\\\\Entity\\\\\w+)#(\d+):/', $err, $m)) {
                    $retryItems[$m[1] . '#' . $m[2]] = ['class' => $m[1], 'id' => (int) $m[2]];
                }
            }
            foreach ($retryItems as $item) {
                $entityRemoved = $this->tryRemoveWithCascade($item['class'], $item['id'], $io);
                if ($entityRemoved) {
                    $retryRemoved++;
                } else {
                    $persistentFailures[] = sprintf('%s#%d: still blocked after cascade-attempt', $item['class'], $item['id']);
                }
            }
            $io->writeln(sprintf('  Retry-pass: removed=%d, still-failing=%d', $retryRemoved, count($persistentFailures)));
            foreach ($persistentFailures as $err) {
                $io->writeln('    <error>' . $err . '</error>');
            }
        }

        $totalErrors = count($persistentFailures);
        $totalRemoved += $retryRemoved;
        if ($persistentFailures !== []) {
            $io->writeln('');
            $io->warning(sprintf('%d entities still blocked by FKs from rows outside sample-tracking. Manual cleanup may be needed:', count($persistentFailures)));
            $io->writeln('  e.g. SET FOREIGN_KEY_CHECKS=0; DELETE FROM <table> WHERE id=N; SET FOREIGN_KEY_CHECKS=1;');
        }
        $io->success(sprintf('Purge complete: %d entities removed, %d still-failing.', $totalRemoved, $totalErrors));
        return Command::SUCCESS;
    }

    /**
     * Try to remove an entity. On FK-violation, parse the offending child
     * table + column from the SQL error, raw-DELETE the blockers, retry.
     * Repeats up to 3× to chase multi-level FK chains.
     */
    private function tryRemoveWithCascade(string $entityClass, int $entityId, SymfonyStyle $io): bool
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $entity = $this->entityManager->find($entityClass, $entityId);
                if ($entity === null) {
                    return true;
                }
                $this->entityManager->remove($entity);
                $this->entityManager->flush();
                return true;
            } catch (\Throwable $e) {
                if (!$this->entityManager->isOpen() && $this->managerRegistry !== null) {
                    $this->managerRegistry->resetManager();
                    $this->entityManager = $this->managerRegistry->getManager();
                }
                // Parse "table `db`.`childtable`, CONSTRAINT … FOREIGN KEY (`col`)"
                $msg = $e->getMessage();
                if (!preg_match('/`[^`]+`\.`([a-z_]+)`,\s*CONSTRAINT[^(]*\(`([a-z_]+)`\)/i', $msg, $m)) {
                    return false;
                }
                $childTable = $m[1];
                $childCol = $m[2];
                try {
                    $conn = $this->entityManager->getConnection();
                    $deleted = $conn->executeStatement(
                        sprintf('DELETE FROM %s WHERE %s = ?',
                            $conn->quoteIdentifier($childTable),
                            $conn->quoteIdentifier($childCol)
                        ),
                        [$entityId]
                    );
                    $io->writeln(sprintf('    <comment>orphan-purge: %d row(s) from %s.%s pointing to %s#%d</comment>',
                        $deleted, $childTable, $childCol, $entityClass, $entityId));
                } catch (\Throwable $rawErr) {
                    $io->writeln(sprintf('    <error>cascade-delete failed for %s.%s: %s</error>',
                        $childTable, $childCol, $rawErr->getMessage()));
                    return false;
                }
            }
        }
        return false;
    }
}
