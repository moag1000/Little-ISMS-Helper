<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * BulkImportRow per-row lifecycle states (6 stages).
 *
 * Backed by the existing VARCHAR(16) `bulk_import_row.status` column —
 * values are unchanged from the pre-enum string literals so no data
 * migration is needed.
 *
 * Storage stays string-typed for backward compatibility; this enum is the
 * typed surface for application code in place of bare strings.
 */
enum BulkImportRowStatus: string
{
    case Pending = 'pending';
    case Created = 'created';
    case Updated = 'updated';
    case Unchanged = 'unchanged';
    case Skipped = 'skipped';
    case Error = 'error';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ row.status.label|trans({}, 'compliance_import') }}`.
     */
    public function label(): string
    {
        return 'bulk_import_row.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     * Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Pending   => 'neutral',
            self::Created   => 'success',
            self::Updated   => 'info',
            self::Unchanged => 'neutral',
            self::Skipped   => 'warning',
            self::Error     => 'danger',
        };
    }
}
