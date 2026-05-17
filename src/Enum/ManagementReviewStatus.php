<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Management-review lifecycle states (ISO 27001 Clause 9.3).
 *
 * Backed by the existing VARCHAR `management_review.status` column — values
 * are unchanged from the pre-enum string literals so no data migration is
 * needed.
 *
 * This enum is the typed surface that application code (voters, repositories,
 * templates) uses instead of bare strings.
 */
enum ManagementReviewStatus: string
{
    case Planned = 'planned';
    case Completed = 'completed';
    case FollowUpRequired = 'follow_up_required';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ review.statusEnum.label|trans({}, 'management_review') }}`.
     */
    public function label(): string
    {
        return 'management_review.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral)
     * derived from the lifecycle stage. Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Planned          => 'info',
            self::Completed        => 'success',
            self::FollowUpRequired => 'warning',
        };
    }
}
