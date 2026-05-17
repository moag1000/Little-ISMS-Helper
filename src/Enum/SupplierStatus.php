<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Supplier lifecycle states (ISO 27001 Annex A.5.19 — supplier relationships).
 *
 * Backed by the existing `suppliers.status` column. Choice list comes from
 * SupplierType FormType; values are unchanged so no data migration is needed.
 *
 * Phase 1 status-enum rollout — typed surface for application code while
 * the storage column and setStatus(string) accessor stay backward-compatible.
 */
enum SupplierStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Evaluation = 'evaluation';
    case Terminated = 'terminated';

    public function label(): string
    {
        return 'supplier.status.' . $this->value;
    }

    public function pillVariant(): string
    {
        return match ($this) {
            self::Active     => 'success',
            self::Inactive   => 'neutral',
            self::Evaluation => 'info',
            self::Terminated => 'danger',
        };
    }
}
