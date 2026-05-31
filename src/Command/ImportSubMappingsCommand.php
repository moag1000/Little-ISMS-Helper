<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use App\Service\AuditLogger;
use App\Service\Compliance\SubRequirementResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import the sub-level decomposition crosswalks
 * (fixtures/library/decompositions/decomp_*.json) as ComplianceMapping rows.
 *
 * MUST run AFTER app:seed-sub-requirements (the source/target requirements have
 * to exist). Both commands share SubRequirementResolver for ID canonicalisation
 * so the importer resolves exactly the rows the seeder created.
 *
 * relationship → mappingPercentage:
 *   equivalent       = 100  (full)
 *   superset         =  90  (partial)
 *   subset           =  75  (partial)
 *   partial_overlap  =  60  (partial)
 *   related          =  40  (weak)
 *
 * Imported mappings are seeded in lifecycleState='draft' (legal relevance — must
 * pass review before publication). source='subreq_decomposition_2026_05',
 * confidence='high'. The batch is recorded via AuditLogger (ISO 27001 7.5.3).
 *
 * LED carve-out: rows whose legal_basis = "LED-2016/680-NOT-GDPR" are BDSG Teil 3
 * (Law-Enforcement Directive), NOT GDPR — no cross-mapping is created (counted).
 */
#[AsCommand(
    name: 'app:import-sub-mappings',
    description: 'Import sub-level decomposition crosswalks as draft ComplianceMappings',
)]
final class ImportSubMappingsCommand extends Command
{
    private const SOURCE_TAG = 'subreq_decomposition_2026_05';
    private const LED_LEGAL_BASIS = 'LED-2016/680-NOT-GDPR';

    /** @var array<string, int> */
    private const RELATIONSHIP_PERCENT = [
        'equivalent' => 100,
        'superset' => 90,
        'subset' => 75,
        'partial_overlap' => 60,
        'related' => 40,
    ];

    /** @var array<string, array{source: string, target: string}> */
    private const FRAMEWORK_PAIRS = [
        'decomp_gdpr_iso27701' => ['source' => 'GDPR', 'target' => 'ISO27701'],
        'decomp_bdsg_gdpr' => ['source' => 'BDSG', 'target' => 'GDPR'],
        'decomp_nis2_iso27001' => ['source' => 'NIS2', 'target' => 'ISO27001'],
        'decomp_dora_iso27001' => ['source' => 'DORA', 'target' => 'ISO27001'],
        'decomp_ai-act_iso42001' => ['source' => 'EU-AI-ACT', 'target' => 'ISO42001'],
        'decomp_gdpr_iso27018' => ['source' => 'GDPR', 'target' => 'ISO27018'],
        'decomp_tisax_iso27001' => ['source' => 'TISAX', 'target' => 'ISO27001'],
        'decomp_nis2_nis2umsucg' => ['source' => 'NIS2', 'target' => 'NIS2-UmsuCG'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly SubRequirementResolver $resolver,
        private readonly AuditLogger $auditLogger,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse and report only, no DB writes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $dir = $this->projectDir . '/fixtures/library/decompositions';
        $files = glob($dir . '/decomp_*.json') ?: [];
        if ($files === []) {
            $io->warning('No decomposition fixtures found at ' . $dir);

            return Command::SUCCESS;
        }

        /** @var array<string, ComplianceFramework|null> $fwCache */
        $fwCache = [];

        $rows = [];
        $grandImported = 0;
        $grandSkipLed = 0;
        $grandSkipMissing = 0;
        $grandExisting = 0;
        $allMissing = [];
        /** @var list<array<string, mixed>> $perEntity */
        $perEntity = [];

        foreach ($files as $file) {
            $base = basename($file, '.json');
            $pair = self::FRAMEWORK_PAIRS[$base] ?? null;
            if ($pair === null) {
                $io->warning(sprintf('Unknown fixture %s — skipping.', $base));
                continue;
            }

            $sourceFw = $this->resolver->resolveFramework($pair['source'], $fwCache);
            $targetFw = $this->resolver->resolveFramework($pair['target'], $fwCache);
            if (!$sourceFw instanceof ComplianceFramework || !$targetFw instanceof ComplianceFramework) {
                $io->warning(sprintf('%s: framework not in DB (%s -> %s). Skipping.', $base, $pair['source'], $pair['target']));
                continue;
            }

            $entries = $this->readEntries($file, $io);
            if ($entries === null) {
                continue;
            }

            $importedThis = 0;
            $skipLedThis = 0;
            $skipMissingThis = 0;
            $existingThis = 0;

            foreach ($entries as $entry) {
                $src = (string) ($entry['source'] ?? '');
                $tgt = (string) ($entry['target'] ?? '');
                $relationship = (string) ($entry['relationship'] ?? '');
                $rationale = (string) ($entry['rationale'] ?? '');
                $legalBasis = (string) ($entry['legal_basis'] ?? '');

                if ($legalBasis === self::LED_LEGAL_BASIS) {
                    // BDSG Teil 3 / LED — explicitly NOT a GDPR mapping.
                    $skipLedThis++;
                    continue;
                }

                if ($this->isPlaceholder($src) || $this->isPlaceholder($tgt)) {
                    // Empty, "n/a" sentinel (non-LED), or UNVERIFIED placeholder:
                    // there is no requirement to map. Flag UNVERIFIED explicitly.
                    if (str_starts_with($src, 'UNVERIFIED-') || str_starts_with($tgt, 'UNVERIFIED-')) {
                        $allMissing[] = sprintf('%s: UNVERIFIED placeholder (%s -> %s)', $base, $src, $tgt);
                    }
                    $skipMissingThis++;
                    continue;
                }

                $sourceReq = $this->resolver->findSubRequirement($sourceFw, $src);
                $targetReq = $this->resolver->findSubRequirement($targetFw, $tgt);
                if (!$sourceReq instanceof ComplianceRequirement || !$targetReq instanceof ComplianceRequirement) {
                    $miss = [];
                    if (!$sourceReq instanceof ComplianceRequirement) {
                        $miss[] = sprintf('source=%s:%s', $sourceFw->getCode(), $src);
                    }
                    if (!$targetReq instanceof ComplianceRequirement) {
                        $miss[] = sprintf('target=%s:%s', $targetFw->getCode(), $tgt);
                    }
                    $allMissing[] = sprintf('%s: %s', $base, implode(', ', $miss));
                    $skipMissingThis++;
                    continue;
                }

                $percentage = self::RELATIONSHIP_PERCENT[$relationship] ?? 40;

                // Idempotency: one mapping per (source, target, source-tag).
                $existing = $this->mappingRepository->findOneBy([
                    'sourceRequirement' => $sourceReq,
                    'targetRequirement' => $targetReq,
                    'source' => self::SOURCE_TAG,
                ]);
                if ($existing instanceof ComplianceMapping) {
                    $existingThis++;
                    continue;
                }

                $mapping = new ComplianceMapping();
                $mapping->setSourceRequirement($sourceReq)
                    ->setTargetRequirement($targetReq)
                    ->setMappingPercentage($percentage) // also sets mappingType band
                    ->setRelationship($relationship)
                    ->setMappingRationale($rationale)
                    ->setConfidence('high')
                    ->setSource(self::SOURCE_TAG)
                    ->setLifecycleState('draft')
                    ->setRequiresReview(true)
                    ->setReviewStatus('unreviewed')
                    ->setProvenanceSource('Sub-requirement decomposition crosswalk ' . $base)
                    ->setMethodologyType('machine_assisted_with_review')
                    ->setValidFrom(new DateTimeImmutable());

                if (!$dryRun) {
                    $this->em->persist($mapping);
                }

                $perEntity[] = [
                    // 'action' omitted → AuditLogger::logBulk defaults to 'create'.
                    'new_values' => [
                        'fixture' => $base,
                        'source' => $src,
                        'target' => $tgt,
                        'relationship' => $relationship,
                        'mapping_percentage' => $percentage,
                        'lifecycle_state' => 'draft',
                    ],
                ];
                $importedThis++;
            }

            $rows[] = [
                $base,
                sprintf('%s→%s', $pair['source'], $pair['target']),
                (string) $importedThis,
                (string) $existingThis,
                (string) $skipLedThis,
                (string) $skipMissingThis,
            ];

            $grandImported += $importedThis;
            $grandExisting += $existingThis;
            $grandSkipLed += $skipLedThis;
            $grandSkipMissing += $skipMissingThis;
        }

        if (!$dryRun && $grandImported > 0) {
            $this->em->flush();

            // ISO 27001 7.5.3 — batch audit trail (1 batch + N per-entity entries).
            $this->auditLogger->logBulk(
                'sub_mapping_import',
                'ComplianceMapping',
                [
                    'source_tag' => self::SOURCE_TAG,
                    'imported' => $grandImported,
                    'skipped_led' => $grandSkipLed,
                    'skipped_missing' => $grandSkipMissing,
                ],
                $perEntity,
                sprintf('Imported %d sub-requirement decomposition mappings (draft)', $grandImported),
            );
        }

        $io->section('Sub-mapping import summary');
        $io->table(
            ['Fixture', 'Frameworks', 'Imported (draft)', 'Already present', 'Skipped (LED)', 'Skipped (missing)'],
            $rows,
        );
        $io->writeln(sprintf(
            'Total: <info>%d</info> imported, <comment>%d</comment> already present, '
            . '<comment>%d</comment> LED-skipped, <error>%d</error> missing-req-skipped.',
            $grandImported,
            $grandExisting,
            $grandSkipLed,
            $grandSkipMissing,
        ));

        if ($allMissing !== []) {
            $io->warning(sprintf('%d row(s) could not resolve a requirement:', count($allMissing)));
            foreach (array_slice($allMissing, 0, 25) as $m) {
                $io->writeln(' - ' . $m);
            }
            if (count($allMissing) > 25) {
                $io->writeln(sprintf(' … and %d more.', count($allMissing) - 25));
            }
        }

        if ($dryRun) {
            $io->note('Dry-run: no DB writes performed.');
        } else {
            $io->success('Sub-requirement mappings imported in draft lifecycle (review gate).');
        }

        return Command::SUCCESS;
    }

    /**
     * A decomposition cell that does NOT denote a real requirement: empty, the
     * "n/a" sentinel used on LED rows, or an UNVERIFIED placeholder.
     */
    private function isPlaceholder(string $id): bool
    {
        $norm = strtolower(trim($id));

        return $norm === ''
            || $norm === 'n/a'
            || $norm === 'na'
            || str_starts_with($id, 'UNVERIFIED-');
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function readEntries(string $file, SymfonyStyle $io): ?array
    {
        $raw = @file_get_contents($file);
        if ($raw === false) {
            $io->warning('Could not read ' . $file);

            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Invalid JSON in %s: %s', basename($file), $e->getMessage()));

            return null;
        }

        if (!is_array($data)) {
            $io->warning(sprintf('%s does not contain a JSON array.', basename($file)));

            return null;
        }

        /** @var list<array<string, mixed>> $list */
        $list = array_values(array_filter($data, 'is_array'));

        return $list;
    }
}
