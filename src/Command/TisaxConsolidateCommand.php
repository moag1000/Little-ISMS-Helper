<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * TISAX framework consolidation migration (spec §4.3, §9.2).
 *
 * Consolidates the two parallel TISAX frameworks into the single canonical
 * `TISAX` framework with the official VDA-ISA `1.1.1` requirement-id scheme.
 *
 * Behaviour contract (spec §4.3 + §5 + §9.2):
 *  - DRY-RUN BY DEFAULT. Nothing is written unless `--force` is passed.
 *  - A per-tenant summary is printed BEFORE any write.
 *  - All TISAX requirement rows are snapshotted to var/backups/ (timestamped)
 *    BEFORE any write — pre-migration rollback safety.
 *  - tenant_upload rows under the legacy code `TISAX-VDA-ISA-6` are migrated to
 *    the canonical `TISAX` framework: matched by crosswalk-normalised control id,
 *    moving the full assessment (maturityCurrent/Target, dataSourceMapping incl.
 *    implementation / referenceDocumentation / maturityRaw / iso27001 + tisax_*
 *    tiers, uploadTenant, maturityReviewedAt). No canonical row → re-home the row
 *    (reassign framework + normalise id).
 *  - Crosswalk-unmapped legacy ids are NEVER dropped — they are parked under
 *    category `legacy_unmapped` and exported to a per-tenant CSV (no-silent-cap).
 *  - Every change is audited via AuditLogger::logBulk with old_values/new_values,
 *    the crosswalk version and a reason string (spec §9.2).
 *  - Seed junk (requirement_source='system' ISA-KAP-* chapter stubs and
 *    superseded `ISA x.y.z` stubs) is deleted ONLY when it carries no tenant
 *    assessment.
 *  - The old framework is RETIRED (active=0 + lifecycle superseded + successor →
 *    canonical), never hard-deleted (FK safety, spec §5).
 *  - Idempotent + safe to re-run; a no-op for tenants that never used TISAX.
 *
 * No Doctrine schema migration is required: this is a pure DATA migration over
 * existing columns (framework_id, requirement_id, maturity*, data_source_mapping,
 * upload_tenant_id, active, successor_id, lifecycle_state). See report.
 */
#[AsCommand(
    name: 'app:tisax:consolidate',
    description: 'Consolidate the two TISAX frameworks into the canonical TISAX framework (dry-run by default, audited).',
)]
final class TisaxConsolidateCommand extends Command
{
    /** Canonical framework code — the side the rest of the app already reads (spec Option A). */
    private const CANONICAL_CODE = 'TISAX';

    /** Legacy BYO-import framework code to be retired. */
    private const LEGACY_CODE = 'TISAX-VDA-ISA-6';

    /** Crosswalk fixture (authoritative legacy-id → canonical-id reference). */
    private const CROSSWALK_PATH = 'fixtures/library/mappings/tisax-legacy-id-crosswalk.yaml';

    /** Where legacy ids with no confirmed canonical target are parked. */
    private const LEGACY_UNMAPPED_CATEGORY = 'legacy_unmapped';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
        private readonly \App\Service\Tisax\TisaxFulfillmentSync $fulfillmentSync,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Actually write the changes. Without this flag the command is a dry-run (default).',
            )
            ->addOption(
                'tenant',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit the migration to a single tenant id (omit to process all tenants).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $tenantFilter = $input->getOption('tenant');
        $tenantFilter = $tenantFilter !== null ? (int) $tenantFilter : null;

        $io->title('TISAX framework consolidation' . ($force ? ' (FORCE — writing)' : ' (dry-run)'));

        // ── Load the crosswalk ─────────────────────────────────────────────
        $crosswalk = $this->loadCrosswalk($io);
        if ($crosswalk === null) {
            return Command::FAILURE;
        }
        $crosswalkVersion = (string) ($crosswalk['version'] ?? 'unknown');
        $confirmedTargets = $this->buildConfirmedTargetMap($crosswalk);
        $io->writeln(sprintf(
            'Crosswalk version <info>%s</info> (ISA %s) — %d confirmed target(s), %d entr(ies) need human review.',
            $crosswalkVersion,
            (string) ($crosswalk['appliesToIsaVersion'] ?? '?'),
            count($confirmedTargets),
            $this->countNeedsReview($crosswalk),
        ));

        // ── Resolve frameworks ─────────────────────────────────────────────
        $repo = $this->em->getRepository(ComplianceFramework::class);
        $canonical = $repo->findOneBy(['code' => self::CANONICAL_CODE]);
        $legacy = $repo->findOneBy(['code' => self::LEGACY_CODE]);

        if ($canonical === null && $legacy === null) {
            $io->success('Neither TISAX framework exists — nothing to consolidate (no-op).');
            return Command::SUCCESS;
        }

        if ($canonical === null) {
            // Edge case: only the legacy framework exists. Re-home it wholesale by
            // promoting it to the canonical code rather than fabricating a new row.
            $io->warning(sprintf(
                'Canonical "%s" framework not found; legacy "%s" (id %d) will be promoted to canonical.',
                self::CANONICAL_CODE,
                self::LEGACY_CODE,
                (int) $legacy->getId(),
            ));
        }

        // ── Snapshot BEFORE any write ──────────────────────────────────────
        $snapshotPath = $this->writeSnapshot($canonical, $legacy, $io);

        // ── Plan + execute per tenant ──────────────────────────────────────
        $plan = $this->buildPlan($canonical, $legacy, $confirmedTargets, $tenantFilter);

        $this->printPerTenantSummary($io, $plan);

        if (!$force) {
            $io->note('Dry-run complete — NO changes written. Re-run with --force to apply.');
            $io->writeln(sprintf('Snapshot written to: <info>%s</info>', $snapshotPath));
            return Command::SUCCESS;
        }

        // ── WRITE PATH (force) ─────────────────────────────────────────────
        $this->applyPlan($io, $plan, $canonical, $legacy, $crosswalkVersion);

        // Retire the legacy framework (never hard-delete — FK safety, spec §5).
        if ($legacy !== null && $canonical !== null && $legacy->getId() !== $canonical->getId()) {
            $this->retireLegacyFramework($legacy, $canonical, $crosswalkVersion, $io);
        }

        $this->em->flush();

        // Materialise fulfilment from maturity for every migrated tenant so the
        // consolidated assessment actually counts (coverage / SoA / inheritance).
        if ($canonical !== null) {
            foreach (array_keys($plan['per_tenant']) as $tenantId) {
                $tenant = $this->em->getRepository(Tenant::class)->find((int) $tenantId);
                if ($tenant !== null) {
                    $r = $this->fulfillmentSync->sync($canonical, $tenant);
                    $io->writeln(sprintf('Fulfilment synced for tenant %d: %d synced, %d covered.', $tenantId, $r['synced'], $r['covered']));
                }
            }
        }

        $io->success(sprintf(
            'Consolidation applied. Snapshot: %s. Crosswalk v%s.',
            $snapshotPath,
            $crosswalkVersion,
        ));
        return Command::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Crosswalk loading
    // ──────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function loadCrosswalk(SymfonyStyle $io): ?array
    {
        $path = $this->projectDir . '/' . self::CROSSWALK_PATH;
        if (!is_file($path)) {
            $io->error(sprintf('Crosswalk fixture not found at %s', $path));
            return null;
        }
        try {
            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($path);
            return $data;
        } catch (\Throwable $e) {
            $io->error('Failed to parse crosswalk: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Build a map legacy_id → confirmed canonical target id.
     *
     * Only entries that carry an explicit `target` AND are NOT flagged
     * `needs_human_review` are treated as authoritative (no-silent-cap rule:
     * unconfirmed ids must NOT be auto-mapped). Today the shipped fixture has
     * zero confirmed targets — all domain-prefixed/AL3 ids therefore park under
     * legacy_unmapped until a reviewer with the licensed catalogue fills them in.
     *
     * @param array<string, mixed> $crosswalk
     * @return array<string, string>  legacy_id => canonical_id
     */
    private function buildConfirmedTargetMap(array $crosswalk): array
    {
        $map = [];
        foreach ((array) ($crosswalk['domain_prefixed_entries'] ?? []) as $entry) {
            $this->collectConfirmed($entry, $map);
        }
        // The AL3 (§3/§4) entries live under the same top-level list in YAML.
        // parseFile already merged them into domain_prefixed_entries when they
        // share the key; defensively scan any other list-of-entries keys too.
        foreach ($crosswalk as $key => $value) {
            if (!is_array($value) || $key === 'domain_prefixed_entries') {
                continue;
            }
            if (array_is_list($value)) {
                foreach ($value as $entry) {
                    if (is_array($entry)) {
                        $this->collectConfirmed($entry, $map);
                    }
                }
            }
        }
        return $map;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, string> $map
     */
    private function collectConfirmed(array $entry, array &$map): void
    {
        $legacyId = $entry['legacy_id'] ?? null;
        $target = $entry['target'] ?? null;
        $status = $entry['status'] ?? null;
        if (is_string($legacyId) && is_string($target) && $target !== '' && $status !== 'needs_human_review') {
            $map[$legacyId] = $target;
        }
    }

    /** @param array<string, mixed> $crosswalk */
    private function countNeedsReview(array $crosswalk): int
    {
        $summary = $crosswalk['summary'] ?? [];
        if (is_array($summary) && isset($summary['needs_human_review'])) {
            return (int) $summary['needs_human_review'];
        }
        return 0;
    }

    // ──────────────────────────────────────────────────────────────────────
    // ID normalisation (crosswalk §1 deterministic rules + §2 confirmed targets)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Normalise a legacy requirement id to the canonical VDA-ISA `1.1.1` scheme.
     *
     * @param array<string, string> $confirmedTargets
     * @return array{kind: string, id: ?string}
     *   kind = 'canonical'      → already an official x.y.z number (id = same)
     *        | 'isa_strip'      → 'ISA x.y.z' → 'x.y.z'              (id = stripped)
     *        | 'chapter_stub'   → 'ISA-KAP-n' → delete-candidate     (id = null)
     *        | 'crosswalk'      → confirmed domain-prefix target      (id = canonical)
     *        | 'unmapped'       → no confirmed mapping → legacy_unmapped (id = original)
     */
    private function normaliseId(string $legacyId, array $confirmedTargets): array
    {
        // Already canonical official VDA-ISA number?
        if (preg_match('/^\d+\.\d+\.\d+$/', $legacyId) === 1) {
            return ['kind' => 'canonical', 'id' => $legacyId];
        }
        // 'ISA x.y.z' → strip prefix (deterministic, crosswalk §1).
        if (preg_match('/^ISA (\d+\.\d+\.\d+)$/', $legacyId, $m) === 1) {
            return ['kind' => 'isa_strip', 'id' => $m[1]];
        }
        // 'ISA-KAP-n' → chapter stub (delete-candidate, crosswalk §1).
        if (preg_match('/^ISA-KAP-\d+$/', $legacyId) === 1) {
            return ['kind' => 'chapter_stub', 'id' => null];
        }
        // Confirmed catalogue-authored crosswalk target?
        if (isset($confirmedTargets[$legacyId])) {
            return ['kind' => 'crosswalk', 'id' => $confirmedTargets[$legacyId]];
        }
        // No confirmed mapping → park, never drop (no-silent-cap).
        return ['kind' => 'unmapped', 'id' => $legacyId];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Snapshot
    // ──────────────────────────────────────────────────────────────────────

    private function writeSnapshot(?ComplianceFramework $canonical, ?ComplianceFramework $legacy, SymfonyStyle $io): string
    {
        $dir = $this->projectDir . '/var/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            $io->warning('Could not create var/backups — snapshot skipped.');
            return '(snapshot skipped — dir not writable)';
        }

        $rows = [];
        foreach ([$canonical, $legacy] as $fw) {
            if ($fw === null) {
                continue;
            }
            foreach ($this->requirementsOf($fw) as $req) {
                $rows[] = [
                    'id' => $req->getId(),
                    'framework_id' => $fw->getId(),
                    'framework_code' => $fw->getCode(),
                    'requirement_id' => $req->getRequirementId(),
                    'title' => $req->getTitle(),
                    'category' => $req->getCategory(),
                    'requirement_source' => $req->getRequirementSource(),
                    'upload_tenant_id' => $req->getUploadTenant()?->getId(),
                    'maturity_current' => $req->getMaturityCurrent(),
                    'maturity_target' => $req->getMaturityTarget(),
                    'maturity_reviewed_at' => $req->getMaturityReviewedAt()?->format('c'),
                    'assessment_value' => $req->getAssessmentValue(),
                    'assessment_state_dp' => $req->getAssessmentStateDp(),
                    'data_source_mapping' => $req->getDataSourceMapping(),
                ];
            }
        }

        $file = sprintf('%s/tisax_consolidate_snapshot_%s.json', $dir, date('Ymd_His'));
        $payload = [
            'generated_at' => date('c'),
            'canonical_framework_id' => $canonical?->getId(),
            'legacy_framework_id' => $legacy?->getId(),
            'row_count' => count($rows),
            'rows' => $rows,
        ];
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $io->writeln(sprintf('Snapshot: <info>%d</info> TISAX requirement rows → %s', count($rows), $file));
        return $file;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Planning (read-only — drives both the dry-run summary and the write path)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Build a per-tenant migration plan over the LEGACY framework's tenant_upload rows
     * plus the seed-junk deletion candidates across both frameworks.
     *
     * @param array<string, string> $confirmedTargets
     * @return array{
     *   per_tenant: array<int, array{
     *      tenant_id: int, tenant_name: string,
     *      move: list<array{req: ComplianceRequirement, canonical: ComplianceRequirement, target_id: string, kind: string}>,
     *      rehome: list<array{req: ComplianceRequirement, target_id: string, kind: string}>,
     *      unmapped: list<array{req: ComplianceRequirement, legacy_id: string}>
     *   }>,
     *   junk: list<ComplianceRequirement>,
     *   junk_kept_with_assessment: int
     * }
     */
    private function buildPlan(
        ?ComplianceFramework $canonical,
        ?ComplianceFramework $legacy,
        array $confirmedTargets,
        ?int $tenantFilter,
    ): array {
        $perTenant = [];
        $junk = [];
        $junkKept = 0;

        // Index canonical rows by normalised requirement id for fast match.
        // When TWO canonical rows normalise to the same id (e.g. numeric "1.1.1"
        // AND a superseded stub "ISA 1.1.1"), PREFER the row whose raw id is
        // already the canonical numeric form — otherwise the move can target the
        // ISA stub, which the seed-junk cleanup then deletes, losing the moved
        // assessment (data-loss bug caught by the E2E run).
        $canonicalByNormId = [];
        if ($canonical !== null) {
            foreach ($this->requirementsOf($canonical) as $req) {
                $rawId = (string) $req->getRequirementId();
                $norm = $this->normaliseId($rawId, $confirmedTargets);
                if ($norm['id'] === null) {
                    continue;
                }
                $existing = $canonicalByNormId[$norm['id']] ?? null;
                $rawIsCanonical = ($rawId === $norm['id']);
                $existingIsCanonical = $existing !== null
                    && (string) $existing->getRequirementId() === $norm['id'];
                if ($existing === null || ($rawIsCanonical && !$existingIsCanonical)) {
                    $canonicalByNormId[$norm['id']] = $req;
                }
            }
        }

        // 1. tenant_upload migration from legacy framework.
        if ($legacy !== null && ($canonical === null || $legacy->getId() !== $canonical->getId())) {
            foreach ($this->requirementsOf($legacy) as $req) {
                if ($req->getRequirementSource() !== 'tenant_upload') {
                    continue;
                }
                $tenant = $req->getUploadTenant();
                $tenantId = $tenant?->getId() ?? 0;
                if ($tenantFilter !== null && $tenantId !== $tenantFilter) {
                    continue;
                }

                $perTenant[$tenantId] ??= [
                    'tenant_id' => $tenantId,
                    'tenant_name' => $tenant?->getName() ?? '(no tenant)',
                    'move' => [],
                    'rehome' => [],
                    'unmapped' => [],
                ];

                $norm = $this->normaliseId((string) $req->getRequirementId(), $confirmedTargets);

                if ($norm['kind'] === 'unmapped') {
                    $perTenant[$tenantId]['unmapped'][] = [
                        'req' => $req,
                        'legacy_id' => (string) $req->getRequirementId(),
                    ];
                    continue;
                }
                if ($norm['kind'] === 'chapter_stub' || $norm['id'] === null) {
                    // A tenant_upload chapter stub is unexpected; park it rather than drop.
                    $perTenant[$tenantId]['unmapped'][] = [
                        'req' => $req,
                        'legacy_id' => (string) $req->getRequirementId(),
                    ];
                    continue;
                }

                $match = $canonicalByNormId[$norm['id']] ?? null;
                if ($match !== null && $match !== $req) {
                    $perTenant[$tenantId]['move'][] = [
                        'req' => $req,
                        'canonical' => $match,
                        'target_id' => $norm['id'],
                        'kind' => $norm['kind'],
                    ];
                } else {
                    $perTenant[$tenantId]['rehome'][] = [
                        'req' => $req,
                        'target_id' => $norm['id'],
                        'kind' => $norm['kind'],
                    ];
                }
            }
        }

        // Collect every canonical row that will RECEIVE a moved assessment, so the
        // seed-junk pass below never deletes a move target (defense-in-depth on top
        // of the numeric-preference above — the junk plan is computed pre-move, so
        // a move target would otherwise still look like an empty stub).
        $moveTargets = new \SplObjectStorage();
        foreach ($perTenant as $tenantPlan) {
            foreach ($tenantPlan['move'] as $moveRow) {
                $moveTargets->attach($moveRow['canonical']);
            }
        }

        // 2. Seed-junk deletion candidates across both frameworks (system rows only,
        //    no tenant assessment): ISA-KAP-* and superseded `ISA x.y.z` stubs.
        foreach ([$canonical, $legacy] as $fw) {
            if ($fw === null) {
                continue;
            }
            foreach ($this->requirementsOf($fw) as $req) {
                if ($req->getRequirementSource() !== 'system') {
                    continue;
                }
                $rid = (string) $req->getRequirementId();
                $isKap = preg_match('/^ISA-KAP-\d+$/', $rid) === 1;
                $isIsaStub = preg_match('/^ISA \d+\.\d+\.\d+$/', $rid) === 1;
                if (!$isKap && !$isIsaStub) {
                    continue;
                }
                if ($this->hasTenantAssessment($req) || $moveTargets->contains($req)) {
                    $junkKept++;
                    continue;
                }
                $junk[] = $req;
            }
        }

        return [
            'per_tenant' => $perTenant,
            'junk' => $junk,
            'junk_kept_with_assessment' => $junkKept,
        ];
    }

    /**
     * A system stub "carries a tenant assessment" if it has any maturity/DP state
     * or an uploadTenant. Such a row is NOT seed junk and is preserved.
     */
    private function hasTenantAssessment(ComplianceRequirement $req): bool
    {
        if ($req->getUploadTenant() !== null) {
            return true;
        }
        if ($req->getMaturityCurrent() !== null && $req->getMaturityCurrent() !== '') {
            return true;
        }
        if ($req->getAssessmentValue() !== null && $req->getAssessmentValue() !== '') {
            return true;
        }
        if ($req->getAssessmentStateDp() !== null && $req->getAssessmentStateDp() !== '') {
            return true;
        }
        return false;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Summary output + legacy_unmapped CSV export
    // ──────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $plan */
    private function printPerTenantSummary(SymfonyStyle $io, array $plan): void
    {
        $io->section('Per-tenant summary');

        $perTenant = $plan['per_tenant'];
        if ($perTenant === []) {
            $io->writeln('No tenant_upload TISAX rows found under the legacy framework — nothing to migrate.');
        } else {
            $rows = [];
            foreach ($perTenant as $t) {
                $rows[] = [
                    $t['tenant_id'],
                    $t['tenant_name'],
                    count($t['move']),
                    count($t['rehome']),
                    count($t['unmapped']),
                ];
                // Always write the per-tenant exportable legacy_unmapped CSV (even
                // in dry-run) so reviewers get the manual-review artifact up front.
                if ($t['unmapped'] !== []) {
                    $csv = $this->writeLegacyUnmappedCsv($t['tenant_id'], $t['unmapped']);
                    $io->writeln(sprintf(
                        '  tenant %d: %d unmapped legacy id(s) → <info>%s</info>',
                        $t['tenant_id'],
                        count($t['unmapped']),
                        $csv,
                    ));
                }
            }
            $io->table(
                ['Tenant ID', 'Tenant', 'Move→canonical', 'Re-home', 'Unmapped (parked)'],
                $rows,
            );
        }

        $io->section('Seed-junk cleanup');
        $io->writeln(sprintf(
            'Delete candidates (system stubs, no assessment): <info>%d</info>',
            count($plan['junk']),
        ));
        $io->writeln(sprintf(
            'System stubs KEPT (carry a tenant assessment): <info>%d</info>',
            $plan['junk_kept_with_assessment'],
        ));
    }

    /**
     * @param list<array{req: ComplianceRequirement, legacy_id: string}> $unmapped
     */
    private function writeLegacyUnmappedCsv(int $tenantId, array $unmapped): string
    {
        $dir = $this->projectDir . '/var/backups';
        if (!is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        $file = sprintf('%s/legacy_unmapped_%d.csv', $dir, $tenantId);
        $fh = @fopen($file, 'w');
        if ($fh === false) {
            return '(csv write failed)';
        }
        fputcsv($fh, ['requirement_db_id', 'legacy_requirement_id', 'title', 'category', 'maturity_current', 'parked_category'], ',', '"', '\\');
        foreach ($unmapped as $u) {
            $req = $u['req'];
            fputcsv($fh, [
                (string) $req->getId(),
                $u['legacy_id'],
                (string) $req->getTitle(),
                (string) $req->getCategory(),
                (string) $req->getMaturityCurrent(),
                self::LEGACY_UNMAPPED_CATEGORY,
            ], ',', '"', '\\');
        }
        fclose($fh);
        return $file;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Write path
    // ──────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $plan
     */
    private function applyPlan(
        SymfonyStyle $io,
        array $plan,
        ?ComplianceFramework $canonical,
        ?ComplianceFramework $legacy,
        string $crosswalkVersion,
    ): void {
        // Promotion edge case: only legacy exists → promote it to canonical code.
        if ($canonical === null && $legacy !== null) {
            $oldCode = $legacy->getCode();
            $legacy->setCode(self::CANONICAL_CODE);
            $legacy->setActive(true);
            $this->auditLogger->logBulk(
                'tisax.consolidate.promote_framework',
                'ComplianceFramework',
                ['crosswalk_version' => $crosswalkVersion, 'reason' => 'No canonical TISAX framework existed; legacy promoted to canonical code (spec §4.3).'],
                [[
                    'entity_id' => $legacy->getId(),
                    'action' => 'update',
                    'old_values' => ['code' => $oldCode],
                    'new_values' => ['code' => self::CANONICAL_CODE],
                ]],
                'TISAX consolidation: promote legacy framework to canonical code.',
            );
            $canonical = $legacy;
        }

        // 1. Per-tenant move / re-home / park.
        foreach ($plan['per_tenant'] as $t) {
            $perEntity = [];

            foreach ($t['move'] as $m) {
                /** @var ComplianceRequirement $src */
                $src = $m['req'];
                /** @var ComplianceRequirement $dst */
                $dst = $m['canonical'];
                $old = $this->assessmentSnapshot($dst);
                $this->moveAssessment($src, $dst);
                // Source legacy row is emptied of its assessment (moved) — mark it
                // migrated rather than deleting, so re-runs are idempotent and FK-safe.
                $src->setRequirementSource('system');
                $src->setUploadTenant(null);
                $src->setMaturityCurrent(null);
                $src->setAssessmentValue(null);
                $src->setAssessmentStateDp(null);
                $perEntity[] = [
                    'entity_id' => $dst->getId(),
                    'action' => 'update',
                    'old_values' => $old,
                    'new_values' => $this->assessmentSnapshot($dst) + ['_moved_from_requirement_id' => $src->getId(), '_target_id' => $m['target_id']],
                ];
            }

            foreach ($t['rehome'] as $r) {
                /** @var ComplianceRequirement $req */
                $req = $r['req'];
                $old = ['framework_id' => $req->getFramework()?->getId(), 'requirement_id' => $req->getRequirementId()];
                if ($canonical !== null) {
                    $req->setFramework($canonical);
                }
                $req->setRequirementId($r['target_id']);
                $req->setCategory($this->dimensionCategory($r['target_id'], $req->getCategory()));
                $perEntity[] = [
                    'entity_id' => $req->getId(),
                    'action' => 'update',
                    'old_values' => $old,
                    'new_values' => ['framework_id' => $canonical?->getId(), 'requirement_id' => $r['target_id'], '_kind' => $r['kind']],
                ];
            }

            foreach ($t['unmapped'] as $u) {
                /** @var ComplianceRequirement $req */
                $req = $u['req'];
                $old = ['framework_id' => $req->getFramework()?->getId(), 'category' => $req->getCategory(), 'requirement_id' => $req->getRequirementId()];
                // Park: re-home onto canonical framework under legacy_unmapped
                // category but KEEP the original legacy id (never dropped).
                if ($canonical !== null) {
                    $req->setFramework($canonical);
                }
                $req->setCategory(self::LEGACY_UNMAPPED_CATEGORY);
                $perEntity[] = [
                    'entity_id' => $req->getId(),
                    'action' => 'update',
                    'old_values' => $old,
                    'new_values' => ['framework_id' => $canonical?->getId(), 'category' => self::LEGACY_UNMAPPED_CATEGORY, 'requirement_id' => $req->getRequirementId()],
                ];
            }

            if ($perEntity !== []) {
                $this->auditLogger->logBulk(
                    'tisax.consolidate.migrate_tenant',
                    'ComplianceRequirement',
                    [
                        'crosswalk_version' => $crosswalkVersion,
                        'tenant_id' => $t['tenant_id'],
                        'reason' => 'TISAX consolidation: move/re-home tenant_upload assessment to canonical framework + park crosswalk-unmapped ids (spec §4.3/§9.2).',
                    ],
                    $perEntity,
                    sprintf('TISAX consolidation for tenant %d (%d rows).', $t['tenant_id'], count($perEntity)),
                );
            }
        }

        // 1b. Normalise the canonical catalogue: leaf controls → dimension
        //     category, sections → 'section', legacy ad-hoc rows → parked. Fixes
        //     the wrong assess "Bereiche" + inflated requirement count.
        $cat = $this->normaliseCanonicalCatalogue($canonical);
        $io->writeln(sprintf(
            'Catalogue normalised: <info>%d</info> leaf→dimension, %d sections, %d legacy parked.',
            $cat['leaf'], $cat['section'], $cat['parked'],
        ));

        // 2. Delete seed junk (system stubs, no assessment).
        if ($plan['junk'] !== []) {
            $perEntity = [];
            foreach ($plan['junk'] as $req) {
                $perEntity[] = [
                    'entity_id' => $req->getId(),
                    'action' => 'delete',
                    'old_values' => [
                        'framework_id' => $req->getFramework()?->getId(),
                        'requirement_id' => $req->getRequirementId(),
                        'title' => $req->getTitle(),
                        'requirement_source' => $req->getRequirementSource(),
                    ],
                    'new_values' => null,
                ];
                $this->em->remove($req);
            }
            $this->auditLogger->logBulk(
                'tisax.consolidate.delete_seed_junk',
                'ComplianceRequirement',
                [
                    'crosswalk_version' => $crosswalkVersion,
                    'reason' => 'TISAX consolidation: delete system chapter/ISA stubs carrying no tenant assessment (spec §4.3 step 4).',
                ],
                $perEntity,
                sprintf('TISAX consolidation: removed %d seed-junk stubs.', count($perEntity)),
            );
            $io->writeln(sprintf('Deleted <info>%d</info> seed-junk stubs.', count($plan['junk'])));
        }
    }

    /**
     * Derive the canonical TISAX assessment DIMENSION (used as `category`) from a
     * VDA-ISA control id. Chapter 8 = Prototype Protection, 9 = Data Protection,
     * 1-7 = Information Security. Non-numeric ids fall back to $fallback (or IS).
     */
    private function dimensionCategory(string $controlId, ?string $fallback = null): string
    {
        $domain = (int) (explode('.', $controlId)[0] ?? 0);
        return match (true) {
            $domain === 9            => 'data_protection',
            $domain === 8            => 'prototype_protection',
            $domain >= 1 && $domain <= 7 => 'information_security',
            default                  => $fallback ?? 'information_security',
        };
    }

    /**
     * Normalise the canonical framework's catalogue so the assess/coverage views
     * are clean (fixes the wrong "Bereiche" + inflated requirement count):
     *  - leaf VDA-ISA controls (N.N.N) → category = their dimension.
     *  - section headers (N.N)         → category 'section' (excluded from counts).
     *  - legacy ad-hoc rows (INF-/ACC-/ISA …, system, no tenant assessment) →
     *    parked under 'legacy_unmapped' (kept for mapping FK safety, out of the
     *    catalogue). Rows carrying a tenant assessment are never touched.
     *
     * @return array{leaf:int, section:int, parked:int}
     */
    private function normaliseCanonicalCatalogue(?ComplianceFramework $canonical): array
    {
        $leaf = 0;
        $section = 0;
        $parked = 0;
        if ($canonical === null) {
            return ['leaf' => 0, 'section' => 0, 'parked' => 0];
        }
        foreach ($this->requirementsOf($canonical) as $req) {
            $id = (string) $req->getRequirementId();
            if (preg_match('/^\d+\.\d+\.\d+/', $id) === 1) {
                $req->setCategory($this->dimensionCategory($id, $req->getCategory()));
                $leaf++;
            } elseif (preg_match('/^\d+\.\d+$/', $id) === 1) {
                $req->setCategory('section');
                $section++;
            } elseif ($req->getRequirementSource() === 'system' && !$this->hasTenantAssessment($req)) {
                // Legacy ad-hoc id (INF-/ACC-/ISA …) with no assessment — park out
                // of the catalogue (kept for the ISO-mapping FK references).
                $req->setCategory(self::LEGACY_UNMAPPED_CATEGORY);
                $parked++;
            }
        }
        return ['leaf' => $leaf, 'section' => $section, 'parked' => $parked];
    }

    /**
     * Move the full assessment from a legacy source row onto the canonical dst row.
     * Only fills empty target fields so a real canonical assessment is never
     * silently overwritten; dataSourceMapping keys are merged (source wins for the
     * import-provenance keys it owns).
     */
    private function moveAssessment(ComplianceRequirement $src, ComplianceRequirement $dst): void
    {
        if ($this->isEmpty($dst->getMaturityCurrent()) && !$this->isEmpty($src->getMaturityCurrent())) {
            $dst->setMaturityCurrent($src->getMaturityCurrent());
        }
        if ($this->isEmpty($dst->getMaturityTarget()) && !$this->isEmpty($src->getMaturityTarget())) {
            $dst->setMaturityTarget($src->getMaturityTarget());
        }
        if ($dst->getMaturityReviewedAt() === null && $src->getMaturityReviewedAt() !== null) {
            $dst->setMaturityReviewedAt($src->getMaturityReviewedAt());
        }
        if ($this->isEmpty($dst->getAssessmentValue()) && !$this->isEmpty($src->getAssessmentValue())) {
            $dst->setAssessmentValue($src->getAssessmentValue());
        }
        if ($this->isEmpty($dst->getAssessmentStateDp()) && !$this->isEmpty($src->getAssessmentStateDp())) {
            $dst->setAssessmentStateDp($src->getAssessmentStateDp());
        }
        if ($dst->getUploadTenant() === null && $src->getUploadTenant() !== null) {
            $dst->setUploadTenant($src->getUploadTenant());
        }
        $dst->setRequirementSource('tenant_upload');
        // Preserve the assessment DIMENSION as the category — otherwise the
        // canonical (dst) stub keeps its legacy/empty category and Prototype
        // Protection (8.x) / Data Protection (9.x) controls render under the
        // wrong "Bereich" on the assess page.
        $dst->setCategory($this->dimensionCategory((string) $dst->getRequirementId(), $src->getCategory()));

        // Merge dataSourceMapping: implementation / referenceDocumentation /
        // maturityRaw / iso27001 + tisax_* tiers. Source provenance keys win.
        $merged = $dst->getDataSourceMapping() ?? [];
        foreach (($src->getDataSourceMapping() ?? []) as $k => $v) {
            if (!array_key_exists($k, $merged) || $this->isEmptyScalar($merged[$k])) {
                $merged[$k] = $v;
            }
        }
        $dst->setDataSourceMapping($merged !== [] ? $merged : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function assessmentSnapshot(ComplianceRequirement $req): array
    {
        return [
            'maturity_current' => $req->getMaturityCurrent(),
            'maturity_target' => $req->getMaturityTarget(),
            'maturity_reviewed_at' => $req->getMaturityReviewedAt()?->format('c'),
            'assessment_value' => $req->getAssessmentValue(),
            'assessment_state_dp' => $req->getAssessmentStateDp(),
            'requirement_source' => $req->getRequirementSource(),
            'upload_tenant_id' => $req->getUploadTenant()?->getId(),
            'data_source_mapping' => $req->getDataSourceMapping(),
        ];
    }

    private function retireLegacyFramework(
        ComplianceFramework $legacy,
        ComplianceFramework $canonical,
        string $crosswalkVersion,
        SymfonyStyle $io,
    ): void {
        $old = [
            'active' => $legacy->isActive(),
            'lifecycle_state' => $legacy->getLifecycleState(),
            'successor_id' => $legacy->getSuccessor()?->getId(),
        ];
        $legacy->setActive(false);
        $legacy->setLifecycleState(ComplianceFramework::LIFECYCLE_SUPERSEDED);
        $legacy->setSuccessor($canonical);

        $this->auditLogger->logBulk(
            'tisax.consolidate.retire_framework',
            'ComplianceFramework',
            [
                'crosswalk_version' => $crosswalkVersion,
                'reason' => 'TISAX consolidation: retire legacy framework (active=0 + superseded + successor→canonical); never hard-deleted for FK safety (spec §4.3 step 5 / §5).',
            ],
            [[
                'entity_id' => $legacy->getId(),
                'action' => 'update',
                'old_values' => $old,
                'new_values' => [
                    'active' => false,
                    'lifecycle_state' => ComplianceFramework::LIFECYCLE_SUPERSEDED,
                    'successor_id' => $canonical->getId(),
                ],
            ]],
            sprintf('Retired legacy TISAX framework %d → successor %d.', (int) $legacy->getId(), (int) $canonical->getId()),
        );
        $io->writeln(sprintf(
            'Retired legacy framework %d (active=0, superseded, successor→%d).',
            (int) $legacy->getId(),
            (int) $canonical->getId(),
        ));
    }

    // ──────────────────────────────────────────────────────────────────────
    // Small helpers
    // ──────────────────────────────────────────────────────────────────────

    /** @return iterable<ComplianceRequirement> */
    private function requirementsOf(ComplianceFramework $fw): iterable
    {
        // Use a direct query rather than the lazy collection so re-runs see a
        // consistent set even after flush() during the same process.
        return $this->em->getRepository(ComplianceRequirement::class)
            ->findBy(['framework' => $fw]);
    }

    private function isEmpty(?string $v): bool
    {
        return $v === null || $v === '';
    }

    private function isEmptyScalar(mixed $v): bool
    {
        return $v === null || $v === '' || $v === [];
    }
}
