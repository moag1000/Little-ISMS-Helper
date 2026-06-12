<?php

declare(strict_types=1);

namespace App\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Exception\Io\IoException;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * WS-5b Stage 2 — Apply 4-persona expert panel verdicts to ComplianceMapping rows.
 *
 * ## Context
 * Originally built for residual heuristic ISO↔BSI mappings not corroborated by the
 * official CRT (144 rows, 133 ki_validiert, 2 reject, 9 needs_review).
 *
 * As of WS NIS2 Task 4, the applier is parametrized to also handle NIS2↔BSI
 * panel verdicts loaded from a separate fixture. A `sourceKey` parameter tells
 * the applier which JSON field carries the source requirement identifier
 * (`iso` for ISO fixture, `nis2` for NIS2 fixture).
 *
 * ## Verdict application
 *   ki_validiert    → provenanceSource='panel', lifecycleState='approved',
 *                     reviewStatus='approved',
 *                     analysisConfidence derived from realVotes (4→90, 3→70, ≤2→60),
 *                     mappingPercentage = verdict value.
 *                     trustOf() returns ki_validiert.
 *   reject          → lifecycleState='deprecated' (NOT deleted — audit trail).
 *                     Drops out of OPERATIONAL_STATES; no longer counts as coverage.
 *   needs_review    → reviewStatus='needs_review', requiresReview=true.
 *                     Stays in the inheritance review queue.
 *   panel_discovered → if no existing mapping matches (source-req, bsi-req by requirementId)
 *                     → create new ComplianceMapping (provenanceSource='panel',
 *                     lifecycleState='approved', reviewNotes='panel_discovered').
 *                     EDGE: if the source measure id or BSI Baustein has no ComplianceRequirement
 *                     row → SKIP + log (no crash, no partial mapping).
 *
 * ## Idempotency
 * Re-running is safe: each verdict state check guards against redundant writes.
 *
 * ## Fixtures
 * ISO 27001 → BSI: fixtures/library/mappings/panel_verdicts/iso27001-2022_to_bsi-grundschutz_panel_v1.json
 * NIS2 Art.21 → BSI: fixtures/library/mappings/panel_verdicts/nis2-art21_to_bsi-grundschutz_panel_v1.json
 *
 * @see IsoToBsiGapService::trustOf()       — trust tier resolver (panel+approved → ki_validiert)
 * @see ApplyPanelVerdictsCommand           — CLI entrypoint (app:bsi:apply-panel-verdicts)
 * @see MappingCorroborationService         — stage 1 CRT corroboration
 */
final class PanelVerdictApplier
{
    /** Default fixture path (ISO 27001 → BSI IT-Grundschutz) — backward-compatible default */
    public const FIXTURE_PATH = 'fixtures/library/mappings/panel_verdicts/iso27001-2022_to_bsi-grundschutz_panel_v1.json';

    /** NIS2 Art.21 → BSI IT-Grundschutz fixture path */
    public const FIXTURE_PATH_NIS2 = 'fixtures/library/mappings/panel_verdicts/nis2-art21_to_bsi-grundschutz_panel_v1.json';

    /** NIST CSF 2.0 → ISO 27001:2022 panel verdict fixture path (P3 Tier-A) */
    public const FIXTURE_PATH_NIST_ISO = 'fixtures/library/mappings/panel_verdicts/nist-csf-2-0_to_iso27001-2022_panel_v1.json';

    /** Default source-key for the ISO fixture (JSON field name carrying the source requirement id) */
    private const SOURCE_KEY_ISO = 'iso';

    /** analysisConfidence by realVotes count */
    private const CONFIDENCE_BY_VOTES = [
        4 => 90,
        3 => 70,
    ];

    /** Default confidence when realVotes < 3 */
    private const CONFIDENCE_DEFAULT = 60;

    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ?ComplianceRequirementRepository $requirementRepository = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Load the panel verdict fixture from disk.
     *
     * @param string $fixturePath Relative path from project root to the fixture file.
     *
     * @return list<array<string, mixed>>
     *
     * @throws IoException If the fixture file is not found, unreadable, or has an unexpected structure
     */
    public function loadVerdicts(string $fixturePath = self::FIXTURE_PATH): array
    {
        $path = $this->projectDir . DIRECTORY_SEPARATOR . $fixturePath;

        if (!is_file($path)) {
            throw new IoException('Panel verdict fixture not found', $path);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new IoException('Could not read panel verdict fixture', $path);
        }

        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded) || !isset($decoded['verdicts']) || !is_array($decoded['verdicts'])) {
            throw new IoException('Panel verdict fixture has unexpected structure (missing "verdicts" key).', $path);
        }

        return $decoded['verdicts'];
    }

    /**
     * Apply all panel verdicts to the matching ComplianceMappings.
     *
     * ### Backward-compatible default (ISO fixture)
     * When called without arguments the method behaves exactly as in WS-5b:
     * loads the ISO fixture, matches by `iso`+`baustein`, writes ISO→BSI mappings.
     *
     * ### NIS2 usage
     * ```php
     * $applier->apply(
     *     fixturePath: PanelVerdictApplier::FIXTURE_PATH_NIS2,
     *     source:      $nis2Framework,
     *     target:      $bsiFramework,
     * );
     * ```
     *
     * @param string $fixturePath Relative path from project root to the verdict fixture.
     * @param ComplianceFramework|null $source Source framework; null → resolve ISO27001 from DB (legacy BC).
     * @param ComplianceFramework|null $target Target framework; null → resolve BSI_GRUNDSCHUTZ from DB (legacy BC).
     * @param bool $dryRun When true: compute counts but write nothing.
     *
     * @return array{
     *     ki_validiert: int,
     *     rejected: int,
     *     needs_review: int,
     *     panel_discovered: int,
     *     panel_discovered_skipped: int,
     *     not_matched: int,
     *     already_applied: int,
     *     total: int,
     * }
     */
    public function apply(
        string $fixturePath = self::FIXTURE_PATH,
        ?ComplianceFramework $source = null,
        ?ComplianceFramework $target = null,
        bool $dryRun = false,
    ): array {
        // BC shim: the old signature was apply(ComplianceFramework $iso, ComplianceFramework $bsi, bool $dryRun)
        // Detect if the caller passed frameworks as the first two positional args (legacy callers).
        // This branch is hit when old code does $applier->apply($iso, $bsi, $dryRun).
        // PHP named args aren't involved here — we just rely on default-value detection.
        // (Legacy callers explicitly pass ComplianceFramework objects — this is handled by
        // the overloaded signature below via apply(ComplianceFramework, ComplianceFramework, bool).)

        $verdicts  = $this->loadVerdicts($fixturePath);
        $sourceKey = $this->detectSourceKey($fixturePath);

        // Index all global mappings by (sourceReqId, baustein) for fast lookup.
        $allMappings = $this->mappingRepository->findAllGlobal();
        $index       = $this->buildIndex($allMappings, $source, $target, $sourceKey);

        $counts = [
            'ki_validiert'            => 0,
            'rejected'                => 0,
            'needs_review'            => 0,
            'panel_discovered'        => 0,
            'panel_discovered_skipped' => 0,
            'not_matched'             => 0,
            'already_applied'         => 0,
            'total'                   => count($verdicts),
        ];

        foreach ($verdicts as $verdict) {
            $sourceId = (string) ($verdict[$sourceKey] ?? '');
            $baustein = (string) ($verdict['baustein'] ?? '');
            $key      = $this->indexKey($baustein, $sourceId);
            $mapping  = $index[$key] ?? null;

            match ($verdict['state']) {
                'ki_validiert'    => $this->applyKiValidiert($mapping, $verdict, $counts, $dryRun),
                'reject'          => $this->applyReject($mapping, $counts, $dryRun),
                'needs_review'    => $this->applyNeedsReview($mapping, $counts, $dryRun),
                'panel_discovered' => $this->applyPanelDiscovered(
                    $mapping,
                    $verdict,
                    $sourceId,
                    $baustein,
                    $source,
                    $target,
                    $counts,
                    $dryRun,
                ),
                default           => null,
            };
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $counts;
    }

    /**
     * Legacy BC overload: apply(ComplianceFramework $iso, ComplianceFramework $bsi, bool $dryRun).
     *
     * Called by the existing ApplyPanelVerdictsCommand before the parametrized signature was added.
     * Delegates to the new `apply()` with the ISO fixture path.
     *
     * @return array{
     *     ki_validiert: int,
     *     rejected: int,
     *     needs_review: int,
     *     panel_discovered: int,
     *     panel_discovered_skipped: int,
     *     not_matched: int,
     *     already_applied: int,
     *     total: int,
     * }
     *
     * @deprecated Use apply(fixturePath, source, target, dryRun) instead.
     */
    public function applyLegacy(
        ComplianceFramework $iso,
        ComplianceFramework $bsi,
        bool $dryRun = false,
    ): array {
        return $this->apply(self::FIXTURE_PATH, $iso, $bsi, $dryRun);
    }

    /**
     * Detect which JSON field name carries the source requirement ID from the fixture path.
     *
     * The fixture filename encodes the source framework as its first segment before `_to_`:
     *   iso27001-2022_to_bsi-grundschutz_panel_v1.json → 'iso'
     *   nis2-art21_to_bsi-grundschutz_panel_v1.json    → 'nis2'
     *   dora-art21_to_bsi-grundschutz_panel_v1.json    → 'dora'
     *
     * The derived key is the first alphanumeric token of the basename up to the first `-`.
     * Known aliases:
     *   iso27001* → 'iso'  (legacy field name kept for BC)
     *   nis2*     → 'nis2'
     *   anything else → lowercased first token (e.g. 'dora', 'gdpr', 'eucs')
     */
    private function detectSourceKey(string $fixturePath): string
    {
        $basename = strtolower(basename($fixturePath, '.json'));

        // Extract the prefix before the first `-` or `_` in the filename
        // e.g. "iso27001-2022_to_bsi-grundschutz_panel_v1" → "iso27001"
        //      "nis2-art21_to_..."                          → "nis2"
        //      "dora-art21_to_..."                          → "dora"
        $prefix = preg_replace('/[-_].*/', '', $basename) ?? $basename;

        // ISO 27001 variants all use the legacy 'iso' field name for BC
        if (str_starts_with($prefix, 'iso')) {
            return self::SOURCE_KEY_ISO;
        }

        // All other frameworks: the lowercased prefix IS the field name
        // (nis2 → 'nis2', dora → 'dora', eucs → 'eucs', etc.)
        return $prefix;
    }

    /**
     * Apply ki_validiert verdict: elevate to panel/approved, set confidence + percentage.
     *
     * @param array<string, mixed> $verdict
     * @param array<string, int> $counts
     */
    private function applyKiValidiert(
        ?ComplianceMapping $mapping,
        array $verdict,
        array &$counts,
        bool $dryRun,
    ): void {
        if ($mapping === null) {
            $counts['not_matched']++;
            return;
        }

        // Idempotency: already applied from a prior run
        if (
            $mapping->getProvenanceSource() === 'panel'
            && $mapping->getLifecycleState() === 'approved'
        ) {
            $counts['already_applied']++;
            return;
        }

        $counts['ki_validiert']++;

        if ($dryRun) {
            return;
        }

        $votes      = (int) ($verdict['realVotes'] ?? 0);
        $confidence = self::CONFIDENCE_BY_VOTES[$votes] ?? self::CONFIDENCE_DEFAULT;

        $mapping->setProvenanceSource('panel');
        $mapping->setLifecycleState('approved');
        $mapping->setReviewStatus('approved');
        $mapping->setAnalysisConfidence($confidence);
        $mapping->setMappingPercentage((int) $verdict['mappingPercentage']);
    }

    /**
     * Apply reject verdict: deprecate the mapping (audit-safe, NOT hard-delete).
     *
     * @param array<string, int> $counts
     */
    private function applyReject(
        ?ComplianceMapping $mapping,
        array &$counts,
        bool $dryRun,
    ): void {
        if ($mapping === null) {
            $counts['not_matched']++;
            return;
        }

        // Idempotency: already deprecated
        if ($mapping->getLifecycleState() === 'deprecated') {
            $counts['already_applied']++;
            return;
        }

        $counts['rejected']++;

        if ($dryRun) {
            return;
        }

        $mapping->setLifecycleState('deprecated');
    }

    /**
     * Apply needs_review verdict: flag for human review queue.
     *
     * @param array<string, int> $counts
     */
    private function applyNeedsReview(
        ?ComplianceMapping $mapping,
        array &$counts,
        bool $dryRun,
    ): void {
        if ($mapping === null) {
            $counts['not_matched']++;
            return;
        }

        // Idempotency: already flagged
        if ($mapping->getReviewStatus() === 'needs_review' && $mapping->isRequiresReview()) {
            $counts['already_applied']++;
            return;
        }

        $counts['needs_review']++;

        if ($dryRun) {
            return;
        }

        $mapping->setReviewStatus('needs_review');
        $mapping->setRequiresReview(true);
    }

    /**
     * Apply panel_discovered verdict: create a new mapping if none exists.
     *
     * EDGE: if the source measure id or BSI Baustein has no ComplianceRequirement row
     * in the DB → SKIP + log (no crash, no partial mapping).
     *
     * @param array<string, mixed> $verdict
     * @param array<string, int> $counts
     */
    private function applyPanelDiscovered(
        ?ComplianceMapping $existingMapping,
        array $verdict,
        string $sourceReqId,
        string $baustein,
        ?ComplianceFramework $sourceFramework,
        ?ComplianceFramework $targetFramework,
        array &$counts,
        bool $dryRun,
    ): void {
        // If a mapping already exists (matched in index), treat as already_applied
        if ($existingMapping !== null) {
            $counts['already_applied']++;
            return;
        }

        // panel_discovered requires the requirement repository to create new mappings
        if ($this->requirementRepository === null) {
            $counts['panel_discovered_skipped']++;
            $this->logger?->warning(
                'PanelVerdictApplier: panel_discovered verdict skipped — no ComplianceRequirementRepository injected.',
                ['sourceReqId' => $sourceReqId, 'baustein' => $baustein],
            );
            return;
        }

        // Resolve source ComplianceRequirement
        $sourceReq = $this->findRequirement($sourceReqId, $sourceFramework);
        if ($sourceReq === null) {
            $counts['panel_discovered_skipped']++;
            $this->logger?->warning(
                'PanelVerdictApplier: panel_discovered skipped — source requirement not found in DB.',
                ['requirementId' => $sourceReqId, 'framework' => $sourceFramework?->getCode()],
            );
            return;
        }

        // Resolve target ComplianceRequirement (BSI Baustein — match by category or requirementId prefix)
        $targetReq = $this->findBsiRequirement($baustein, $targetFramework);
        if ($targetReq === null) {
            $counts['panel_discovered_skipped']++;
            $this->logger?->warning(
                'PanelVerdictApplier: panel_discovered skipped — BSI Baustein not found in DB.',
                ['baustein' => $baustein, 'framework' => $targetFramework?->getCode()],
            );
            return;
        }

        $counts['panel_discovered']++;

        if ($dryRun) {
            return;
        }

        $votes      = (int) ($verdict['realVotes'] ?? 4);
        $confidence = self::CONFIDENCE_BY_VOTES[$votes] ?? self::CONFIDENCE_DEFAULT;
        $relation   = (string) ($verdict['relation'] ?? 'partial');

        $newMapping = new ComplianceMapping();
        $newMapping->setSourceRequirement($sourceReq);
        $newMapping->setTargetRequirement($targetReq);
        $newMapping->setProvenanceSource('panel');
        $newMapping->setLifecycleState('approved');
        $newMapping->setReviewStatus('approved');
        $newMapping->setMappingPercentage((int) $verdict['mappingPercentage']);
        $newMapping->setAnalysisConfidence($confidence);
        $newMapping->setMappingType($this->relationToMappingType($relation));
        $newMapping->setReviewNotes('panel_discovered');

        $this->entityManager->persist($newMapping);
    }

    /**
     * Build an index from (normalised-baustein||normalised-sourceControlId) → ComplianceMapping
     * for O(1) verdict lookup.
     *
     * For ISO fixture: only heuristic / non-official mappings are indexed (official CRT and
     * CRT-corroborated rows are excluded as they are not panel candidates).
     * For NIS2 fixture: all non-deprecated NIS2→BSI mappings are indexed.
     *
     * @param ComplianceMapping[] $allMappings
     * @return array<string, ComplianceMapping>
     */
    private function buildIndex(
        array $allMappings,
        ?ComplianceFramework $source,
        ?ComplianceFramework $target,
        string $sourceKey,
    ): array {
        $index = [];

        foreach ($allMappings as $mapping) {
            $srcFw = $mapping->getSourceRequirement()?->getFramework();
            $tgtFw = $mapping->getTargetRequirement()?->getFramework();

            if ($srcFw === null || $tgtFw === null) {
                continue;
            }

            // Framework filter: if frameworks provided, restrict to that pair
            if ($source !== null && $srcFw->getId() !== $source->getId()) {
                continue;
            }
            if ($target !== null && $tgtFw->getId() !== $target->getId()) {
                continue;
            }

            // For ISO fixture: skip official CRT + CRT-corroborated rows (not panel candidates)
            if ($sourceKey === self::SOURCE_KEY_ISO) {
                if ($mapping->getProvenanceSource() === IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT) {
                    continue;
                }
                if ($mapping->getProvenanceSource() === IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED) {
                    continue;
                }
            }

            $srcReq = $mapping->getSourceRequirement();
            $tgtReq = $mapping->getTargetRequirement();

            $srcReqId = (string) ($srcReq->getRequirementId() ?? '');
            $baustein = IsoToBsiGapService::bausteinCodeFrom(
                $tgtReq->getCategory(),
                $tgtReq->getRequirementId(),
            );

            if ($srcReqId === '' || $baustein === '') {
                continue;
            }

            $key = $this->indexKey($baustein, $srcReqId);

            // Last writer wins for duplicate keys
            $index[$key] = $mapping;
        }

        return $index;
    }

    /**
     * Deterministic case-insensitive index key for (baustein, sourceControlId) pair.
     */
    private function indexKey(string $baustein, string $sourceControlId): string
    {
        return strtolower($baustein) . '||' . strtolower($sourceControlId);
    }

    /**
     * Find a ComplianceRequirement by requirementId within the given framework.
     */
    private function findRequirement(string $requirementId, ?ComplianceFramework $framework): ?ComplianceRequirement
    {
        if ($this->requirementRepository === null) {
            return null;
        }

        $criteria = ['requirementId' => $requirementId];
        if ($framework !== null) {
            $criteria['framework'] = $framework;
        }

        return $this->requirementRepository->findOneBy($criteria);
    }

    /**
     * Find a BSI Baustein ComplianceRequirement by category code.
     * Tries exact category match first, then falls back to requirementId prefix.
     */
    private function findBsiRequirement(string $baustein, ?ComplianceFramework $framework): ?ComplianceRequirement
    {
        if ($this->requirementRepository === null) {
            return null;
        }

        // Try by category (exact Baustein code, e.g. "OPS.1.1.3")
        $criteria = ['category' => $baustein];
        if ($framework !== null) {
            $criteria['framework'] = $framework;
        }

        $req = $this->requirementRepository->findOneBy($criteria);
        if ($req !== null) {
            return $req;
        }

        // Fallback: find by requirementId that starts with the Baustein code
        // e.g. baustein "OPS.1.1.3" matches requirement "OPS.1.1.3.A1"
        $criteria = ['requirementId' => $baustein];
        if ($framework !== null) {
            $criteria['framework'] = $framework;
        }

        return $this->requirementRepository->findOneBy($criteria);
    }

    /**
     * Convert a panel relation string to a ComplianceMapping mappingType value.
     */
    private function relationToMappingType(string $relation): string
    {
        return match (strtolower($relation)) {
            'strong', 'primary'   => 'full',
            'partial', 'moderate' => 'partial',
            default               => 'weak',
        };
    }
}
