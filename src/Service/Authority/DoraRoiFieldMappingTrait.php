<?php

declare(strict_types=1);

namespace App\Service\Authority;

use App\Privacy\GdprTransferZones;

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
     * EEA = EU-27 + Iceland (IS) + Liechtenstein (LI) + Norway (NO), from the
     * shared list in config/gdpr_transfer_zones.yaml. Switzerland (CH) is EFTA
     * but NOT EEA. Unknown → false (stricter signal).
     */
    private function isEeaCountryCode(?string $code): bool
    {
        return GdprTransferZones::isEea($code);
    }
}
