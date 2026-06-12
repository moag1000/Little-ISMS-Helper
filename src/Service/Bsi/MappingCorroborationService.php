<?php

declare(strict_types=1);

namespace App\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * WS-5b Stage 1 — CRT corroboration of heuristic framework mappings.
 *
 * ## Problem
 * The application holds two kinds of mapping rows:
 *   a) Official crosswalk imports — `provenanceSource = 'official_bsi_crosswalk'` (or another
 *      official-provenance sentinel for non-BSI target pairs). Granularity varies: for
 *      BSI Grundschutz targets the official source operates at **Baustein level** (e.g.
 *      "SYS.1.2 ↔ A.8.9"), not at Anforderung level.
 *   b) Heuristic Anforderung-level mappings — finer grain, e.g. "SYS.1.2.A3 ↔ A.8.9",
 *      but no amtlich provenance yet.
 *
 * ## Solution
 * For each heuristic mapping, derive a **target key** (Baustein code for BSI targets, raw
 * requirementId for others) and pair it with the source requirement identifier. If the
 * official crosswalk contains a row with the SAME pair, the heuristic mapping is
 * "CRT-corroborated" — the target level is officially covered by the same source control.
 *
 * Corroborated mappings are elevated to `provenanceSource = 'crt_corroborated'`,
 * which `IsoToBsiGapService::trustOf()` maps to the `amtlich_gestuetzt` tier.
 * That tier is trusted — corroborated mappings do NOT land in the "prüfen" bucket.
 *
 * ## Target-ID normalization strategy
 * - BSI Grundschutz targets (framework code `BSI_GRUNDSCHUTZ`): Baustein-level
 *   normalization — strip `.A<n>` suffix via `IsoToBsiGapService::bausteinCodeFrom()`.
 * - All other targets: raw `requirementId` (direct match against the official crosswalk).
 *
 * ## Idempotency
 * The command is safe to re-run:
 * - Mappings already at `crt_corroborated` are counted as corroborated and not
 *   touched again (no unnecessary flush).
 * - Official crosswalk rows (identified by the `officialProvenance` sentinel) are
 *   never modified.
 *
 * @see IsoToBsiGapService           — trust-tier resolver
 * @see CorroborateBsiMappingsCommand — CLI entrypoint
 */
final class MappingCorroborationService
{
    /** BSI IT-Grundschutz framework code — triggers Baustein-level target normalization */
    private const BSI_GRUNDSCHUTZ_CODE = 'BSI_GRUNDSCHUTZ';

    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Corroborate heuristic framework mappings against the official crosswalk.
     *
     * @param ComplianceFramework $source         Source framework (e.g. ISO 27001 or NIS2)
     * @param ComplianceFramework $target         Target framework (e.g. BSI IT-Grundschutz)
     * @param string              $officialProvenance  The provenanceSource sentinel that identifies
     *                                            official crosswalk rows. Default: ISO↔BSI value.
     * @param bool                $dryRun         When true: compute counts but write nothing
     *
     * @return array{
     *     corroborated: int,
     *     residual: int,
     *     already_official: int,
     *     by_baustein: array<string, array{corroborated: int, residual: int}>,
     *     details: list<array{mapping_id: int|null, baustein: string, iso_control: string, was_elevated: bool}>,
     * }
     */
    public function corroborate(
        ComplianceFramework $source,
        ComplianceFramework $target,
        string $officialProvenance = IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT,
        bool $dryRun = false,
    ): array {
        // ── Step 1: Build crosswalk lookup set (targetKey × sourceControlId) ──
        $crtSet = $this->buildCrtSet($source, $target, $officialProvenance);

        // ── Step 2: Walk heuristic (non-official) source→target mappings ──────
        $allMappings = $this->mappingRepository->findAllGlobal();

        $corroborated    = 0;
        $residual        = 0;
        $alreadyOfficial = 0;
        $byBaustein      = [];
        $details         = [];

        foreach ($allMappings as $mapping) {
            if (!$this->isFrameworkPairMapping($mapping, $source, $target)) {
                continue;
            }

            $provenance = $mapping->getProvenanceSource();

            // Official crosswalk rows: never touch, just count
            if ($provenance === $officialProvenance) {
                $alreadyOfficial++;
                continue;
            }

            $srcReq = $mapping->getSourceRequirement();
            $tgtReq = $mapping->getTargetRequirement();

            if (!$srcReq instanceof ComplianceRequirement || !$tgtReq instanceof ComplianceRequirement) {
                continue;
            }

            $srcControlId = $srcReq->getRequirementId();
            $targetKey    = $this->targetKey($target, $tgtReq);

            if ($srcControlId === null || $targetKey === '') {
                $residual++;
                continue;
            }

            $crtKey = $this->crtKey($targetKey, $srcControlId);
            $inCrt  = isset($crtSet[$crtKey]);

            // Track per-target-key stats (reuses the "by_baustein" key for BC)
            if (!isset($byBaustein[$targetKey])) {
                $byBaustein[$targetKey] = ['corroborated' => 0, 'residual' => 0];
            }

            if ($inCrt) {
                $byBaustein[$targetKey]['corroborated']++;
                $corroborated++;

                $wasElevated = false;
                // Already corroborated from a previous run? No-op.
                if ($provenance !== IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED) {
                    $wasElevated = true;
                    if (!$dryRun) {
                        $mapping->setProvenanceSource(IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED);
                    }
                }

                $details[] = [
                    'mapping_id'   => $mapping->getId(),
                    'baustein'     => $targetKey,
                    'iso_control'  => $srcControlId,
                    'was_elevated' => $wasElevated,
                ];
            } else {
                $byBaustein[$targetKey]['residual']++;
                $residual++;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        ksort($byBaustein);

        return [
            'corroborated'     => $corroborated,
            'residual'         => $residual,
            'already_official' => $alreadyOfficial,
            'by_baustein'      => $byBaustein,
            'details'          => $details,
        ];
    }

    /**
     * Compute the normalised target key for a ComplianceRequirement.
     *
     * - BSI Grundschutz targets → Baustein-level normalization (strips `.A<n>` suffix).
     * - All other targets → raw requirementId (direct match).
     */
    public function targetKey(ComplianceFramework $target, ComplianceRequirement $req): string
    {
        if ($target->getCode() === self::BSI_GRUNDSCHUTZ_CODE) {
            return IsoToBsiGapService::bausteinCodeFrom(
                $req->getCategory(),
                $req->getRequirementId(),
            );
        }

        return (string) ($req->getRequirementId() ?? '');
    }

    /**
     * True when the mapping goes from the source framework to the target framework.
     *
     * Generalised replacement for the former `isIsoBsiMapping()` method.
     * The old name is kept as a BC alias for external callers.
     */
    public function isFrameworkPairMapping(
        ComplianceMapping $mapping,
        ComplianceFramework $source,
        ComplianceFramework $target,
    ): bool {
        $srcFw = $mapping->getSourceRequirement()?->getFramework();
        $tgtFw = $mapping->getTargetRequirement()?->getFramework();

        return $srcFw !== null && $tgtFw !== null
            && $srcFw->getId() === $source->getId()
            && $tgtFw->getId() === $target->getId();
    }

    /**
     * BC alias — delegates to `isFrameworkPairMapping()`.
     *
     * @deprecated Use isFrameworkPairMapping() instead.
     */
    public function isIsoBsiMapping(
        ComplianceMapping $mapping,
        ComplianceFramework $iso,
        ComplianceFramework $bsi,
    ): bool {
        return $this->isFrameworkPairMapping($mapping, $iso, $bsi);
    }

    /**
     * Build the crosswalk lookup set: (normalised-targetKey, normalised-srcControlId) → true.
     *
     * Only rows with `provenanceSource = $officialProvenance` are indexed.
     *
     * @return array<string, true>
     */
    private function buildCrtSet(
        ComplianceFramework $source,
        ComplianceFramework $target,
        string $officialProvenance,
    ): array {
        $allMappings = $this->mappingRepository->findAllGlobal();
        $set = [];

        foreach ($allMappings as $mapping) {
            if ($mapping->getProvenanceSource() !== $officialProvenance) {
                continue;
            }

            if (!$this->isFrameworkPairMapping($mapping, $source, $target)) {
                continue;
            }

            $srcReq = $mapping->getSourceRequirement();
            $tgtReq = $mapping->getTargetRequirement();

            if (!$srcReq instanceof ComplianceRequirement || !$tgtReq instanceof ComplianceRequirement) {
                continue;
            }

            $srcControlId = $srcReq->getRequirementId();
            $targetKey    = $this->targetKey($target, $tgtReq);

            if ($srcControlId === null || $targetKey === '') {
                continue;
            }

            $set[$this->crtKey($targetKey, $srcControlId)] = true;
        }

        return $set;
    }

    /**
     * Deterministic string key for the (targetKey, srcControlId) pair.
     * Both sides are lower-cased for case-insensitive matching.
     */
    private function crtKey(string $targetKey, string $srcControlId): string
    {
        return strtolower($targetKey) . '||' . strtolower($srcControlId);
    }
}
