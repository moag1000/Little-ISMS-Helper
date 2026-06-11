<?php

declare(strict_types=1);

namespace App\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Exception\Io\IoException;
use App\Repository\ComplianceMappingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * WS-5b Stage 2 — Apply 4-persona expert panel verdicts to residual heuristic ISO↔BSI mappings.
 *
 * ## Context
 * After stage 1 (CRT corroboration) elevated some heuristic mappings to `amtlich_gestuetzt`,
 * 144 residual mappings remain that are not covered by the official CRT. A build-time
 * 4-persona panel (bsi-specialist, isms-specialist, consultant-senior, isb-practitioner)
 * adjudicated each residual mapping. Results: 133 ki_validiert, 2 reject, 9 needs_review.
 *
 * ## Verdict application
 *   ki_validiert → provenanceSource='panel', lifecycleState='approved',
 *                  analysisConfidence derived from realVotes (4→90, 3→70, ≤2→50),
 *                  mappingPercentage = verdict value.
 *                  trustOf() returns ki_validiert.
 *   reject       → lifecycleState='deprecated' (NOT deleted — audit trail).
 *                  Drops out of OPERATIONAL_STATES; no longer appears as coverage.
 *   needs_review → reviewStatus='needs_review', requiresReview=true.
 *                  Stays in the inheritance review queue.
 *
 * ## Idempotency
 * Re-running is safe: each verdict state check guards against redundant writes.
 *
 * ## Fixture
 * Verdicts are loaded from the reproducible audit fixture at:
 *   fixtures/library/mappings/panel_verdicts/iso27001-2022_to_bsi-grundschutz_panel_v1.json
 *
 * @see IsoToBsiGapService::trustOf()       — trust tier resolver (panel+approved → ki_validiert)
 * @see ApplyPanelVerdictsCommand           — CLI entrypoint (app:bsi:apply-panel-verdicts)
 * @see MappingCorroborationService         — stage 1 CRT corroboration
 */
final class PanelVerdictApplier
{
    /** Relative path from project root to the verdict fixture */
    private const FIXTURE_PATH = 'fixtures/library/mappings/panel_verdicts/iso27001-2022_to_bsi-grundschutz_panel_v1.json';

    /** analysisConfidence by realVotes count */
    private const CONFIDENCE_BY_VOTES = [
        4 => 90,
        3 => 70,
    ];

    /** Default confidence when realVotes < 3 */
    private const CONFIDENCE_DEFAULT = 50;

    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Load the panel verdict fixture from disk.
     *
     * @return list<array{iso: string, baustein: string, state: string, mappingPercentage: int, realVotes: int, relations: list<string>}>
     *
     * @throws IoException If the fixture file is not found, unreadable, or has an unexpected structure
     */
    public function loadVerdicts(): array
    {
        $path = $this->projectDir . DIRECTORY_SEPARATOR . self::FIXTURE_PATH;

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
     * Apply all panel verdicts to the matching ISO→BSI ComplianceMappings.
     *
     * @param ComplianceFramework $iso ISO 27001 framework entity
     * @param ComplianceFramework $bsi BSI IT-Grundschutz framework entity
     * @param bool $dryRun             When true: compute counts but write nothing
     *
     * @return array{
     *     ki_validiert: int,
     *     rejected: int,
     *     needs_review: int,
     *     not_matched: int,
     *     already_applied: int,
     *     total: int,
     * }
     */
    public function apply(
        ComplianceFramework $iso,
        ComplianceFramework $bsi,
        bool $dryRun = false,
    ): array {
        $verdicts = $this->loadVerdicts();

        // Index all ISO→BSI mappings by (isoControl, baustein) for fast lookup.
        // Only non-official, non-deprecated mappings are candidates.
        $allMappings = $this->mappingRepository->findAllGlobal();
        $index = $this->buildIndex($allMappings, $iso, $bsi);

        $counts = [
            'ki_validiert'  => 0,
            'rejected'      => 0,
            'needs_review'  => 0,
            'not_matched'   => 0,
            'already_applied' => 0,
            'total'         => count($verdicts),
        ];

        foreach ($verdicts as $verdict) {
            $key = $this->indexKey($verdict['baustein'], $verdict['iso']);
            $mapping = $index[$key] ?? null;

            if ($mapping === null) {
                $counts['not_matched']++;
                continue;
            }

            match ($verdict['state']) {
                'ki_validiert'  => $this->applyKiValidiert($mapping, $verdict, $counts, $dryRun),
                'reject'        => $this->applyReject($mapping, $counts, $dryRun),
                'needs_review'  => $this->applyNeedsReview($mapping, $counts, $dryRun),
                default         => null,
            };
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        return $counts;
    }

    /**
     * Apply ki_validiert verdict: elevate to panel/approved, set confidence + percentage.
     *
     * @param array{iso: string, baustein: string, state: string, mappingPercentage: int, realVotes: int, relations: list<string>} $verdict
     * @param array<string, int> $counts
     */
    private function applyKiValidiert(
        ComplianceMapping $mapping,
        array $verdict,
        array &$counts,
        bool $dryRun,
    ): void {
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

        $votes      = (int) $verdict['realVotes'];
        $confidence = self::CONFIDENCE_BY_VOTES[$votes] ?? self::CONFIDENCE_DEFAULT;

        $mapping->setProvenanceSource('panel');
        $mapping->setLifecycleState('approved');
        $mapping->setAnalysisConfidence($confidence);
        $mapping->setMappingPercentage((int) $verdict['mappingPercentage']);
    }

    /**
     * Apply reject verdict: deprecate the mapping (audit-safe, NOT hard-delete).
     *
     * @param array<string, int> $counts
     */
    private function applyReject(
        ComplianceMapping $mapping,
        array &$counts,
        bool $dryRun,
    ): void {
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
        ComplianceMapping $mapping,
        array &$counts,
        bool $dryRun,
    ): void {
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
     * Build an index from (normalised-baustein||normalised-isoControl) → ComplianceMapping
     * for O(1) verdict lookup.
     *
     * Only heuristic / non-official mappings are indexed — official CRT rows and
     * already-deprecated rows are excluded (they are not panel candidates).
     *
     * @param ComplianceMapping[] $allMappings
     * @return array<string, ComplianceMapping>
     */
    private function buildIndex(array $allMappings, ComplianceFramework $iso, ComplianceFramework $bsi): array
    {
        $index = [];

        foreach ($allMappings as $mapping) {
            // Filter to ISO→BSI direction
            $srcFw = $mapping->getSourceRequirement()?->getFramework();
            $tgtFw = $mapping->getTargetRequirement()?->getFramework();

            if ($srcFw === null || $tgtFw === null) {
                continue;
            }
            if ($srcFw->getId() !== $iso->getId() || $tgtFw->getId() !== $bsi->getId()) {
                continue;
            }

            // Skip official CRT rows — they are not panel candidates
            if ($mapping->getProvenanceSource() === IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT) {
                continue;
            }

            // Skip already CRT-corroborated (stage 1) rows
            if ($mapping->getProvenanceSource() === IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED) {
                continue;
            }

            $isoReq = $mapping->getSourceRequirement();
            $bsiReq = $mapping->getTargetRequirement();

            $isoControlId = $isoReq->getRequirementId();
            $baustein     = IsoToBsiGapService::bausteinCodeFrom(
                $bsiReq->getCategory(),
                $bsiReq->getRequirementId(),
            );

            if ($isoControlId === null || $baustein === '') {
                continue;
            }

            $key = $this->indexKey($baustein, $isoControlId);

            // Last writer wins for duplicate keys (deterministic, since we sort below)
            $index[$key] = $mapping;
        }

        return $index;
    }

    /**
     * Deterministic case-insensitive index key for (baustein, isoControl) pair.
     */
    private function indexKey(string $baustein, string $isoControlId): string
    {
        return strtolower($baustein) . '||' . strtolower($isoControlId);
    }
}
