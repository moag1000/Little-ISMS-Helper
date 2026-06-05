<?php

declare(strict_types=1);

namespace App\Service\Authority;

/**
 * Shared ESA-RoI field-mapping helpers used by both the XBRL-XML exporter
 * ({@see DoraRoiXbrlExporter}) and the xBRL-CSV (OIM) exporter
 * ({@see DoraRoiCsvExporter}) so the two submission formats stay in lockstep.
 */
trait DoraRoiFieldMappingTrait
{
    /**
     * Maps internal criticality strings to ESA RoI classification values
     * (critical | important | other).
     */
    private function mapCriticalityToEsa(?string $criticality): string
    {
        return match (strtolower((string) $criticality)) {
            'critical', 'hoch', 'high' => 'critical',
            'medium', 'mittel', 'important' => 'important',
            default => 'other',
        };
    }

    /**
     * EEA = EU-27 + Iceland (IS) + Liechtenstein (LI) + Norway (NO).
     * Switzerland (CH) is EFTA but NOT EEA. Unknown → false (stricter signal).
     */
    private function isEeaCountryCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        $eea = [
            'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR',
            'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL',
            'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
            'IS', 'LI', 'NO',
        ];

        return in_array(strtoupper($code), $eea, true);
    }
}
