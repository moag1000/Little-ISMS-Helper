<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Change-request lifecycle states (ISO 27001 §6.3 / §8.1, DORA Art. 6).
 *
 * Backed by the existing VARCHAR(50) `change_request.status` column — values
 * are unchanged from the pre-enum string literals so no data migration is
 * needed.
 *
 * This enum is the typed surface that application code (voters, repositories,
 * templates) uses instead of bare strings.
 */
enum ChangeRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Scheduled = 'scheduled';
    case Implemented = 'implemented';
    case Verified = 'verified';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ change.statusEnum.label|trans({}, 'change_requests') }}`.
     */
    public function label(): string
    {
        return 'change_request.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral)
     * derived from the lifecycle stage. Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Draft       => 'neutral',
            self::Submitted   => 'info',
            self::UnderReview => 'info',
            self::Approved    => 'success',
            self::Rejected    => 'danger',
            self::Scheduled   => 'info',
            self::Implemented => 'warning',
            self::Verified    => 'success',
            self::Closed      => 'neutral',
            self::Cancelled   => 'neutral',
        };
    }
}
