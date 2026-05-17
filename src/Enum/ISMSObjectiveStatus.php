<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ISMS-objective lifecycle states (ISO 27001 Clause 6.2).
 *
 * Backed by the existing VARCHAR `isms_objective.status` column — values are
 * unchanged from the pre-enum string literals so no data migration is needed.
 *
 * This enum is the typed surface that application code (voters, repositories,
 * templates) uses instead of bare strings.
 */
enum ISMSObjectiveStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Achieved = 'achieved';
    case Delayed = 'delayed';
    case Cancelled = 'cancelled';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ objective.statusEnum.label|trans({}, 'objective') }}`.
     */
    public function label(): string
    {
        return 'objective.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral)
     * derived from the lifecycle stage. Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::NotStarted => 'neutral',
            self::InProgress => 'info',
            self::Achieved   => 'success',
            self::Delayed    => 'warning',
            self::Cancelled  => 'neutral',
        };
    }
}
