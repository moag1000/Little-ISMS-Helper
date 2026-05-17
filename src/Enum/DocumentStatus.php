<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Document-lifecycle states (Lifecycle Foundation Pilot, canonical 5-stage).
 *
 * Backed by the existing VARCHAR(50) `documents.status` column — values are
 * unchanged from the pre-enum string literals so no data migration is needed.
 *
 * Workflow transitions are driven by `LifecycleService::transition()` against
 * `config/workflows/document.yaml`; this enum is the typed surface that
 * application code (voters, repositories, templates) uses instead of bare
 * strings.
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ doc.status.label|trans({}, 'document') }}`.
     */
    public function label(): string
    {
        return 'document.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral)
     * derived from the lifecycle stage. Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Draft     => 'neutral',
            self::InReview  => 'info',
            self::Approved  => 'warning',
            self::Published => 'success',
            self::Archived  => 'neutral',
        };
    }
}
