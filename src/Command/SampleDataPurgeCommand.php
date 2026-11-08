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
 *   php bin/console app:sample-data:purge --tenant-id=1
 *   php bin/console app:sample-data:purge --tenant-id=1 --dry-run
 *
 * The organisation is named explicitly (no default: purging the wrong one is
 * not undoable). Deletion goes through the EntityManager only — rows outside
 * the sample tracking that still reference a sample (the organisation's real
 * data) are reported, never deleted behind its back. Every run is audit-logged
 * in the organisation.
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
        private readonly ?\App\Doctrine\EntityManagerRecovery $entityManagerRecovery = null,
        private readonly ?\App\Service\AuditLogger $auditLogger = null,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Organisation (tenant) ID to purge — required', name: 'tenant-id')]
        ?int $tenantId = null,
        #[Option(description: 'Show what would be deleted without actually deleting', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        if ($tenantId === null) {
            $io->error('Name the organisation: --tenant-id=<id>.');
            return Command::INVALID;
        }
        $tenant = $this->tenantRepository->find($tenantId);
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
            // Only entities this organisation's tracking names — an id parsed
            // from an error message is never trusted on its own.
            $tracked = [];
            foreach ($tracks as $t) {
                $tracked[$t->getEntityClass() . '#' . $t->getEntityId()] = true;
            }
            $retryItems = [];
            foreach ($allErrors as $err) {
                if (preg_match('/^(App\\\\Entity\\\\\w+)#(\d+):/', $err, $m) && isset($tracked[$m[1] . '#' . $m[2]])) {
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

        // Final cleanup: orphan tracking-rows whose entity no longer exists.
        // Cascade-delete removed BP entities but left the tracking pointing
        // at vanished IDs — those then over-inflate sample counts.
        $orphanRows = 0;
        $allTracks = $this->entityManager->getRepository(\App\Entity\SampleDataImport::class)
            ->findBy(['tenant' => $tenant]);
        foreach ($allTracks as $t) {
            if ($this->entityManager->find($t->getEntityClass(), $t->getEntityId()) === null) {
                $this->entityManager->remove($t);
                $orphanRows++;
            }
        }
        if ($orphanRows > 0) {
            try {
                $this->entityManager->flush();
            } catch (\Throwable) {
                // Best-effort cleanup: tracking rows that could not be flushed are picked up by the next purge run.
            }
            $io->writeln(sprintf('  Cleaned %d orphan tracking-row(s) pointing at non-existent entities.', $orphanRows));
        }

        $totalErrors = count($persistentFailures);
        $totalRemoved += $retryRemoved;
        if ($persistentFailures !== []) {
            $io->writeln('');
            $io->warning(sprintf('%d entities are still referenced by records outside the sample data (listed above). Remove or relink those records in the application, then run the purge again.', count($persistentFailures)));
        }
        $this->auditLogger?->withTenant($tenant, fn () => $this->auditLogger->logCustom(
            'sample_data.purged',
            'SampleDataImport',
            null,
            null,
            ['removed' => $totalRemoved, 'still_referenced' => $totalErrors],
            sprintf('Sample data purged: %d removed, %d still referenced', $totalRemoved, $totalErrors),
            'system:sample-data:purge',
        ));
        $io->success(sprintf('Purge complete: %d entities removed, %d still-failing.', $totalRemoved, $totalErrors));
        return Command::SUCCESS;
    }

    /**
     * Try once more to remove a tracked sample entity now that the other
     * samples are gone. A row outside the sample data that still references it
     * is reported (table + column), not deleted: that row is the
     * organisation's own data and leaves only through the application.
     */
    private function tryRemoveWithCascade(string $entityClass, int $entityId, SymfonyStyle $io): bool
    {
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
                // Restores the tenant-filter state that existed before verbatim (CLI: unscoped).
                $this->entityManagerRecovery?->recover();
                $this->entityManager = $this->managerRegistry->getManager();
            }
            if (preg_match('/`[^`]+`\.`([a-z_]+)`,\s*CONSTRAINT[^(]*\(`([a-z_]+)`\)/i', $e->getMessage(), $m)) {
                $io->writeln(sprintf('    <comment>%s#%d is referenced by %s.%s</comment>', $entityClass, $entityId, $m[1], $m[2]));
            }

            return false;
        }
    }
}
