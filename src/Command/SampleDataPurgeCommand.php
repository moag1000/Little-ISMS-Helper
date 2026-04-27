<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\SampleDataImport;
use App\Repository\TenantRepository;
use App\Service\DataImportService;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly DataImportService $importer,
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
        $byKey = [];
        foreach ($tracks as $t) {
            $byKey[$t->getSampleKey()][] = $t;
        }

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
        $totalErrors = 0;
        foreach ($byKey as $key => $rows) {
            $result = $this->importer->removeSampleData((string) $key, $tenant);
            $totalRemoved += $result['removed'] ?? 0;
            $totalErrors += count($result['errors'] ?? []);
            $io->writeln(sprintf('  sample[%s]: removed=%d errors=%d',
                $key, $result['removed'] ?? 0, count($result['errors'] ?? [])));
            foreach ($result['errors'] ?? [] as $err) {
                $io->writeln('    <error>' . $err . '</error>');
            }
        }

        $io->success(sprintf('Purge complete: %d entities removed, %d errors.', $totalRemoved, $totalErrors));
        return Command::SUCCESS;
    }
}
