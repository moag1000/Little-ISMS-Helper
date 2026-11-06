<?php

declare(strict_types=1);

namespace App\Service\Catalog;

/**
 * Known requirementId prefix variants per framework code (single source of truth).
 *
 * Loader conventions are inconsistent (ISO27701 stores "27701-5.2.1", EU-AI-ACT
 * stores "AIACT-1", ...), so importers and resolvers probe a small alias map
 * instead of guessing generically. Alias framework codes (NIST-CSF, ISO22301,
 * NIS2-UmsuCG, ...) are canonicalised first via FrameworkCode.
 */
final class RequirementIdPrefixes
{
    /** @var array<string, list<string>> */
    private const MAP = [
        'DORA' => ['DORA'],
        'GDPR' => ['GDPR'],
        'BDSG' => ['BDSG'],
        'NIS2' => ['NIS2'],
        'NIS2UMSUCG' => ['NIS2UMSUCG', 'NIS2UmsuCG', 'NIS2-UmsuCG', 'BSIG'],
        'ISO27001' => ['ISO27001'],
        'ISO27005' => ['27005', 'ISO27005'],
        'ISO27018' => ['ISO27018', '27018'],
        'ISO27701' => ['27701', 'ISO27701'],
        'ISO42001' => ['ISO42001', '42001', 'AIMS'],
        'ISO-22301' => ['ISO22301', 'ISO-22301', '22301'],
        'EU-AI-ACT' => ['AIACT', 'EUAIACT', 'EU-AI-ACT'],
        'BSI-C5-2026' => ['C5-2026', 'C52026', 'BSI-C5-2026'],
        'BSI-C5' => ['C5', 'BSI-C5'],
        'CIS-CONTROLS' => ['CIS', 'CIS-CONTROLS'],
        'TKG-2024' => ['TKG', 'TKG-2024'],
        'KRITIS' => ['KRITIS'],
        'NIST-CSF-2.0' => ['NIST-CSF', 'NISTCSF', 'NIST-CSF-2.0'],
        'TISAX' => ['TISAX', 'VDA', 'VDA-ISA'],
    ];

    /**
     * @return list<string>
     */
    public static function forFramework(string $code): array
    {
        $canonical = FrameworkCode::canonicalize($code) ?? $code;
        if (isset(self::MAP[$canonical])) {
            return self::MAP[$canonical];
        }

        return array_values(array_unique([$code, str_replace(['-', '_'], '', $code)]));
    }
}
