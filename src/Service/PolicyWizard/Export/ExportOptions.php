<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Export;

/**
 * Policy-Wizard W7-A — ZIP export options DTO.
 *
 * Encapsulates the auditor-facing toggles for {@see PolicyZipExporter}.
 * Defaults match the "give-me-everything" use case auditors want during
 * a certification audit; toggling individual flags off shrinks the
 * delivered evidence pack.
 *
 * @phpstan-type StandardCode string
 */
final class ExportOptions
{
    public const DEFAULT_STANDARDS = [
        'iso27001',
        'bsi',
        'dora',
        'bcm',
        'gdpr',
        'iso27701',
    ];

    /**
     * @param list<string> $includeStandards Subset of {@see DEFAULT_STANDARDS}
     *                                       to include. Empty array → all.
     */
    public function __construct(
        public readonly bool $includeArchived = false,
        public readonly array $includeStandards = self::DEFAULT_STANDARDS,
        public readonly bool $includeEvidence = true,
    ) {
    }

    /**
     * Whether the given standard code passes the include-filter.
     */
    public function acceptsStandard(string $standard): bool
    {
        if ($this->includeStandards === []) {
            return true;
        }
        return in_array($standard, $this->includeStandards, true);
    }
}
