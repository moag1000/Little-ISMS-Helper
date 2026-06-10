<?php

declare(strict_types=1);

namespace App\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * WS-5b Stage 1 — CRT corroboration of heuristic ISO↔BSI mappings.
 *
 * ## Problem
 * The application holds two kinds of ISO↔BSI mapping rows:
 *   a) Official BSI Cross-Reference-Table (CRT) imports — `provenanceSource = 'official_bsi_crosswalk'`.
 *      Granularity: **Baustein level** (e.g. "SYS.1.2 ↔ A.8.9").
 *   b) Heuristic Anforderung-level mappings — finer grain, e.g. "SYS.1.2.A3 ↔ A.8.9",
 *      but no amtlich provenance yet.
 *
 * ## Solution
 * For each heuristic mapping, derive its target Baustein code and source ISO
 * control. If the official CRT contains a row with the SAME (Baustein, ISO
 * control) pair, the heuristic mapping is "CRT-corroborated" — the Baustein
 * level is officially covered by the same ISO control.
 *
 * Corroborated mappings are elevated to `provenanceSource = 'crt_corroborated'`,
 * which the `IsoToBsiGapService::trustOf()` maps to the `amtlich_gestuetzt` tier.
 * That tier is trusted — corroborated mappings do NOT land in the "prüfen" bucket.
 *
 * ## Idempotency
 * The command is safe to re-run:
 * - Mappings already at `crt_corroborated` are counted as corroborated and not
 *   touched again (no unnecessary flush).
 * - Official CRT rows (`official_bsi_crosswalk`) are never modified.
 *
 * @see IsoToBsiGapService           — trust-tier resolver
 * @see CorroborateBsiMappingsCommand — CLI entrypoint
 */
final class MappingCorroborationService
{
    public function __construct(
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Corroborate heuristic ISO↔BSI mappings against the official CRT.
     *
     * @param ComplianceFramework $iso ISO 27001 framework entity
     * @param ComplianceFramework $bsi BSI IT-Grundschutz framework entity
     * @param bool $dryRun             When true: compute counts but write nothing
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
        ComplianceFramework $iso,
        ComplianceFramework $bsi,
        bool $dryRun = false,
    ): array {
        // ── Step 1: Build CRT lookup set (Baustein × isoControl) ─────────────
        $crtSet = $this->buildCrtSet($iso, $bsi);

        // ── Step 2: Walk heuristic (non-official) ISO→BSI mappings ───────────
        $allMappings = $this->mappingRepository->findAllGlobal();

        $corroborated    = 0;
        $residual        = 0;
        $alreadyOfficial = 0;
        $byBaustein      = [];
        $details         = [];

        foreach ($allMappings as $mapping) {
            if (!$this->isIsoBsiMapping($mapping, $iso, $bsi)) {
                continue;
            }

            $provenance = $mapping->getProvenanceSource();

            // Official CRT rows: never touch, just count
            if ($provenance === IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT) {
                $alreadyOfficial++;
                continue;
            }

            $isoReq = $mapping->getSourceRequirement();
            $bsiReq = $mapping->getTargetRequirement();

            if (!$isoReq instanceof ComplianceRequirement || !$bsiReq instanceof ComplianceRequirement) {
                continue;
            }

            $isoControlId = $isoReq->getRequirementId();
            $baustein = IsoToBsiGapService::bausteinCodeFrom(
                $bsiReq->getCategory(),
                $bsiReq->getRequirementId(),
            );

            if ($isoControlId === null || $baustein === '') {
                $residual++;
                continue;
            }

            $crtKey = $this->crtKey($baustein, $isoControlId);
            $inCrt  = isset($crtSet[$crtKey]);

            // Track per-Baustein stats
            if (!isset($byBaustein[$baustein])) {
                $byBaustein[$baustein] = ['corroborated' => 0, 'residual' => 0];
            }

            if ($inCrt) {
                $byBaustein[$baustein]['corroborated']++;
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
                    'mapping_id'  => $mapping->getId(),
                    'baustein'    => $baustein,
                    'iso_control' => $isoControlId,
                    'was_elevated' => $wasElevated,
                ];
            } else {
                $byBaustein[$baustein]['residual']++;
                $residual++;
            }
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        ksort($byBaustein);

        return [
            'corroborated'    => $corroborated,
            'residual'        => $residual,
            'already_official' => $alreadyOfficial,
            'by_baustein'     => $byBaustein,
            'details'         => $details,
        ];
    }

    /**
     * Build the CRT lookup set: (normalised-baustein, normalised-isoControl) → true.
     *
     * The CRT rows have `provenanceSource = 'official_bsi_crosswalk'`.
     * The Baustein code is derived from the BSI requirement's category / requirementId
     * using the same `bausteinCodeFrom()` helper used for the heuristic side.
     * The ISO control is the sourceRequirement's requirementId.
     *
     * @return array<string, true>
     */
    private function buildCrtSet(ComplianceFramework $iso, ComplianceFramework $bsi): array
    {
        $allMappings = $this->mappingRepository->findAllGlobal();
        $set = [];

        foreach ($allMappings as $mapping) {
            if ($mapping->getProvenanceSource() !== IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT) {
                continue;
            }

            if (!$this->isIsoBsiMapping($mapping, $iso, $bsi)) {
                continue;
            }

            $isoReq = $mapping->getSourceRequirement();
            $bsiReq = $mapping->getTargetRequirement();

            if (!$isoReq instanceof ComplianceRequirement || !$bsiReq instanceof ComplianceRequirement) {
                continue;
            }

            $isoControlId = $isoReq->getRequirementId();
            $baustein     = IsoToBsiGapService::bausteinCodeFrom(
                $bsiReq->getCategory(),
                $bsiReq->getRequirementId(),
            );

            if ($isoControlId === null || $baustein === '') {
                continue;
            }

            $set[$this->crtKey($baustein, $isoControlId)] = true;
        }

        return $set;
    }

    /**
     * True when the mapping goes from the ISO framework (source) to the BSI framework (target).
     */
    private function isIsoBsiMapping(
        ComplianceMapping $mapping,
        ComplianceFramework $iso,
        ComplianceFramework $bsi,
    ): bool {
        $srcFw = $mapping->getSourceRequirement()?->getFramework();
        $tgtFw = $mapping->getTargetRequirement()?->getFramework();

        return $srcFw !== null && $tgtFw !== null
            && $srcFw->getId() === $iso->getId()
            && $tgtFw->getId() === $bsi->getId();
    }

    /**
     * Deterministic string key for the (Baustein, isoControl) pair.
     * Both sides are lower-cased for case-insensitive matching.
     */
    private function crtKey(string $baustein, string $isoControlId): string
    {
        return strtolower($baustein) . '||' . strtolower($isoControlId);
    }
}
