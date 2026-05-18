<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Internal-audit lifecycle states (ISO 27001 Clause 9.2).
 *
 * Backed by the existing VARCHAR `internal_audit.status` column — values are
 * unchanged from the pre-enum string literals so no data migration is needed.
 * Canonical stages plus legacy buckets (`in_progress`, `completed`,
 * `postponed`) carried over for back-compat — see
 * `InternalAudit::LIFECYCLE_STAGES` for the authoritative transition graph.
 *
 * This enum is the typed surface that application code (voters, repositories,
 * templates) uses instead of bare strings.
 */
enum InternalAuditStatus: string
{
    case Planned = 'planned';
    case Conducted = 'conducted';
    case Reported = 'reported';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Postponed = 'postponed';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ audit.statusEnum.label|trans({}, 'audit') }}`.
     * Keys live under the `audit:` root in `translations/audit.{de,en}.yaml`.
     */
    public function label(): string
    {
        return 'audit.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral)
     * derived from the lifecycle stage. Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Planned    => 'info',
            self::Conducted  => 'warning',
            self::Reported   => 'info',
            self::Approved   => 'success',
            self::Rejected   => 'danger',
            self::Closed     => 'neutral',
            self::Cancelled  => 'neutral',
            self::InProgress => 'warning',
            self::Completed  => 'success',
            self::Postponed  => 'neutral',
        };
    }
}
