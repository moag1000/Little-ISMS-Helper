<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceMappingRepository;
use App\Service\AuditLogger;
use App\Service\MappingQualityScoreService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill the Mapping-Quality-Score (MQS) for existing ComplianceMappings that
 * carry authoritative metadata (provenanceUrl + an explicit confidence) but have
 * no qualityScore yet.
 *
 * Motivation: the ~7000 sub-level decomposition mappings imported before MQS was
 * wired into the importer have rich metadata but a NULL qualityScore. Without an
 * MQS they were counted as "waiting for analysis" and flooded the slow
 * text-similarity backlog — the wrong tool for metadata-rich rows. This command
 * computes their MQS from the real metadata so they are honestly scored without
 * ever running the heuristic.
 *
 * Idempotent: only touches rows where qualityScore IS NULL (already-scored rows
 * are skipped). Audit-logged via AuditLogger::logBulk (ISO 27001 7.5.3).
 *
 * ComplianceMapping is a global library entity (no tenant_id), so this command
 * is not tenant-scoped — it operates on the shared crosswalk library.
 */
#[AsCommand(
    name: 'app:backfill-mqs',
    description: 'Compute the Mapping-Quality-Score for metadata-rich mappings that lack one',
)]
final class BackfillMappingQualityScoreCommand extends Command
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly MappingQualityScoreService $mqsService,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report eligible rows only, no DB writes');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $eligible = $this->mappingRepository->countMqsBackfillCandidates();
        if ($eligible === 0) {
            $io->success('No mappings require an MQS backfill — all metadata-rich rows are already scored.');

            return Command::SUCCESS;
        }

        $io->note(sprintf('%d mapping(s) eligible for MQS backfill (provenance + confidence, no qualityScore).', $eligible));

        if ($dryRun) {
            $sample = $this->mappingRepository->findMqsBackfillCandidates(10);
            $rows = [];
            foreach ($sample as $m) {
                $result = $this->mqsService->compute($m);
                $rows[] = [
                    (string) $m->getId(),
                    $m->getConfidence(),
                    $m->getLifecycleState(),
                    (string) $result['mqs'],
                ];
                // Discard the in-memory mutation: do not flush in dry-run.
                $this->em->detach($m);
            }
            $io->table(['ID', 'Confidence', 'Lifecycle', 'MQS (computed)'], $rows);
            $io->note('Dry-run: no DB writes performed. (Showing up to 10 sample computations.)');

            return Command::SUCCESS;
        }

        $scored = 0;
        $offset = 0;
        /** @var list<array<string, mixed>> $perEntity */
        $perEntity = [];

        while (true) {
            $batch = $this->mappingRepository->findMqsBackfillCandidates(self::BATCH_SIZE);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $mapping) {
                $result = $this->mqsService->compute($mapping);
                $perEntity[] = [
                    'action' => 'update',
                    'entity_id' => $mapping->getId(),
                    'new_values' => [
                        'quality_score' => $result['mqs'],
                        'mqs_breakdown' => $result['breakdown'],
                        'confidence' => $mapping->getConfidence(),
                        'lifecycle_state' => $mapping->getLifecycleState(),
                    ],
                ];
                $scored++;
            }

            // Flush + clear per batch to keep memory bounded. The filter excludes
            // just-scored rows (qualityScore is now set), so the next page is
            // always fresh candidates — no offset advance needed (and clearing
            // would invalidate it anyway).
            $this->em->flush();
            $this->em->clear();

            $offset += count($batch);
            if ($offset >= $eligible + self::BATCH_SIZE) {
                // Safety valve against an unexpected non-shrinking result set.
                break;
            }
        }

        if ($perEntity !== []) {
            $this->auditLogger->logBulk(
                'mqs_backfill',
                'ComplianceMapping',
                ['scored' => $scored],
                $perEntity,
                sprintf('Backfilled MQS for %d metadata-rich mappings', $scored),
            );
        }

        $io->success(sprintf('Computed MQS for %d mapping(s).', $scored));

        return Command::SUCCESS;
    }
}
