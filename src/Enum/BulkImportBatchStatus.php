<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * BulkImportBatch lifecycle states (7 stages, Upload → Map → Preview → Commit).
 *
 * Backed by the existing VARCHAR(32) `bulk_import_batch.status` column —
 * values are unchanged from the pre-enum string literals so no data
 * migration is needed.
 *
 * Storage stays string-typed for backward compatibility; this enum is the
 * typed surface for application code in place of bare strings.
 */
enum BulkImportBatchStatus: string
{
    case Uploaded = 'uploaded';
    case Mapped = 'mapped';
    case Preview = 'preview';
    case Committing = 'committing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ batch.status.label|trans({}, 'compliance_import') }}`.
     */
    public function label(): string
    {
        return 'bulk_import_batch.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     * Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Uploaded   => 'neutral',
            self::Mapped     => 'info',
            self::Preview    => 'info',
            self::Committing => 'warning',
            self::Completed  => 'success',
            self::Failed     => 'danger',
            self::Cancelled  => 'neutral',
        };
    }
}
