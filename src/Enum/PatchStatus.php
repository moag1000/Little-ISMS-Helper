<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Patch lifecycle states (patch management workflow).
 *
 * Backed by the existing `patches.status` column. Choice list comes from
 * PatchType FormType; values are unchanged so no data migration is needed.
 *
 * Phase 1 status-enum rollout — typed surface for application code while
 * the storage column and setStatus(string) accessor stay backward-compatible.
 */
enum PatchStatus: string
{
    case Pending = 'pending';
    case Testing = 'testing';
    case Approved = 'approved';
    case Deployed = 'deployed';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';
    case NotApplicable = 'not_applicable';

    public function label(): string
    {
        return 'patch.status.' . $this->value;
    }

    public function pillVariant(): string
    {
        return match ($this) {
            self::Pending       => 'neutral',
            self::Testing       => 'info',
            self::Approved      => 'warning',
            self::Deployed      => 'success',
            self::Failed        => 'danger',
            self::RolledBack    => 'danger',
            self::NotApplicable => 'neutral',
        };
    }
}
