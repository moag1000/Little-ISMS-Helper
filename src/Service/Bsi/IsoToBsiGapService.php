<?php

declare(strict_types=1);

namespace App\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Enum\AbsicherungsStufe;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;

/**
 * ISO 27001 → BSI IT-Grundschutz gap classification service.
 *
 * Assembles existing coverage infrastructure into a 6-state gap view:
 *   gedeckt              — ISO control maps 100 % AND tenant has fulfilled it
 *   partiell             — ISO control maps < 100 % but tenant has progress
 *   iso_offen            — Mapping exists but tenant fulfillment is 0
 *   ungemappt_unbewertet — No mapping at all (v1; eigenständig deferred to WS-5b)
 *
 * Trust axis (ordered highest → lowest):
 *   amtlich            — official BSI crosswalk (provenanceSource = 'official_bsi_crosswalk')
 *   amtlich_gestuetzt  — heuristic mapping corroborated by official CRT at Baustein level
 *                        (provenanceSource = 'crt_corroborated', WS-5b stage-1 elevation)
 *   ki_validiert       — AI-assisted panel mapping with reviewStatus = 'approved'
 *   bestaetigt         — manual / confirmed mapping
 *   heuristisch        — unreviewed heuristic (must be checked / prüfen bucket)
 *
 * Action buckets:
 *   erledigt    — gedeckt + non-heuristic trust
 *   quick_win   — iso_offen  (ISO control exists, just needs implementation)
 *   bsi_arbeit  — partiell or ungemappt_eigenstaendig
 *   pruefen     — heuristic trust on any covered/partial state, or unbewertet
 *
 * @see BsiGapResult
 * @see MappingCorroborationService — the build-time step that sets crt_corroborated
 */
class IsoToBsiGapService
{
    /** provenanceSource sentinel written by import step (official BSI Cross-Reference-Table) */
    public const PROVENANCE_OFFICIAL_CRT = 'official_bsi_crosswalk';

    /** provenanceSource sentinel written by MappingCorroborationService (WS-5b stage-1) */
    public const PROVENANCE_CRT_CORROBORATED = 'crt_corroborated';

    /**
     * provenanceSource sentinel for EU lex-specialis mappings grounded in EUR-Lex
     * (e.g. DORA↔NIS2, loaded from dora_to_nis2_lex-specialis_v2.0.yaml).
     * These are amtlich — authoritative EU law, no human review needed.
     */
    public const PROVENANCE_OFFICIAL_EU_LEX_SPECIALIS = 'official_eu_lex_specialis';

    /** Trust tier constants */
    public const TIER_AMTLICH            = 'amtlich';
    public const TIER_AMTLICH_GESTUETZT  = 'amtlich_gestuetzt';
    public const TIER_KI_VALIDIERT       = 'ki_validiert';
    public const TIER_BESTAETIGT         = 'bestaetigt';
    public const TIER_HEURISTISCH        = 'heuristisch';

    /**
     * Tiers that are considered TRUSTED — they do NOT land in the "prüfen" bucket.
     *
     * @var list<string>
     */
    public const TRUSTED_TIERS = [
        self::TIER_AMTLICH,
        self::TIER_AMTLICH_GESTUETZT,
        self::TIER_KI_VALIDIERT,
        self::TIER_BESTAETIGT,
    ];

    public function __construct(
        private readonly ComplianceRequirementRepository $reqRepo,
        private readonly ComplianceMappingRepository $mappingRepo,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepo,
    ) {
    }

    /**
     * Build the full ISO→BSI gap for a tenant at their configured assurance level.
     *
     * @param ComplianceFramework $iso The source ISO 27001 framework
     * @param ComplianceFramework $bsi The target BSI IT-Grundschutz framework
     */
    public function buildGap(
        Tenant $tenant,
        ComplianceFramework $iso,
        ComplianceFramework $bsi,
    ): BsiGapResult {
        // Derive the set of tiers in scope for this tenant's assurance level
        $tiers = AbsicherungsStufe::tiersForLevel($tenant->getBsiAssuranceLevel());

        // 1. Fetch all top-level BSI requirements in scope (filtered by tier)
        $targets = $this->reqRepo->findByFrameworkAndTiers($bsi, $tiers);

        // 2. Fetch all operational cross-framework mappings (ISO → BSI).
        //    This loads all framework-pair mappings into memory in one query;
        //    acceptable at current volumes (~400 requirements, ~1200 mappings).
        $byTarget = $this->indexMappingsByTarget(
            $this->mappingRepo->findCrossFrameworkMappings($iso, $bsi)
        );

        // 3. Classify each in-scope BSI requirement
        /** @var list<array{requirementId:string,baustein:string,tier:string,state:string,trust:string,delta:int,isoControl:string|null,evidence:list<string>}> $items */
        $items = [];
        /** @var array{erledigt:int,quick_win:int,bsi_arbeit:int,pruefen:int} $buckets */
        $buckets = ['erledigt' => 0, 'quick_win' => 0, 'bsi_arbeit' => 0, 'pruefen' => 0];

        foreach ($targets as $req) {
            [$state, $trust, $delta, $isoCtrl, $evidence] = $this->classify(
                $tenant,
                $byTarget[$req->getId()] ?? [],
            );

            $bucket = $this->bucket($state, $trust);
            $buckets[$bucket]++;

            $items[] = [
                'requirementId' => (string) $req->getRequirementId(),
                'baustein'      => $this->deriveBaustein($req),
                'tier'          => (string) $req->getAbsicherungsStufe(),
                'state'         => $state,
                'trust'         => $trust,
                'delta'         => $delta,
                'isoControl'    => $isoCtrl,
                'evidence'      => $evidence,
            ];
        }

        return new BsiGapResult($items, $buckets, count($targets));
    }

    /**
     * Classify a single BSI requirement given the set of mappings pointing to it.
     *
     * Returns a 5-tuple: [state, trust, delta, isoControlId|null, evidenceTitles[]]
     *
     * @param ComplianceMapping[] $maps All operational mappings whose targetRequirement === $req
     * @return array{string, string, int, string|null, list<string>}
     */
    private function classify(Tenant $tenant, array $maps): array
    {
        if ($maps === []) {
            // No mapping at all — eigenständig classification deferred to WS-5b
            return ['ungemappt_unbewertet', '-', 0, null, []];
        }

        // Use the mapping with the highest coverage percentage
        usort($maps, static fn(ComplianceMapping $a, ComplianceMapping $b): int =>
            $b->getMappingPercentage() <=> $a->getMappingPercentage()
        );

        $m    = $maps[0];
        $trust = $this->trustOf($m);
        $src   = $m->getSourceRequirement();
        $pct   = $m->getMappingPercentage();

        // Single round-trip: fetch both fulfillment % and tenant-scoped evidence titles.
        $fulfillmentData = $this->fulfillmentRepo->fulfillmentDataFor($tenant, $src);
        $srcFulfilled    = $fulfillmentData['pct'];

        if ($srcFulfilled <= 0) {
            // ISO control exists in a mapping but tenant has not started it
            return ['iso_offen', $trust, 0, $src->getRequirementId(), []];
        }

        $evidence = $fulfillmentData['evidence'];

        if ($pct >= 100) {
            // Full coverage: mapping percentage ≥ 100 % AND tenant has fulfilled source
            return ['gedeckt', $trust, 0, $src->getRequirementId(), $evidence];
        }

        // Partial coverage: mapping percentage < 100 %
        $delta = max(0, 100 - $pct);

        return ['partiell', $trust, $delta, $src->getRequirementId(), $evidence];
    }

    /**
     * Derive the trust level of a mapping from its provenance / lifecycle metadata.
     *
     * Decision table (first match wins):
     *   1. provenanceSource = 'official_bsi_crosswalk'          → amtlich
     *   2. provenanceSource = 'crt_corroborated'                 → amtlich_gestuetzt
     *   3. provenanceSource = 'panel' AND reviewStatus = 'approved' → ki_validiert
     *   4. provenanceSource = 'manual'                           → bestaetigt
     *   5. reviewStatus     = 'confirmed'                        → bestaetigt
     *   6. (default)                                             → heuristisch
     *
     * The `null` provenanceSource case is listed explicitly (as a separate arm
     * before `default`) so PHPStan and readers see that a missing provenance
     * intentionally falls through to the reviewStatus check — same behaviour as
     * the generic `default` arm for all other algorithm-generated sources.
     *
     * Public so that MappingCorroborationServiceTest and other services can use
     * the same tier resolver without duplicating the decision table.
     */
    public function trustOf(ComplianceMapping $m): string
    {
        $reviewBasedTrust = $m->getReviewStatus() === 'confirmed' ? self::TIER_BESTAETIGT : self::TIER_HEURISTISCH;

        return match ($m->getProvenanceSource()) {
            self::PROVENANCE_OFFICIAL_CRT              => self::TIER_AMTLICH,
            self::PROVENANCE_CRT_CORROBORATED          => self::TIER_AMTLICH_GESTUETZT,
            self::PROVENANCE_OFFICIAL_EU_LEX_SPECIALIS => self::TIER_AMTLICH,
            'panel' => $m->getReviewStatus() === 'approved' ? self::TIER_KI_VALIDIERT : self::TIER_HEURISTISCH,
            'manual' => self::TIER_BESTAETIGT,
            // null: no provenance recorded — trust via reviewStatus, same as default arm below
            null => $reviewBasedTrust,
            // All other algorithm-generated sources: trust via reviewStatus
            default => $reviewBasedTrust,
        };
    }

    /**
     * Return true when the mapping needs human review (lands in the "prüfen" bucket).
     * Only the heuristisch tier requires review; all other tiers are trusted.
     */
    public function requiresReview(ComplianceMapping $m): bool
    {
        return $this->trustOf($m) === self::TIER_HEURISTISCH;
    }

    /**
     * Map a (state, trust) pair to an action bucket.
     *
     * Only `heuristisch` trust forces a 'pruefen' bucket — all other tiers
     * (including `amtlich_gestuetzt`) are considered trusted and follow the
     * state-based routing in the match expression below.
     */
    private function bucket(string $state, string $trust): string
    {
        // Only heuristic trust on any positively-classified state → human review required.
        // amtlich_gestuetzt, ki_validiert, bestaetigt, and amtlich are trusted — they do NOT
        // land in pruefen via this guard (they follow the state-based match below).
        if ($trust === self::TIER_HEURISTISCH && in_array($state, ['gedeckt', 'partiell', 'iso_offen'], true)) {
            return 'pruefen';
        }

        return match ($state) {
            'gedeckt'                    => 'erledigt',
            'iso_offen'                  => 'quick_win',
            'partiell', 'ungemappt_eigenstaendig' => 'bsi_arbeit',
            default                      => 'pruefen',
        };
    }

    /**
     * Index mappings by their target requirement ID for O(1) lookup.
     *
     * @param ComplianceMapping[] $maps
     * @return array<int, ComplianceMapping[]>
     */
    private function indexMappingsByTarget(array $maps): array
    {
        $index = [];
        foreach ($maps as $m) {
            $targetId = $m->getTargetRequirement()->getId();
            $index[$targetId][] = $m;
        }
        return $index;
    }

    /**
     * Derive the Baustein (BSI component) identifier from a requirement.
     *
     * BSI IT-Grundschutz Bausteine have IDs like "APP.1.1.A1" where the prefix
     * "APP.1.1" is the Baustein and "A1" is the individual requirement number.
     *
     * Primary source: requirement.category holds the Baustein identifier
     * (populated by the BSI loader as the Baustein shorthand, e.g. "APP.1.1").
     * Fallback: derive the prefix from the requirementId by stripping the
     * trailing ".A<n>" segment.
     */
    private function deriveBaustein(ComplianceRequirement $req): string
    {
        // category is the canonical Baustein field for BSI requirements
        if ($req->getCategory() !== null && $req->getCategory() !== '') {
            return $req->getCategory();
        }

        // Fallback: strip the ".A<N>" suffix from the requirementId
        // e.g. "APP.1.1.A3" → "APP.1.1"
        $id = (string) $req->getRequirementId();
        if (preg_match('/^(.+)\.A\d+$/', $id, $matches) === 1) {
            return $matches[1];
        }

        return $id;
    }

    /**
     * Derive the BSI Baustein code from a ComplianceRequirement's category or
     * requirementId (public static helper used by MappingCorroborationService).
     *
     * Convention (matches BsiGrundschutzCheckService::bausteinCode()):
     *   1. If $category is non-empty, extract the first whitespace-delimited token
     *      (e.g. "SYS.1.2 Windows Server" → "SYS.1.2").
     *   2. Fallback: parse $requirementId by stripping the trailing ".A<n>" segment
     *      (e.g. "SYS.1.2.A3" → "SYS.1.2").
     *
     * @param string|null $category      ComplianceRequirement::getCategory()
     * @param string|null $requirementId ComplianceRequirement::getRequirementId()
     * @return string  Baustein code, or empty string if undeterminable
     */
    public static function bausteinCodeFrom(?string $category, ?string $requirementId): string
    {
        // 1. Category-prefix (canonical for imported BSI requirements)
        if ($category !== null && $category !== '') {
            $first = explode(' ', trim($category), 2)[0];
            if ($first !== '') {
                return $first;
            }
        }

        // 2. requirementId prefix fallback (same logic as BsiGrundschutzCheckService)
        if ($requirementId !== null && $requirementId !== '') {
            $parts     = explode('.', $requirementId);
            $collected = [];
            foreach ($parts as $part) {
                if (preg_match('/^A\d+$/', $part) === 1) {
                    break;
                }
                $collected[] = $part;
            }
            return implode('.', $collected);
        }

        return '';
    }
}
