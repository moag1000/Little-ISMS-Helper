<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Service\Compliance\SubRequirementResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seed the FINE-grained sub-requirement catalogue rows referenced by the
 * sub-level decomposition crosswalks (fixtures/library/decompositions/decomp_*.json).
 *
 * Each fixture file is a JSON array of:
 *   {source, target, relationship, rationale, [legal_basis]}
 * where the filename encodes the source/target framework
 * (decomp_<source>_<target>.json). Both the source AND target IDs are finer
 * than the coarse catalogue rows seeded by Load*RequirementsCommand, so we
 * create a ComplianceRequirement with requirementType='sub_requirement' for
 * each distinct ID on each side, hung under its coarse parent.
 *
 * Tenant scope: ComplianceRequirement is a GLOBAL catalogue entity (no
 * tenant_id; only the optional TISAX-BYO uploadTenant which we leave NULL).
 * We therefore seed globally exactly like the existing Load*Requirements
 * commands — no per-tenant duplication.
 *
 * Parent-derivation rule (see SubRequirementResolver::deriveParentId):
 *   1. Compute the coarse parent by stripping the finest trailing segment
 *      (".b", "(a)", ".1", "-slug").
 *   2. Resolve that coarse parent against the EXISTING catalogue (candidate +
 *      LIKE-prefix fallback).
 *   3. If it cannot be resolved, create a minimal core-type parent STUB for the
 *      coarse ID so parentRequirement is ALWAYS set (hierarchy integrity).
 *   Exactly one parent level is materialised; we never build deep chains.
 *
 * LED carve-out: rows whose legal_basis = "LED-2016/680-NOT-GDPR" (BDSG Teil 3,
 * the German implementation of the Law-Enforcement Directive) are NOT GDPR
 * mappings. We still seed the BDSG sub-requirement but the companion
 * ImportSubMappingsCommand skips the cross-mapping. The seeder counts these.
 */
#[AsCommand(
    name: 'app:seed-sub-requirements',
    description: 'Seed sub_requirement catalogue rows from the decomposition crosswalk fixtures',
)]
final class SeedSubRequirementsCommand extends Command
{
    private const LED_LEGAL_BASIS = 'LED-2016/680-NOT-GDPR';

    /**
     * Filename-token → ComplianceFramework.code. Keyed by full basename (without
     * the .json) because two tokens collide on a naive underscore split
     * (decomp_nis2_nis2umsucg).
     *
     * @var array<string, array{source: string, target: string}>
     */
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
        private readonly SubRequirementResolver $resolver,
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
        // Per-run cache of requirements created/resolved so we don't double-create
        // within one file before the flush.
        /** @var array<string, ComplianceRequirement> $reqCache */
        $reqCache = [];

        $grandSeeded = 0;
        $grandParents = 0;
        $grandLed = 0;
        $rows = [];

        foreach ($files as $file) {
            $base = basename($file, '.json');
            $pair = self::FRAMEWORK_PAIRS[$base] ?? null;
            if ($pair === null) {
                $io->warning(sprintf('Unknown fixture %s — no framework mapping, skipping.', $base));
                continue;
            }

            $sourceFw = $this->resolver->resolveFramework($pair['source'], $fwCache);
            $targetFw = $this->resolver->resolveFramework($pair['target'], $fwCache);
            if (!$sourceFw instanceof ComplianceFramework || !$targetFw instanceof ComplianceFramework) {
                $io->warning(sprintf(
                    '%s: framework not in DB (%s -> %s). Run the Load*Requirements commands first. Skipping.',
                    $base,
                    $pair['source'],
                    $pair['target'],
                ));
                continue;
            }

            $entries = $this->readEntries($file, $io);
            if ($entries === null) {
                continue;
            }

            $seededThis = 0;
            $parentsThis = 0;
            $ledThis = 0;

            // Distinct IDs per side; a single ID may appear in many rows.
            /** @var array<string, true> $seenSource */
            $seenSource = [];
            /** @var array<string, true> $seenTarget */
            $seenTarget = [];

            foreach ($entries as $entry) {
                $src = (string) ($entry['source'] ?? '');
                $tgt = (string) ($entry['target'] ?? '');
                $rationale = (string) ($entry['rationale'] ?? '');
                $legalBasis = (string) ($entry['legal_basis'] ?? '');

                if (($legalBasis === self::LED_LEGAL_BASIS) && !$this->isPlaceholder($src) && !isset($seenSource[$src])) {
                    // LED row: still seed the BDSG sub-req, but flag the count.
                    $ledThis++;
                }

                if (!$this->isPlaceholder($src) && !isset($seenSource[$src])) {
                    $seenSource[$src] = true;
                    [$created, $parentCreated] = $this->ensureSubRequirement(
                        $sourceFw,
                        $src,
                        $rationale,
                        $reqCache,
                        $dryRun,
                    );
                    $seededThis += $created;
                    $parentsThis += $parentCreated;
                }

                // LED rows carry target="n/a" (no GDPR counterpart by design).
                // Never materialise a placeholder requirement on the target side.
                if (!$this->isPlaceholder($tgt) && !isset($seenTarget[$tgt])) {
                    $seenTarget[$tgt] = true;
                    [$created, $parentCreated] = $this->ensureSubRequirement(
                        $targetFw,
                        $tgt,
                        $rationale,
                        $reqCache,
                        $dryRun,
                    );
                    $seededThis += $created;
                    $parentsThis += $parentCreated;
                }
            }

            if (!$dryRun) {
                $this->em->flush();
            }

            $rows[] = [
                $base,
                sprintf('%s→%s', $pair['source'], $pair['target']),
                (string) $seededThis,
                (string) $parentsThis,
                (string) $ledThis,
            ];

            $grandSeeded += $seededThis;
            $grandParents += $parentsThis;
            $grandLed += $ledThis;
        }

        $io->section('Sub-requirement seeding summary');
        $io->table(
            ['Fixture', 'Frameworks', 'Sub-reqs seeded', 'Parent stubs', 'LED rows'],
            $rows,
        );
        $io->writeln(sprintf(
            'Total: <info>%d</info> sub-requirements, <comment>%d</comment> parent stubs created, <comment>%d</comment> LED rows flagged.',
            $grandSeeded,
            $grandParents,
            $grandLed,
        ));

        if ($dryRun) {
            $io->note('Dry-run: no DB writes performed.');
        } else {
            $io->success('Sub-requirement catalogue seeded (idempotent).');
        }

        return Command::SUCCESS;
    }

    /**
     * Idempotently ensure a sub_requirement row exists for $rawId, with its
     * coarse parent linked (resolved or stub-created).
     *
     * @param array<string, ComplianceRequirement> $reqCache
     *
     * @return array{0:int, 1:int} [subReqsCreated, parentStubsCreated]
     */
    private function ensureSubRequirement(
        ComplianceFramework $framework,
        string $rawId,
        string $rationale,
        array &$reqCache,
        bool $dryRun,
    ): array {
        $compactId = $this->resolver->compactId($rawId);
        $cacheKey = ($framework->getCode() ?? '') . '::' . $compactId;

        if (isset($reqCache[$cacheKey])) {
            return [0, 0];
        }

        $existing = $this->em->getRepository(ComplianceRequirement::class)->findOneBy([
            'framework' => $framework,
            'requirementId' => $compactId,
        ]);
        if ($existing instanceof ComplianceRequirement) {
            $reqCache[$cacheKey] = $existing;

            return [0, 0];
        }

        // Resolve / create the coarse parent first (hierarchy integrity).
        [$parent, $parentStub] = $this->resolveOrCreateParent(
            $framework,
            $compactId,
            $rawId,
            $reqCache,
            $dryRun,
        );

        $sub = new ComplianceRequirement();
        $sub->setFramework($framework)
            ->setRequirementId($compactId)
            ->setRequirementType('sub_requirement')
            ->setTitle($this->resolver->deriveTitle($rawId, $rationale))
            ->setDescription(sprintf(
                'Sub-requirement decomposed from %s/%s. %s',
                $framework->getCode(),
                $rawId,
                $rationale !== '' ? $rationale : 'Seeded via app:seed-sub-requirements.',
            ))
            ->setCategory($parent->getCategory())
            ->setPriority($parent->getPriority() ?? 'medium')
            ->setParentRequirement($parent);

        if (!$dryRun) {
            $this->em->persist($sub);
        }
        $reqCache[$cacheKey] = $sub;

        return [1, $parentStub];
    }

    /**
     * Resolve the coarse parent for a compact sub-ID, creating a minimal
     * core-type stub if none exists. ALWAYS returns a parent.
     *
     * @param array<string, ComplianceRequirement> $reqCache
     *
     * @return array{0:ComplianceRequirement, 1:int} [parent, stubCreatedCount]
     */
    private function resolveOrCreateParent(
        ComplianceFramework $framework,
        string $compactId,
        string $rawId,
        array &$reqCache,
        bool $dryRun,
    ): array {
        $parentId = $this->resolver->deriveParentId($compactId);

        // Coarsest level reached: anchor on a framework-root stub so the sub-req
        // still has a parent. Use a stable synthetic root id.
        if ($parentId === null || $parentId === $compactId) {
            $parentId = $this->rootStubId($framework);
        }

        $parentCacheKey = ($framework->getCode() ?? '') . '::' . $parentId;
        if (isset($reqCache[$parentCacheKey])) {
            return [$reqCache[$parentCacheKey], 0];
        }

        $existing = $this->resolver->findExistingParent($framework, $parentId);
        if ($existing instanceof ComplianceRequirement) {
            $reqCache[$parentCacheKey] = $existing;

            return [$existing, 0];
        }

        // Also probe the DB directly for an already-created stub with this exact id
        // (covers re-runs where the stub was persisted in a previous invocation).
        $directStub = $this->em->getRepository(ComplianceRequirement::class)->findOneBy([
            'framework' => $framework,
            'requirementId' => $parentId,
        ]);
        if ($directStub instanceof ComplianceRequirement) {
            $reqCache[$parentCacheKey] = $directStub;

            return [$directStub, 0];
        }

        $stub = new ComplianceRequirement();
        $stub->setFramework($framework)
            ->setRequirementId($parentId)
            ->setRequirementType('core')
            ->setTitle(sprintf('%s %s', $framework->getCode(), $parentId))
            ->setDescription(sprintf(
                'Coarse parent stub for sub-requirement decomposition (%s/%s). '
                . 'Auto-created by app:seed-sub-requirements; replace with the full '
                . 'catalogue text when the dedicated loader covers this clause.',
                $framework->getCode(),
                $parentId,
            ))
            ->setPriority('medium');

        if (!$dryRun) {
            $this->em->persist($stub);
        }
        $reqCache[$parentCacheKey] = $stub;

        return [$stub, 1];
    }

    /**
     * Synthetic framework-root parent id (≤50 chars) for sub-reqs whose ID has
     * no coarser segment to strip.
     */
    private function rootStubId(ComplianceFramework $framework): string
    {
        return mb_substr('ROOT-' . ($framework->getCode() ?? 'FW'), 0, 50);
    }

    /**
     * A decomposition cell that does NOT denote a real requirement: empty, the
     * "n/a" sentinel used on LED rows' target side, or an UNVERIFIED placeholder.
     * Such cells must never be materialised as ComplianceRequirement rows.
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
