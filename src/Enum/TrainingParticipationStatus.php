<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * TrainingParticipation lifecycle states (5 stages).
 *
 * Backed by the existing VARCHAR(24) `training_participation.status` column —
 * values are unchanged from the pre-enum string literals so no data
 * migration is needed.
 *
 * Storage stays string-typed for backward compatibility; this enum is the
 * typed surface for application code in place of bare strings.
 */
enum TrainingParticipationStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Waived = 'waived';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ participation.status.label|trans({}, 'training') }}`.
     */
    public function label(): string
    {
        return 'training_participation.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     * Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Pending    => 'neutral',
            self::InProgress => 'info',
            self::Completed  => 'success',
            self::Failed     => 'danger',
            self::Waived     => 'neutral',
        };
    }
}
