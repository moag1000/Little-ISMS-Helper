<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\ComplianceMapping;

/**
 * Value rules for one row of the compliance-mapping CSV import — the same set the
 * mapping form and wizard allow, so preview and commit refuse what the UI refuses
 * instead of writing a cast or free-text value.
 *
 *  - mapping_percentage: whole number 0 … {@see ComplianceMapping::MAX_PERCENTAGE}
 *  - mapping_type:       {@see ComplianceMapping::MAPPING_TYPES}
 *  - confidence:         {@see ComplianceMapping::CONFIDENCE_LEVELS}
 *
 * Type and confidence are compared trimmed and case-insensitive; the importer
 * writes the normalised value ({@see self::normalised()}).
 */
final class ComplianceMappingRowRules
{
    /**
     * Names of the columns whose value is not allowed; empty when the row is fine.
     *
     * @param array<string, mixed> $data row keyed by lower-case header
     *
     * @return list<string>
     */
    public static function invalidColumns(array $data): array
    {
        $invalid = [];

        $percentage = trim((string) ($data['mapping_percentage'] ?? ''));
        if (preg_match('/^\d{1,3}$/', $percentage) !== 1 || (int) $percentage > ComplianceMapping::MAX_PERCENTAGE) {
            $invalid[] = 'mapping_percentage';
        }
        if (!in_array(self::normalised($data['mapping_type'] ?? ''), ComplianceMapping::MAPPING_TYPES, true)) {
            $invalid[] = 'mapping_type';
        }
        if (!in_array(self::normalised($data['confidence'] ?? ''), ComplianceMapping::CONFIDENCE_LEVELS, true)) {
            $invalid[] = 'confidence';
        }

        return $invalid;
    }

    public static function normalised(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
