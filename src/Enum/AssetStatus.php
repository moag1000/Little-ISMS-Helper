<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Asset lifecycle states (ISO 27001 Annex A.5.9 — asset inventory lifecycle).
 *
 * Backed by the existing `assets.status` column. Choice list comes from
 * AssetType FormType + `asset_lifecycle` Symfony Workflow places; values are
 * unchanged so no data migration is needed.
 *
 * Phase 1 status-enum rollout — typed surface for application code while
 * the storage column and setStatus(string) accessor stay backward-compatible.
 */
enum AssetStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case InUse = 'in_use';
    case Returned = 'returned';
    case Retired = 'retired';
    case Disposed = 'disposed';

    public function label(): string
    {
        return 'asset.status.' . $this->value;
    }

    public function pillVariant(): string
    {
        return match ($this) {
            self::Draft    => 'neutral',
            self::Active   => 'success',
            self::Inactive => 'neutral',
            self::InUse    => 'info',
            self::Returned => 'warning',
            self::Retired  => 'neutral',
            self::Disposed => 'danger',
        };
    }
}
