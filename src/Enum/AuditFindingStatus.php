<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Audit-finding lifecycle states (ISO 27001 Clause 10.1).
 *
 * Backed by the existing VARCHAR(30) `audit_finding.status` column — values
 * are unchanged from the pre-enum string literals so no data migration is
 * needed.
 *
 * This enum is the typed surface that application code (voters, repositories,
 * templates) uses instead of bare strings. Existing string call-sites keep
 * working via the dual-input `Entity::setStatus()` accessor.
 */
enum AuditFindingStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Verified = 'verified';
    case Closed = 'closed';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ finding.statusEnum.label|trans({}, 'audit_finding') }}`.
     */
    public function label(): string
    {
        return 'audit_finding.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral)
     * derived from the lifecycle stage. Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Open       => 'danger',
            self::InProgress => 'warning',
            self::Resolved   => 'info',
            self::Verified   => 'success',
            self::Closed     => 'neutral',
        };
    }
}
