<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * PrototypeProtectionAssessment lifecycle states (TISAX prototype protection).
 *
 * Backed by the existing `prototype_protection_assessments.status` column —
 * values mirror the legacy `PrototypeProtectionAssessment::STATUS_*` constants.
 * No data migration required: enum cases share string literals with prior code.
 *
 * Phase 1 status-enum rollout — typed surface for application code while
 * the storage column and setStatus(string) accessor stay backward-compatible.
 */
enum PrototypeProtectionAssessmentStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return 'prototype_protection.status.' . $this->value;
    }

    public function pillVariant(): string
    {
        return match ($this) {
            self::Draft    => 'neutral',
            self::InReview => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Expired  => 'neutral',
        };
    }
}
