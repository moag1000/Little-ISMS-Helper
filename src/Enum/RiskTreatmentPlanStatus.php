<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Risk-treatment-plan lifecycle states (ISO 27001 Clause 6.1.3).
 *
 * Backed by the existing VARCHAR `risk_treatment_plan.status` column — values
 * are unchanged from the pre-enum string literals so no data migration is
 * needed.
 *
 * This enum is the typed surface that application code (voters, repositories,
 * templates) uses instead of bare strings.
 */
enum RiskTreatmentPlanStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case OnHold = 'on_hold';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ plan.statusEnum.label|trans({}, 'risk_treatment_plan') }}`.
     */
    public function label(): string
    {
        return 'risk_treatment_plan.status.' . $this->value;
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
            self::OnHold     => 'neutral',
        };
    }
}
