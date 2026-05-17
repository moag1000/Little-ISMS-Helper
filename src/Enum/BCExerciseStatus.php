<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Business-continuity-exercise lifecycle states (ISO 22301 Clause 8.5).
 *
 * Backed by the existing VARCHAR `bc_exercise.status` column — values are
 * unchanged from the pre-enum string literals so no data migration is needed.
 *
 * This enum is the typed surface that application code (voters, repositories,
 * templates) uses instead of bare strings.
 */
enum BCExerciseStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ exercise.statusEnum.label|trans({}, 'bc_exercises') }}`.
     */
    public function label(): string
    {
        return 'bc_exercises.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral)
     * derived from the lifecycle stage. Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Planned    => 'info',
            self::InProgress => 'warning',
            self::Completed  => 'success',
            self::Cancelled  => 'neutral',
        };
    }
}
