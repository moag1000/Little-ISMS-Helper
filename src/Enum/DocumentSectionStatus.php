<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * DocumentSection lifecycle states.
 *
 * Backed by the existing VARCHAR `document_sections.status` column — values
 * are unchanged from the pre-enum string literals so no data migration is
 * needed.
 *
 * Phase 1 (foundation only): callers still pass and receive strings via
 * `setStatus()` / `getStatus()`. This enum provides a typed surface via
 * `getStatusEnum()` and is intended as the migration target for Phase 2.
 */
enum DocumentSectionStatus: string
{
    case Draft = 'draft';
    case DpoSignOff = 'dpo_sign_off';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /**
     * Translation-key for the human-readable status label.
     */
    public function label(): string
    {
        return 'document_section.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Draft      => 'neutral',
            self::DpoSignOff => 'info',
            self::Approved   => 'success',
            self::Rejected   => 'danger',
        };
    }
}
