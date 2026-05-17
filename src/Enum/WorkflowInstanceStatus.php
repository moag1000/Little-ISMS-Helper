<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * WorkflowInstance lifecycle states (5 stages).
 *
 * Backed by the existing VARCHAR(50) `workflow_instance.status` column —
 * values are unchanged from the pre-enum string literals so no data migration
 * is needed.
 *
 * Storage stays string-typed for backward compatibility; this enum is the
 * typed surface for application code (services, voters, templates) in
 * place of bare strings.
 */
enum WorkflowInstanceStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ instance.status.label|trans({}, 'workflows') }}`.
     */
    public function label(): string
    {
        return 'workflow_instance.status.' . $this->value;
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
            self::Approved   => 'success',
            self::Rejected   => 'danger',
            self::Cancelled  => 'neutral',
        };
    }
}
