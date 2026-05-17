<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Training lifecycle states (5 stages).
 *
 * Backed by the existing VARCHAR `training.status` column — values are
 * unchanged from the pre-enum string literals so no data migration is
 * needed.
 *
 * Storage stays string-typed for backward compatibility; this enum is the
 * typed surface for application code in place of bare strings.
 */
enum TrainingStatus: string
{
    case Planned = 'planned';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ training.status.label|trans({}, 'training') }}`.
     */
    public function label(): string
    {
        return 'training.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     * Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Planned    => 'neutral',
            self::Scheduled  => 'info',
            self::InProgress => 'warning',
            self::Completed  => 'success',
            self::Cancelled  => 'neutral',
        };
    }
}
