<?php

declare(strict_types=1);

namespace App\Service\Import\Mapper;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Service\Tisax\Dto\VdaIsaControlRow;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maps parsed VdaIsaControlRow DTOs to ComplianceRequirement entities
 * under the canonical TISAX framework.
 *
 * Delta strategy:
 *  - Match by (framework + requirementId + uploadTenant) composite
 *  - Existing row → update title/description/metadata
 *  - No row → create new with requirementSource='tenant_upload'
 *
 * Framework code history (§4.1 consolidation, 2026-06-01):
 *  - 'TISAX-VDA-ISA-6' was the code used by the BYO import prior to
 *    consolidation. 'TISAX' is the canonical code used by all dashboards,
 *    mappings, reports and the compliance wizard. The old code is kept in
 *    LEGACY_CODE_ALIASES so that any saved session or lookup referencing
 *    'TISAX-VDA-ISA-6' still resolves to the canonical framework during
 *    the deprecation window.
 */
final class TisaxRequirementMapper
{
    /** Canonical framework code for the TISAX VDA-ISA framework. */
    public const FRAMEWORK_CODE = 'TISAX';

    /**
     * Backward-compatibility alias map.
     * Key = deprecated code → Value = canonical code.
     * Used by findOrCreateFramework() and can be consumed by any caller
     * that needs to resolve a legacy code stored in sessions / YAML keys.
     *
     * @var array<string, string>
     * @deprecated aliases — resolve to FRAMEWORK_CODE; will be removed after P4
     */
    public const LEGACY_CODE_ALIASES = [
        'TISAX-VDA-ISA-6' => self::FRAMEWORK_CODE,
    ];

    /** ISO 27001 anchor mapping prefix stored in dataSourceMapping. */
    private const ISO_KEY = 'iso27001';

    /** Mirror of TisaxMaturityAssessmentService::LEVEL_MAP. Kept inline to
     * avoid coupling the import-mapper to the assessment-service layer.
     * @var array<int, string>
     */
    private const LEVEL_MAP = [
        0 => 'incomplete',
        1 => 'performed',
        2 => 'managed',
        3 => 'established',
        4 => 'predictable',
        5 => 'optimising',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Find or create the canonical TISAX framework.
     * Idempotent — safe to call on every import run.
     *
     * Resolution order (§4.1 alias deprecation window):
     *  1. Look up canonical code 'TISAX'.
     *  2. If not found, fall back to the legacy alias 'TISAX-VDA-ISA-6' so that
     *     installations that were created before the consolidation still resolve
     *     correctly without a full data migration having been run yet.
     *  3. If neither exists, create a new framework with the canonical code.
     */
    public function findOrCreateFramework(): ComplianceFramework
    {
        $repo = $this->em->getRepository(ComplianceFramework::class);

        // 1. Try canonical code first.
        $framework = $repo->findOneBy(['code' => self::FRAMEWORK_CODE]);
        if ($framework !== null) {
            return $framework;
        }

        // 2. Try legacy alias codes (deprecation window — remove after P4).
        foreach (array_keys(self::LEGACY_CODE_ALIASES) as $legacyCode) {
            $framework = $repo->findOneBy(['code' => $legacyCode]);
            if ($framework !== null) {
                return $framework;
            }
        }

        // 3. Create with the canonical code.
        $framework = new ComplianceFramework();
        $framework->setCode(self::FRAMEWORK_CODE);
        $framework->setName('TISAX VDA-ISA 6.0');
        $framework->setVersion('6.0');
        $framework->setDescription(
            'VDA Information Security Assessment (VDA-ISA) — customer-supplied workbook. '
            . 'ENX-licensed content; tenant-specific requirements only.',
        );
        $framework->setRegulatoryBody('VDA / ENX Association');
        $framework->setApplicableIndustry('Automotive');
        $framework->setMandatory(false);
        $framework->setActive(true);
        $framework->setRequiredModules(['prototype_protection']);

        $this->em->persist($framework);
        $this->em->flush();

        return $framework;
    }

    /**
     * Map a list of parsed rows to ComplianceRequirement entities.
     *
     * Returns a delta summary array:
     *   ['created' => int, 'updated' => int, 'skipped' => int, 'entities' => list<ComplianceRequirement>]
     *
     * @param list<VdaIsaControlRow> $rows
     * @return array{created: int, updated: int, skipped: int, entities: list<ComplianceRequirement>}
     */
    public function mapRows(
        array $rows,
        ComplianceFramework $framework,
        Tenant $tenant,
        bool $dryRun = false,
    ): array {
        $repo     = $this->em->getRepository(ComplianceRequirement::class);
        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $entities = [];

        foreach ($rows as $row) {
            // Try to find existing row for this tenant
            $existing = $repo->findOneBy([
                'framework'    => $framework,
                'requirementId' => $row->controlId,
                'uploadTenant' => $tenant,
            ]);

            if ($existing !== null) {
                // Update in place. Backfill a pre-filled Reifegrad ONLY when the
                // existing requirement has no assessment yet — this rescues rows
                // imported by the old Reifegrad-blind parser (they sit at null and
                // would otherwise show "Bitte wählen" forever). A real assessment
                // is never overwritten, so assessor work is preserved on re-import.
                $this->hydrateEntity($existing, $row, $framework, $tenant);
                if ($this->isEmptyMaturity($existing->getMaturityCurrent())
                    && ($level = $this->resolvePrefilledLevel($row)) !== null
                ) {
                    $existing->setMaturityCurrent($level);
                }
                $entities[] = $existing;
                $updated++;
            } else {
                // Create new — mirror pre-filled Reifegrad from workbook (if any)
                $req = new ComplianceRequirement();
                $this->hydrateEntity($req, $row, $framework, $tenant);
                if (($level = $this->resolvePrefilledLevel($row)) !== null) {
                    $req->setMaturityCurrent($level);
                }
                if (!$dryRun) {
                    $this->em->persist($req);
                }
                $entities[] = $req;
                $created++;
            }
        }

        if (!$dryRun && ($created > 0 || $updated > 0)) {
            $this->em->flush();
        }

        return compact('created', 'updated', 'skipped', 'entities');
    }

    /**
     * Compute a preview delta without writing to the DB.
     *
     * @param list<VdaIsaControlRow> $rows
     * @return array{new: int, existing: int, total: int}
     */
    public function computeDelta(
        array $rows,
        ComplianceFramework $framework,
        Tenant $tenant,
    ): array {
        $repo     = $this->em->getRepository(ComplianceRequirement::class);
        $existing = 0;

        foreach ($rows as $row) {
            if ($repo->findOneBy([
                'framework'    => $framework,
                'requirementId' => $row->controlId,
                'uploadTenant' => $tenant,
            ]) !== null) {
                $existing++;
            }
        }

        $total = count($rows);
        return [
            'new'      => $total - $existing,
            'existing' => $existing,
            'total'    => $total,
        ];
    }

    /**
     * Compute the Reifegrad delta between the uploaded workbook and the current
     * stored assessment, for rows whose existing maturity is set AND differs
     * from the workbook value. Empty existing rows are excluded — those are
     * silently backfilled by mapRows() and are not "changes" the user must
     * confirm. The result feeds the selective-overwrite UI on the commit step.
     *
     * @param list<VdaIsaControlRow> $rows
     * @return list<array{controlId: string, title: string, currentLevel: string,
     *     currentInt: int|null, workbookLevel: string, workbookInt: int, direction: string}>
     */
    public function computeMaturityDiff(
        array $rows,
        ComplianceFramework $framework,
        Tenant $tenant,
    ): array {
        $repo    = $this->em->getRepository(ComplianceRequirement::class);
        $reverse = array_flip(self::LEVEL_MAP); // 'established' => 3, …
        $diff    = [];

        foreach ($rows as $row) {
            $workbookLevel = $this->resolvePrefilledLevel($row);
            if ($workbookLevel === null) {
                continue; // no usable workbook score (or DP tier)
            }

            $existing = $repo->findOneBy([
                'framework'     => $framework,
                'requirementId' => $row->controlId,
                'uploadTenant'  => $tenant,
            ]);
            if ($existing === null) {
                continue; // brand-new row → handled as create, not a change
            }

            $currentLevel = $existing->getMaturityCurrent();
            if ($this->isEmptyMaturity($currentLevel)) {
                continue; // empty → auto-backfilled, no confirmation needed
            }
            if ($currentLevel === $workbookLevel) {
                continue; // unchanged
            }

            $currentInt  = $reverse[$currentLevel] ?? null;
            $workbookInt = (int) $row->maturityCurrent;
            $diff[] = [
                'controlId'     => $row->controlId,
                'title'         => $existing->getTitle() ?? $row->title,
                'currentLevel'  => $currentLevel,
                'currentInt'    => $currentInt,
                'workbookLevel' => $workbookLevel,
                'workbookInt'   => $workbookInt,
                'direction'     => ($currentInt === null || $workbookInt > $currentInt) ? 'up' : 'down',
            ];
        }

        return $diff;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the pre-filled workbook assessment into the canonical level string
     * ('incomplete'…'optimising'), or null when the row carries no usable score.
     *
     * - Information Security / Prototype Protection: 0-5 Reifegrad → LEVEL_MAP.
     * - Data Protection: tristate cell ("OK" / "Nicht OK" / "Not OK" / "na").
     *   "OK" → established (target met); "Nicht OK"/"Not OK" → incomplete;
     *   "na"/"n.a." (not applicable) → null. Some DP rows DO carry a 0-5 value
     *   (the workbook allows both validation lists) — honour that too.
     */
    private function resolvePrefilledLevel(VdaIsaControlRow $row): ?string
    {
        if ($row->dimension === 'data_protection') {
            return $this->resolveDataProtectionLevel($row);
        }

        if ($row->maturityCurrent === null || !isset(self::LEVEL_MAP[$row->maturityCurrent])) {
            return null;
        }

        return self::LEVEL_MAP[$row->maturityCurrent];
    }

    /**
     * Map the Data-Protection tristate / numeric assessment cell to a level.
     */
    private function resolveDataProtectionLevel(VdaIsaControlRow $row): ?string
    {
        // Numeric 0-5 path (DP cells whose validation list is {NA,0-5}).
        if ($row->maturityCurrent !== null && isset(self::LEVEL_MAP[$row->maturityCurrent])) {
            return self::LEVEL_MAP[$row->maturityCurrent];
        }

        $raw = strtolower(trim((string) $row->maturityRaw));
        $raw = rtrim($raw, '.'); // "n.a." → "n.a"

        return match (true) {
            $raw === 'ok'                              => 'established', // compliant
            $raw === 'nicht ok', $raw === 'not ok'     => 'incomplete',  // non-compliant
            default                                    => null,          // "na"/"n.a"/blank → not applicable
        };
    }

    /**
     * True when an existing requirement has no Reifegrad assessment yet.
     * 'incomplete' (= level 0) is treated as a real, deliberate assessment and
     * is NOT backfilled — only null/empty counts as "unrated".
     */
    private function isEmptyMaturity(?string $current): bool
    {
        return $current === null || $current === '';
    }

    private function hydrateEntity(
        ComplianceRequirement $req,
        VdaIsaControlRow $row,
        ComplianceFramework $framework,
        Tenant $tenant,
    ): void {
        $req->setFramework($framework);
        $req->setRequirementId($row->controlId);
        $req->setTitle(mb_substr($row->title, 0, 255));
        $req->setDescription($row->description ?? $row->title);
        $req->setPriority($this->derivePriority($row));
        // Authoritative dimension from the source sheet (information_security /
        // prototype_protection / data_protection) — not the ID-prefix guess.
        $req->setCategory($row->dimension);
        $req->setRequirementType('core');
        $req->setRequirementSource('tenant_upload');
        $req->setUploadTenant($tenant);
        $req->setUpdatedAt(new DateTimeImmutable());

        // VDA-ISA target Reifegrad is uniformly 3 ("established") in ISA 6 — set
        // it so the assess-page shows the gap-to-target, not a blank target.
        if ($row->dimension !== 'data_protection' && $this->isEmptyMaturity($req->getMaturityTarget())) {
            $req->setMaturityTarget('established');
        }

        // Persist ISO 27001 anchors + evidence hints in dataSourceMapping JSON
        $mapping = $req->getDataSourceMapping() ?? [];
        if ($row->iso27001Ref !== null) {
            $mapping[self::ISO_KEY] = $row->iso27001Ref;
        }
        if ($row->auditEvidenceHint !== null) {
            $mapping['auditEvidence'] = $row->auditEvidenceHint;
        }
        if ($row->titleEn !== null && $row->titleEn !== $row->title) {
            $mapping['titleEn'] = $row->titleEn;
        }
        // The assessor's documented MEASURE ("Beschreibung der Umsetzung",
        // col E) — previously dropped on the floor. This is the user's
        // "Maßnahme" and MUST survive the import.
        if ($row->implementationDescription !== null) {
            $mapping['implementation'] = $row->implementationDescription;
        }
        // Referenced documents ("Referenz Dokumentation", col F) — the user's
        // "Dokumente". Stored as text references (free-text document names; the
        // workbook does not carry file handles, so we keep the citation).
        if ($row->referenceDocumentation !== null) {
            $mapping['referenceDocumentation'] = $row->referenceDocumentation;
        }
        // Verbatim maturity cell — preserves the Data-Protection tristate
        // ("OK"/"Nicht OK"/"Not OK"/"na") that the 0-5 scale cannot express.
        if ($row->maturityRaw !== null) {
            $mapping['maturityRaw'] = $row->maturityRaw;
        }
        // Store TISAX maturity targets in dataSourceMapping
        foreach (['mustLevel' => 'must', 'shouldLevel' => 'should', 'highLevel' => 'high', 'veryHighLevel' => 'veryHigh', 'sgaLevel' => 'sga'] as $prop => $key) {
            if ($row->$prop !== null) {
                $mapping["tisax_{$key}"] = $row->$prop;
            }
        }
        $req->setDataSourceMapping($mapping ?: null);
    }

    /**
     * Derive a ComplianceRequirement priority from VDA-ISA maturity levels.
     */
    private function derivePriority(VdaIsaControlRow $row): string
    {
        if ($row->veryHighLevel !== null && $row->veryHighLevel !== '') {
            return 'critical';
        }
        if ($row->highLevel !== null && $row->highLevel !== '') {
            return 'high';
        }
        if ($row->mustLevel !== null && $row->mustLevel !== '') {
            return 'medium';
        }
        return 'low';
    }
}
