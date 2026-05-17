<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * DataProtectionImpactAssessment (GDPR Art. 35/36) lifecycle states.
 *
 * Backed by the existing VARCHAR(30)
 * `data_protection_impact_assessments.status` column — values are unchanged
 * from the pre-enum string literals so no data migration is needed.
 *
 * Phase 1 (foundation only): callers still pass and receive strings via
 * `setStatus()` / `getStatus()`. This enum provides a typed surface via
 * `getStatusEnum()` and is intended as the migration target for Phase 2.
 */
enum DpiaStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case RequiresRevision = 'requires_revision';

    /**
     * Translation-key for the human-readable status label.
     */
    public function label(): string
    {
        return 'dpia.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Draft            => 'neutral',
            self::InReview         => 'info',
            self::Approved         => 'success',
            self::Rejected         => 'danger',
            self::RequiresRevision => 'warning',
        };
    }
}
