<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ImportSession lifecycle states (3 stages).
 *
 * Backed by the existing VARCHAR(20) `import_session.status` column —
 * values are unchanged from the pre-enum string literals so no data
 * migration is needed.
 *
 * Storage stays string-typed for backward compatibility; this enum is the
 * typed surface for application code in place of bare strings.
 */
enum ImportSessionStatus: string
{
    case Preview = 'preview';
    case Committed = 'committed';
    case Cancelled = 'cancelled';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ session.status.label|trans({}, 'compliance_import') }}`.
     */
    public function label(): string
    {
        return 'import_session.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     * Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Preview   => 'info',
            self::Committed => 'success',
            self::Cancelled => 'neutral',
        };
    }
}
