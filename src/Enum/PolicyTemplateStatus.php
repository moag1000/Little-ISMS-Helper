<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * PolicyTemplate lifecycle states (canonical 5-stage).
 *
 * Backed by the existing VARCHAR(32) `policy_templates.status` column —
 * values are unchanged from the pre-enum string literals so no data
 * migration is needed.
 *
 * Phase 1 (foundation only): callers still pass and receive strings via
 * `setStatus()` / `getStatus()`. This enum provides a typed surface via
 * `getStatusEnum()` and is intended as the migration target for Phase 2.
 */
enum PolicyTemplateStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * Translation-key for the human-readable status label.
     */
    public function label(): string
    {
        return 'policy_template.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Draft     => 'neutral',
            self::InReview  => 'info',
            self::Approved  => 'warning',
            self::Published => 'success',
            self::Archived  => 'neutral',
        };
    }
}
