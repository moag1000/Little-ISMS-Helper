<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Lifecycle of a top-management decision (management_decision_lifecycle).
 * A newer approval of the same type and subject supersedes the older one.
 */
enum ManagementDecisionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Superseded = 'superseded';

    public function label(): string
    {
        return 'management_decision.status.' . $this->value;
    }

    public function pillVariant(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Withdrawn, self::Superseded => 'neutral',
        };
    }
}
