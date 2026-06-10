<?php

declare(strict_types=1);

namespace App\Service\Bsi;

use App\Entity\ComplianceMapping;

/**
 * Trust-tier classification for ISO↔BSI compliance mappings.
 *
 * ## Trust tiers (ordered highest → lowest)
 *
 * | Tier                | provenanceSource value         | Meaning                                             |
 * |---------------------|-------------------------------|-----------------------------------------------------|
 * | amtlich             | official_bsi_crosswalk        | Direct BSI CRT import — authoritative by definition |
 * | amtlich_gestuetzt   | crt_corroborated              | Heuristic Anforderung-mapping backed by official    |
 * |                     |                               | CRT at Baustein level (WS-5b stage-1 elevation)     |
 * | ki_validiert        | panel + approved reviewStatus | Panel-reviewed AI-assisted mapping                  |
 * | bestaetigt          | manual / confirmed status     | Manual human confirmation                           |
 * | heuristisch         | (anything else)               | Unreviewed heuristic — must be checked (prüfen)     |
 *
 * The `amtlich_gestuetzt` tier is deliberately distinct from `bestaetigt`:
 * it carries the weight of amtlich CRT evidence (Baustein level) and should
 * NOT land in the "prüfen" bucket even before a human reviewer touches it.
 *
 * @see MappingCorroborationService  — the build-time step that sets crt_corroborated
 */
final class IsoToBsiGapService
{
    /** provenanceSource sentinel written by MappingCorroborationService */
    public const PROVENANCE_OFFICIAL_CRT = 'official_bsi_crosswalk';
    public const PROVENANCE_CRT_CORROBORATED = 'crt_corroborated';

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

    /**
     * Return the trust tier for a single ComplianceMapping.
     *
     * Decision table (first match wins):
     *   1. provenanceSource = 'official_bsi_crosswalk'  → amtlich
     *   2. provenanceSource = 'crt_corroborated'         → amtlich_gestuetzt
     *   3. provenanceSource = 'panel' AND reviewStatus = 'approved' → ki_validiert
     *   4. provenanceSource = 'manual'                   → bestaetigt
     *   5. reviewStatus     = 'confirmed'                → bestaetigt
     *   6. (default)                                     → heuristisch
     */
    public function trustOf(ComplianceMapping $mapping): string
    {
        $provenance    = $mapping->getProvenanceSource();
        $reviewStatus  = $mapping->getReviewStatus();

        if ($provenance === self::PROVENANCE_OFFICIAL_CRT) {
            return self::TIER_AMTLICH;
        }

        if ($provenance === self::PROVENANCE_CRT_CORROBORATED) {
            return self::TIER_AMTLICH_GESTUETZT;
        }

        if ($provenance === 'panel' && $reviewStatus === 'approved') {
            return self::TIER_KI_VALIDIERT;
        }

        if ($provenance === 'manual') {
            return self::TIER_BESTAETIGT;
        }

        if ($reviewStatus === 'confirmed') {
            return self::TIER_BESTAETIGT;
        }

        return self::TIER_HEURISTISCH;
    }

    /**
     * Return true when the mapping needs human review (lands in the "prüfen" bucket).
     * Trusted tiers do NOT require review.
     */
    public function requiresReview(ComplianceMapping $mapping): bool
    {
        return !in_array($this->trustOf($mapping), self::TRUSTED_TIERS, true);
    }

    /**
     * Derive the BSI Baustein code from a ComplianceRequirement's category or
     * requirementId.
     *
     * Convention (matches BsiGrundschutzCheckService::bausteinCode()):
     *   1. If $category is non-empty, extract the first whitespace-delimited token
     *      (e.g. "SYS.1.2 Windows Server" → "SYS.1.2").
     *   2. Fallback: parse $requirementId by stripping the trailing ".A<n>" segment
     *      (e.g. "SYS.1.2.A3" → "SYS.1.2").
     *
     * @param string|null $category    ComplianceRequirement::getCategory()
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
