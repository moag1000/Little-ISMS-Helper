<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * FourEyesApprovalRequest lifecycle states (segregation-of-duties workflow).
 *
 * Backed by the existing `four_eyes_approval_requests.status` column — values
 * mirror the legacy `FourEyesApprovalRequest::STATUS_*` constants. No data
 * migration required: enum cases share string literals with prior code.
 *
 * Phase 1 status-enum rollout — typed surface for application code while
 * the storage column and setStatus(string) accessor stay backward-compatible.
 */
enum FourEyesApprovalRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return 'four_eyes.status.' . $this->value;
    }

    public function pillVariant(): string
    {
        return match ($this) {
            self::Pending  => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Expired  => 'neutral',
        };
    }
}
