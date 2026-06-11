<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * BSI IT-Grundschutz Absicherungsstufen (protection levels).
 *
 * The three protection tiers defined by BSI IT-Grundschutz are cumulative:
 * 'basis' ⊂ 'standard' ⊂ 'kern'. The canonical field on ComplianceRequirement
 * is `absicherungsStufe` (column `absicherungs_stufe`). The legacy field
 * `anforderungsTyp` (column `anforderungs_typ`) uses overlapping but different
 * vocabulary (basis/standard/hoch) — see normalize() for the mapping.
 *
 * WS-1: Vocabulary & Service Consolidation for ISO 27001 → BSI IT-Grundschutz gap feature.
 */
enum AbsicherungsStufe: string
{
    case Basis    = 'basis';
    case Standard = 'standard';
    case Hoch     = 'hoch';

    /**
     * Returns all anforderungsTyp tiers that fall within the given
     * Absicherungsstufe level (cumulative / inclusive).
     *
     * BSI mapping:
     *   basis    → covers only Basis-Anforderungen
     *   standard → covers Basis + Standard-Anforderungen
     *   kern     → covers Basis + Standard + Hoch-Anforderungen (full)
     *
     * @return list<string> ordered from lowest to highest tier
     */
    public static function tiersForLevel(string $level): array
    {
        return match ($level) {
            'basis'    => ['basis'],
            'standard' => ['basis', 'standard'],
            'kern'     => ['basis', 'standard', 'hoch'],
            default    => ['basis'],
        };
    }

    /**
     * Normalize a raw anforderungsTyp string from legacy loaders into
     * a canonical absicherungsStufe value (or null if unrecognised).
     *
     * Legacy → canonical mappings:
     *   'basis'    → 'basis'
     *   'standard' → 'standard'
     *   'hoch'     → 'hoch'   (some loaders already use canonical form)
     *   'erhoeht'  → 'hoch'   (LoadBsiRequirementsCommand legacy spelling)
     *   'erhöht'   → 'hoch'   (German umlaut variant)
     *   anything else / empty / null → null
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return match (strtolower(trim($raw))) {
            'basis'    => 'basis',
            'standard' => 'standard',
            'hoch', 'erhoeht', 'erhöht' => 'hoch',
            default    => null,
        };
    }
}
