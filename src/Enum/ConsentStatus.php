<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Consent (GDPR Art. 6/7) lifecycle states.
 *
 * Backed by the existing VARCHAR(50) `consents.status` column — values are
 * unchanged from the pre-enum string literals so no data migration is needed.
 *
 * Phase 1 (foundation only): callers still pass and receive strings via
 * `setStatus()` / `getStatus()`. This enum provides a typed surface via
 * `getStatusEnum()` and is intended as the migration target for Phase 2.
 */
enum ConsentStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case PendingVerification = 'pending_verification';
    case Rejected = 'rejected';

    /**
     * Translation-key for the human-readable status label.
     */
    public function label(): string
    {
        return 'consent.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Active              => 'success',
            self::Revoked             => 'neutral',
            self::Expired             => 'warning',
            self::PendingVerification => 'info',
            self::Rejected            => 'danger',
        };
    }
}
