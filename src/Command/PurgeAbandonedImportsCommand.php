<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BulkImportBatch;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Nightly (Schedule): removes the uploaded file of a bulk import that was never
 * committed — uploaded, mapped, previewed, failed or cancelled, older than
 * --days (30). Nothing was imported from it, so the file is no evidence; a
 * committed batch keeps its file as the import evidence (ISO 27001 7.5.3).
 *
 * The batch row stays (history), its source document is deleted. Paths are
 * only unlinked inside var/import-uploads. One audit batch per organisation.
 */
#[AsCommand(
    name: 'app:import:purge-abandoned',
    description: 'Delete uploaded files of bulk imports that were never committed',
)]
final class PurgeAbandonedImportsCommand extends Command
{
    private const array OPEN_STATES = [
        BulkImportBatch::STATUS_UPLOADED,
        BulkImportBatch::STATUS_MAPPED,
        BulkImportBatch::STATUS_PREVIEW,
        BulkImportBatch::STATUS_FAILED,
        BulkImportBatch::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        #[Autowire('%kernel.project_dir%/var/import-uploads')]
        private readonly string $uploadDir,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Age in days', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $cutoff = new DateTimeImmutable(sprintf('-%d days', max(1, (int) $input->getOption('days'))));

        /** @var list<BulkImportBatch> $batches */
        $batches = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(BulkImportBatch::class, 'b')
            ->where('b.status IN (:open)')
            ->andWhere('b.createdAt < :cutoff')
            ->andWhere('b.sourceDocument IS NOT NULL')
            ->setParameter('open', self::OPEN_STATES)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();

        $byTenant = [];
        $root = realpath($this->uploadDir);
        foreach ($batches as $batch) {
            $document = $batch->getSourceDocument();
            if ($document === null) {
                continue;
            }
            $path = realpath((string) $document->getFilePath());
            $io->writeln(sprintf('#%d %s (%s)', (int) $batch->getId(), $batch->getSourceFileName(), $batch->getStatus()));
            if ($dryRun) {
                continue;
            }
            if ($root !== false && $path !== false && str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                @unlink($path);
            }
            $batch->setSourceDocument(null);
            $this->entityManager->remove($document);
            $byTenant[(int) $batch->getTenant()?->getId()][] = $batch;
        }

        if ($dryRun || $byTenant === []) {
            $io->success(sprintf('%d abandoned import file(s)%s.', count($batches), $dryRun ? ' (dry run)' : ''));

            return Command::SUCCESS;
        }
        $this->entityManager->flush();

        foreach ($byTenant as $tenantBatches) {
            $this->auditLogger->withTenant($tenantBatches[0]->getTenant(), fn () => $this->auditLogger->logBulk(
                'import.file_purged',
                'BulkImportBatch',
                ['count' => count($tenantBatches), 'older_than' => $cutoff->format('Y-m-d')],
                array_map(static fn (BulkImportBatch $b): array => [
                    'action' => 'import.file_purged',
                    'entity_id' => $b->getId(),
                    'new_values' => ['source_file_name' => $b->getSourceFileName()],
                ], $tenantBatches),
                sprintf('Deleted %d upload file(s) of imports that were never committed', count($tenantBatches)),
            ));
        }
        $io->success(sprintf('Deleted %d abandoned import file(s).', count($batches)));

        return Command::SUCCESS;
    }
}
