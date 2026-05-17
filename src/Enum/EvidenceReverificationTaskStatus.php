<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * EvidenceReverificationTask lifecycle states.
 *
 * Backed by the existing VARCHAR(20)
 * `evidence_reverification_tasks.status` column — values are unchanged from
 * the pre-enum string literals so no data migration is needed.
 *
 * Phase 1 (foundation only): callers still pass and receive strings via
 * `setStatus()` / `getStatus()`. This enum provides a typed surface via
 * `getStatusEnum()` and is intended as the migration target for Phase 2.
 */
enum EvidenceReverificationTaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Skipped = 'skipped';

    /**
     * Translation-key for the human-readable status label.
     */
    public function label(): string
    {
        return 'evidence_reverification_task.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Pending    => 'warning',
            self::InProgress => 'info',
            self::Completed  => 'success',
            self::Skipped    => 'neutral',
        };
    }
}
