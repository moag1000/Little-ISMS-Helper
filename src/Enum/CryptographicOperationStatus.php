<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * CryptographicOperation result states (crypto audit-trail).
 *
 * Backed by the existing `cryptographic_operations.status` column. Values
 * mirror the entity-level Assert\Choice list; no data migration is needed.
 *
 * Phase 1 status-enum rollout — typed surface for application code while
 * the storage column and setStatus(string) accessor stay backward-compatible.
 */
enum CryptographicOperationStatus: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Pending = 'pending';

    public function label(): string
    {
        return 'crypto.status.' . $this->value;
    }

    public function pillVariant(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::Failure => 'danger',
            self::Pending => 'info',
        };
    }
}
