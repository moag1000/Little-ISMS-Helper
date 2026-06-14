<?php

declare(strict_types=1);

namespace App\Service\Catalog;

/**
 * Canonical compliance-framework code registry (single source of truth).
 *
 * Background: framework codes drifted into spelling/version collisions
 * (ISO-22301 vs ISO22301 vs ISO_22301, NIS2UMSUCG vs NIS2-UmsuCG, ...). The
 * `ComplianceFrameworkLoaderService` registry (getAvailableFrameworks) and the
 * registry-bound loaders (FrameworkLoaderInterface::getFrameworkCode) use the
 * CANONICAL spellings below; everything else (legacy DB rows, fixture/library
 * framework identifiers, wizard config) must resolve onto these.
 *
 * CANONICAL mirrors the real framework set on main: every getAvailableFrameworks
 * literal code + the loader-only codes (TISAX is registered via a meta variable,
 * EUCS is a loader-only mapping target). check_compliance_catalog.py enforces
 * that every registry code has a loader.
 *
 * Deferred (separate process frameworks, not aliases): BSI-GRUNDSCHUTZ-KERN,
 * BSI-GRUNDSCHUTZ-STANDARD. Unknown/no-loader mapping refs handled in WS-2.2.
 */
final class FrameworkCode
{
    /**
     * Canonical framework codes — the real framework set on main.
     *
     * @var list<string>
     */
    public const CANONICAL = [
        'TISAX',
        'DORA',
        'NIS2',
        'NIS2UMSUCG',
        'BSI_GRUNDSCHUTZ',
        'GDPR',
        'BDSG',
        'ISO27001',
        'ISO27701',
        'ISO27701_2025',
        'ISO27005',
        'ISO27017',
        'ISO27018',
        'ISO42001',
        'ISO-22301',
        'BSI-C5',
        'BSI-C5-2026',
        'KRITIS',
        'KRITIS-HEALTH',
        'DIGAV',
        'TKG-2024',
        'GXP',
        'SOC2',
        'NIST-CSF-2.0',
        'CIS-CONTROLS',
        'EU-AI-ACT',
        'EU-CRA',
        'PCI-DSS-4.0.1',
        'MRIS-v1.5',
        // DACH frameworks (added on main via #962, registry-bound)
        'NISG-AT',
        'REVDSG-CH',
        'IKT-MINSTD-CH',
        // Cloud certification scheme — surfaced in the UI framework list (getAvailableFrameworks)
        'EUCS',
    ];

    /**
     * Legacy / variant code -> canonical code. Only spelling/version aliases that
     * denote the SAME framework as a canonical entry. Used by the consolidation
     * migration and the mapping-id resolver.
     *
     * @var array<string,string>
     */
    public const ALIASES = [
        // ISO 22301 — separator drift
        'ISO22301' => 'ISO-22301',
        'ISO_22301' => 'ISO-22301',
        // BSI IT-Grundschutz — separator drift (underscore is canonical)
        'BSI-GRUNDSCHUTZ' => 'BSI_GRUNDSCHUTZ',
        'BSI-GRUNDSCHUTZ-2024' => 'BSI_GRUNDSCHUTZ',
        // NIST CSF — main's canonical code carries the 2.0 suffix
        'NIST-CSF' => 'NIST-CSF-2.0',
        // SOC 2 — Type II is an attestation flavour, not a separate framework
        'SOC2-TYPE-II' => 'SOC2',
        // German NIS2 transposition — separator/casing drift
        'NIS2-UmsuCG' => 'NIS2UMSUCG',
        'NIS2-UMSUCG' => 'NIS2UMSUCG',
        // KRITIS — DE suffix is redundant
        'KRITIS-DE' => 'KRITIS',
        // EUCS — ENISA prefix variant collapses to the loader code
        'ENISA-EUCS' => 'EUCS',
    ];

    public static function isCanonical(string $code): bool
    {
        return in_array($code, self::CANONICAL, true);
    }

    /**
     * Resolve any code (canonical or alias) to its canonical form, or null if it
     * is neither canonical nor a known alias (i.e. a deferred / unknown framework).
     */
    public static function canonicalize(string $code): ?string
    {
        if (self::isCanonical($code)) {
            return $code;
        }
        return self::ALIASES[$code] ?? null;
    }
}
