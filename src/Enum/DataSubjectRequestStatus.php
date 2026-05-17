<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * DataSubjectRequest (GDPR Art. 15-22) lifecycle states.
 *
 * Backed by the existing VARCHAR(20) `data_subject_requests.status` column —
 * values are unchanged from the pre-enum string literals so no data
 * migration is needed.
 *
 * Phase 1 (foundation only): callers still pass and receive strings via
 * `setStatus()` / `getStatus()`. This enum provides a typed surface via
 * `getStatusEnum()` and is intended as the migration target for Phase 2.
 */
enum DataSubjectRequestStatus: string
{
    case Received = 'received';
    case IdentityVerification = 'identity_verification';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Extended = 'extended';

    /**
     * Translation-key for the human-readable status label.
     */
    public function label(): string
    {
        return 'data_subject_request.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Received             => 'info',
            self::IdentityVerification => 'warning',
            self::InProgress           => 'info',
            self::Completed            => 'success',
            self::Rejected             => 'danger',
            self::Extended             => 'warning',
        };
    }
}
