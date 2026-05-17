<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * ComplianceRequirementFulfillment implementation states.
 *
 * Backed by the existing `compliance_requirement_fulfillments.status` column —
 * values mirror the entity-level `$allowedStatuses` whitelist. No data
 * migration required: enum cases share string literals with prior code.
 *
 * Phase 1 status-enum rollout — typed surface for application code while
 * the storage column and setStatus(string) accessor stay backward-compatible.
 */
enum ComplianceRequirementFulfillmentStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Implemented = 'implemented';
    case Verified = 'verified';

    public function label(): string
    {
        return 'compliance.fulfillment.status.' . $this->value;
    }

    public function pillVariant(): string
    {
        return match ($this) {
            self::NotStarted  => 'neutral',
            self::InProgress  => 'info',
            self::Implemented => 'warning',
            self::Verified    => 'success',
        };
    }
}
