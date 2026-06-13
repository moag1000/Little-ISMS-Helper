<?php

declare(strict_types=1);

namespace App\Service\Catalog;

/**
 * Canonical compliance-framework code registry (single source of truth).
 *
 * Background: framework codes drifted into spelling/version collisions
 * (ISO-22301 vs ISO22301 vs ISO_22301, NIS2UMSUCG vs NIS2-UmsuCG, ...). The
 * `ComplianceFrameworkLoaderService` registry + the seed-mapping commands use the
 * CANONICAL spellings below; everything else (legacy DB rows, fixture/library
 * framework identifiers) must resolve onto these.
 *
 * Decision (2026-06-13): canonical = what the UI registry + seeds already use.
 *
 * This class is consumed by:
 *  - the merge/rename migration that consolidates legacy alias rows,
 *  - the mapping importer (resolve library framework id -> canonical code),
 *  - check_compliance_catalog.py (indirectly — collision baseline must reach 0).
 *
 * NOT covered here (deferred to WS-2.2 per decision — frameworks referenced by
 * mappings but with no loader, kept untouched for now): BAIT, ENISA-EUCS,
 * TISAX-VDA-ISA-6, BSI-GRUNDSCHUTZ-KERN, BSI-GRUNDSCHUTZ-STANDARD, iso27002,
 * iec-isa-62443, nist-csf-1.1, nist-sp800-53r5.
 */
final class FrameworkCode
{
    /**
     * Canonical framework codes — mirror of ComplianceFrameworkLoaderService
     * getAvailableFrameworks(). check_compliance_catalog.py parity keeps these in sync.
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
        'NIST-CSF',
        'CIS-CONTROLS',
        'EU-AI-ACT',
        'EU-CRA',
        'PCI-DSS-4.0.1',
        'MRIS-v1.5',
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
        // NIST CSF — the catalogue IS 2.0; canonical code carries no version suffix
        'NIST-CSF-2.0' => 'NIST-CSF',
        // SOC 2 — Type II is an attestation flavour, not a separate framework
        'SOC2-TYPE-II' => 'SOC2',
        // German NIS2 transposition — separator/casing drift
        'NIS2-UmsuCG' => 'NIS2UMSUCG',
        'NIS2-UMSUCG' => 'NIS2UMSUCG',
        // KRITIS — DE suffix is redundant
        'KRITIS-DE' => 'KRITIS',
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
