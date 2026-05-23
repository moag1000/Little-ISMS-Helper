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
    // Junior-ISB-Audit-2026-05-22 CAPA-Lifecycle: NEW intermediate `verified`
    // state forces the reviewer through a `completed → verified` step
    // (ISO 27001 Cl. 10.1 d). See config/workflows/corrective_action.yaml.
    case Verified = 'verified';
    case VerifiedEffective = 'verified_effective';
    case VerifiedIneffective = 'verified_ineffective';
    case Cancelled = 'cancelled';

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
            self::Verified             => 'info',
            self::VerifiedEffective    => 'success',
            self::VerifiedIneffective  => 'danger',
            self::Cancelled            => 'neutral',
        };
    }
}
