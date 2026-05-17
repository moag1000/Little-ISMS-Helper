<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * WizardSession lifecycle states (3 stages).
 *
 * Backed by the existing VARCHAR `wizard_session.status` column — values
 * are unchanged from the pre-enum string literals so no data migration
 * is needed.
 *
 * Storage stays string-typed for backward compatibility; this enum is the
 * typed surface for application code in place of bare strings.
 */
enum WizardSessionStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Abandoned = 'abandoned';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ session.status.label|trans({}, 'wizard') }}`.
     */
    public function label(): string
    {
        return 'wizard_session.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     * Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::InProgress => 'info',
            self::Completed  => 'success',
            self::Abandoned  => 'neutral',
        };
    }
}
