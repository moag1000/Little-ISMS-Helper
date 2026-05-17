<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * DataBreach (GDPR Art. 33/34) lifecycle states.
 *
 * Backed by the existing VARCHAR(30) `data_breaches.status` column — values
 * are unchanged from the pre-enum string literals so no data migration is
 * needed.
 *
 * Phase 1 (foundation only): callers still pass and receive strings via
 * `setStatus()` / `getStatus()`. This enum provides a typed surface via
 * `getStatusEnum()` and is intended as the migration target for Phase 2.
 */
enum DataBreachStatus: string
{
    case Draft = 'draft';
    case UnderAssessment = 'under_assessment';
    case AuthorityNotified = 'authority_notified';
    case SubjectsNotified = 'subjects_notified';
    case Closed = 'closed';

    /**
     * Translation-key for the human-readable status label.
     */
    public function label(): string
    {
        return 'data_breach.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Draft             => 'neutral',
            self::UnderAssessment   => 'info',
            self::AuthorityNotified => 'warning',
            self::SubjectsNotified  => 'warning',
            self::Closed            => 'success',
        };
    }
}
