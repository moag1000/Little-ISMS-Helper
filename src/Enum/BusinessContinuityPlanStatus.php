<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Business-continuity-plan lifecycle states (ISO 22301 Clause 8.4).
 *
 * Backed by the existing VARCHAR `business_continuity_plan.status` column —
 * values are unchanged from the pre-enum string literals so no data migration
 * is needed.
 *
 * This enum is the typed surface that application code (voters, repositories,
 * templates) uses instead of bare strings.
 */
enum BusinessContinuityPlanStatus: string
{
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case Active = 'active';
    case Archived = 'archived';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ plan.statusEnum.label|trans({}, 'bc_plans') }}`.
     */
    public function label(): string
    {
        return 'bc_plans.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral)
     * derived from the lifecycle stage. Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Draft       => 'neutral',
            self::UnderReview => 'info',
            self::Active      => 'success',
            self::Archived    => 'neutral',
        };
    }
}
