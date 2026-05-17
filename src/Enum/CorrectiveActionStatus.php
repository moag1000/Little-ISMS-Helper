<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * CorrectiveAction lifecycle states (CAPA remediation tracking).
 *
 * Backed by the existing VARCHAR(50) `corrective_actions.status` column —
 * values mirror the legacy `CorrectiveAction::STATUS_*` constants. No data
 * migration required: enum cases share string literals with prior code.
 *
 * Phase 1 status-enum rollout — typed surface for application code while
 * the storage column and setStatus(string) accessor stay backward-compatible.
 */
enum CorrectiveActionStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case VerifiedEffective = 'verified_effective';
    case VerifiedIneffective = 'verified_ineffective';

    public function label(): string
    {
        return 'corrective_action.status.' . $this->value;
    }

    public function pillVariant(): string
    {
        return match ($this) {
            self::Planned              => 'neutral',
            self::InProgress           => 'info',
            self::Completed            => 'warning',
            self::VerifiedEffective    => 'success',
            self::VerifiedIneffective  => 'danger',
        };
    }
}
