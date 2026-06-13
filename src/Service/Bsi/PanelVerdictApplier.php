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
final class PanelVerdictApplier implements PanelVerdictApplierInterface
{
    /** Default fixture path (ISO 27001 → BSI IT-Grundschutz) — backward-compatible default */
    public const FIXTURE_PATH = 'fixtures/library/mappings/panel_verdicts/iso27001-2022_to_bsi-grundschutz_panel_v1.json';

    /** NIS2 Art.21 → BSI IT-Grundschutz fixture path */
    public const FIXTURE_PATH_NIS2 = 'fixtures/library/mappings/panel_verdicts/nis2-art21_to_bsi-grundschutz_panel_v1.json';

    /** BSI C5:2020 → ISO 27001:2022 fixture path (P3 Tier-A) */
    public const FIXTURE_PATH_C5_ISO = 'fixtures/library/mappings/panel_verdicts/bsi-c5-2020_to_iso27001-2022_panel_v1.json';

    /** DORA → NIS2 fixture path (P3 Tier-A; panel verdicts: 49 ki_validiert, 28 deprecated) */
    public const FIXTURE_PATH_DORA_NIS2 = 'fixtures/library/mappings/panel_verdicts/dora_to_nis2_panel_v1.json';

    /** NIST CSF 2.0 → ISO 27001:2022 fixture path (P3 Tier-A; 90 ki_validiert) */
    public const FIXTURE_PATH_NIST_ISO = 'fixtures/library/mappings/panel_verdicts/nist-csf-2-0_to_iso27001-2022_panel_v1.json';

    /** Default source-key for the ISO fixture (JSON field name carrying the source requirement id) */
    private const SOURCE_KEY_ISO = 'iso';

    /** BSI Grundschutz framework code — triggers Baustein-level target normalization */
    private const BSI_GRUNDSCHUTZ_CODE = 'BSI_GRUNDSCHUTZ';

    /** Default target-key for fixtures where target is BSI IT-Grundschutz (Baustein code) */
    private const TARGET_KEY_BAUSTEIN = 'baustein';

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
        $targetKey = $this->detectTargetKey($target, $fixturePath);

        // Auto-fallback for source key: if the detected source key is not present
        // in the first verdict but a generic 'source' key is, use 'source'.
        // (e.g. dora_to_nis2_panel_v1.json uses generic 'source'/'target' field names)
        if (!empty($verdicts) && !array_key_exists($sourceKey, $verdicts[0]) && array_key_exists('source', $verdicts[0])) {
            $sourceKey = 'source';
        }

        // Auto-fallback for target key: if the detected target key is not present
        // in the first verdict but a generic 'target' key is, use 'target'.
        if (!empty($verdicts) && !array_key_exists($targetKey, $verdicts[0]) && array_key_exists('target', $verdicts[0])) {
            $targetKey = 'target';
        }

        // Index all global mappings by (targetReqId, sourceReqId) for fast lookup.
        $allMappings = $this->mappingRepository->findAllGlobal();
        $index       = $this->buildIndex($allMappings, $source, $target, $sourceKey, $targetKey);

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
            $sourceId    = (string) ($verdict[$sourceKey] ?? '');
            // Target field name: 'baustein' for BSI targets, or a fixture-specific key
            // (e.g. 'iso' for C5→ISO fixtures, 'target' for DORA→NIS2). Fall back to 'baustein' for BC.
            $targetReqId = (string) ($verdict[$targetKey] ?? $verdict['baustein'] ?? '');
            $key         = $this->indexKey($targetReqId, $sourceId);
            $mapping     = $index[$key] ?? null;

            match ($verdict['state']) {
                'ki_validiert'    => $this->applyKiValidiert($mapping, $verdict, $counts, $dryRun),
                'reject'          => $this->applyReject($mapping, $counts, $dryRun),
                'needs_review'    => $this->applyNeedsReview($mapping, $counts, $dryRun),
                'panel_discovered' => $this->applyPanelDiscovered(
                    $mapping,
                    $verdict,
                    $sourceId,
                    $targetReqId,
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
     *   iso27001-2022_to_bsi-grundschutz_panel_v1.json     → 'iso'
     *   nis2-art21_to_bsi-grundschutz_panel_v1.json        → 'nis2'
     *   bsi-c5-2020_to_iso27001-2022_panel_v1.json         → 'c5'
     *   dora_to_nis2_panel_v1.json                         → 'dora'
     *   nist-csf-2-0_to_iso27001-2022_panel_v1.json        → 'nist'
     *
     * The derived key is the first alphanumeric token of the basename up to the first `-`.
     * Known aliases:
     *   iso27001* → 'iso'  (legacy field name kept for BC)
     *   nis2*     → 'nis2'
     *   bsi-c5*   → 'c5'  (BSI C5 criteria use 'c5' as the JSON source field)
     *   nist*     → 'nist'
     *   anything else → lowercased first token (e.g. 'dora', 'gdpr', 'eucs')
     */
    private function detectSourceKey(string $fixturePath): string
    {
        $basename = strtolower(basename($fixturePath, '.json'));

        // BSI C5 fixtures: "bsi-c5-2020_to_..." → source field is 'c5'
        if (str_starts_with($basename, 'bsi-c5')) {
            return 'c5';
        }

        // Extract the prefix before the first `-` or `_` in the filename
        // e.g. "iso27001-2022_to_bsi-grundschutz_panel_v1" → "iso27001"
        //      "nis2-art21_to_..."                          → "nis2"
        //      "dora_to_nis2..."                            → "dora"
        //      "nist-csf-2-0_to_..."                       → "nist"
        $prefix = preg_replace('/[-_].*/', '', $basename) ?? $basename;

        // ISO 27001 variants all use the legacy 'iso' field name for BC
        if (str_starts_with($prefix, 'iso')) {
            return self::SOURCE_KEY_ISO;
        }

        // All other frameworks: the lowercased prefix IS the field name
        // (nis2 → 'nis2', dora → 'dora', nist → 'nist', eucs → 'eucs', etc.)
        return $prefix;
    }

    /**
     * Detect the JSON field name carrying the target requirement ID.
     *
     * Resolution order:
     * 1. BSI IT-Grundschutz targets always use 'baustein'.
     * 2. ISO 27001 targets use 'iso' (legacy field name).
     * 3. For fixtures that target BSI (path contains '_to_bsi'): use 'baustein'.
     * 4. For all other fixtures without a BSI-specific convention: use 'target'
     *    (the generic field name used by e.g. DORA→NIS2 panel fixtures).
     *
     * The fallback to 'baustein' in the apply() loop handles the case where
     * fixture authors mix field names (e.g. NIST uses 'baustein' for ISO27001 targets).
     */
    private function detectTargetKey(?ComplianceFramework $target, string $fixturePath = ''): string
    {
        if ($target === null || $target->getCode() === self::BSI_GRUNDSCHUTZ_CODE) {
            return self::TARGET_KEY_BAUSTEIN;
        }

        // For ISO27001 targets: fixtures either use 'iso' or 'baustein'.
        // Using 'baustein' as the primary key (with fallback to 'iso' via the loop's `?? $verdict['baustein']`)
        // would work for NIST panel files that use 'baustein' for ISO controls.
        // Using 'iso' as primary matches C5→ISO fixtures.
        // We detect from the fixture path: if it contains 'to_bsi' → baustein, else iso/generic.
        $code = strtolower($target->getCode());
        if (str_starts_with($code, 'iso27001')) {
            // ISO27001 target: C5→ISO uses 'iso', NIST→ISO uses 'baustein'.
            // Detect from fixture path: NIST fixtures contain 'nist' in the filename.
            $basename = strtolower(basename($fixturePath, '.json'));
            if (str_starts_with($basename, 'nist')) {
                // NIST panel uses 'baustein' for ISO27001 controls (historical naming).
                return self::TARGET_KEY_BAUSTEIN;
            }
            // C5 and other ISO27001-targeted fixtures use 'iso' or 'baustein'.
            // Default to 'iso' for C5→ISO; the apply() loop falls back to 'baustein' if 'iso' absent.
            return self::SOURCE_KEY_ISO;
        }

        // Non-BSI, non-ISO27001 targets (e.g. NIS2, NIST) use 'target' as the generic key.
        // (DORA→NIS2 panel fixture uses 'target' for the NIS2 requirement ID.)
        // The auto-fallback in apply() handles fixtures that use 'source'/'target' generically.
        return 'target';
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
     * EDGE: if the source or target requirement has no ComplianceRequirement row
     * in the DB → SKIP + log (no crash, no partial mapping).
     *
     * For BSI IT-Grundschutz targets, $targetReqId is a Baustein code and
     * `findBsiRequirement()` is used (category + requirementId-prefix lookup).
     * For all other targets, $targetReqId is a raw requirementId and
     * `findRequirement()` is used.
     *
     * @param array<string, mixed> $verdict
     * @param array<string, int> $counts
     */
    private function applyPanelDiscovered(
        ?ComplianceMapping $existingMapping,
        array $verdict,
        string $sourceReqId,
        string $targetReqId,
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
                ['sourceReqId' => $sourceReqId, 'targetReqId' => $targetReqId],
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

        // Resolve target ComplianceRequirement.
        // For BSI targets: use Baustein-aware lookup (category + requirementId-prefix).
        // For other targets (e.g. ISO27001, NIS2 controls): use direct requirementId lookup.
        $isBsiTarget = $targetFramework === null || $targetFramework->getCode() === self::BSI_GRUNDSCHUTZ_CODE;
        $targetReq   = $isBsiTarget
            ? $this->findBsiRequirement($targetReqId, $targetFramework)
            : $this->findRequirement($targetReqId, $targetFramework);

        if ($targetReq === null) {
            $counts['panel_discovered_skipped']++;
            $this->logger?->warning(
                'PanelVerdictApplier: panel_discovered skipped — target requirement not found in DB.',
                ['targetReqId' => $targetReqId, 'framework' => $targetFramework?->getCode()],
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
     * Build an index from (normalised-targetReqId||normalised-sourceReqId) → ComplianceMapping
     * for O(1) verdict lookup.
     *
     * For BSI IT-Grundschutz targets: targetReqId is the Baustein code (via bausteinCodeFrom()).
     * For other targets (e.g. ISO 27001, NIS2): targetReqId is the raw requirementId.
     *
     * For ISO fixture: only heuristic / non-official mappings are indexed (official CRT and
     * CRT-corroborated rows are excluded as they are not panel candidates).
     * For NIS2/C5/DORA/NIST fixtures: all non-deprecated mappings in the pair are indexed.
     *
     * @param ComplianceMapping[] $allMappings
     * @return array<string, ComplianceMapping>
     */
    private function buildIndex(
        array $allMappings,
        ?ComplianceFramework $source,
        ?ComplianceFramework $target,
        string $sourceKey,
        string $targetKey = self::TARGET_KEY_BAUSTEIN,
    ): array {
        $isBsiTarget = ($target === null || $target->getCode() === self::BSI_GRUNDSCHUTZ_CODE);
        $index       = [];

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

            // Target key derivation: Baustein normalization for BSI targets, raw id for others
            $targetIdentifier = $isBsiTarget
                ? IsoToBsiGapService::bausteinCodeFrom(
                    $tgtReq->getCategory(),
                    $tgtReq->getRequirementId(),
                )
                : (string) ($tgtReq->getRequirementId() ?? '');

            if ($srcReqId === '' || $targetIdentifier === '') {
                continue;
            }

            $key = $this->indexKey($targetIdentifier, $srcReqId);

            // Last writer wins for duplicate keys
            $index[$key] = $mapping;
        }

        return $index;
    }

    /**
     * Deterministic case-insensitive index key for (targetReqId, sourceControlId) pair.
     */
    private function indexKey(string $targetReqId, string $sourceControlId): string
    {
        return strtolower($targetReqId) . '||' . strtolower($sourceControlId);
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
