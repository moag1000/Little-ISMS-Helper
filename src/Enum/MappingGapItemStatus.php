<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Compliance-gap-item lifecycle states (mapping-result follow-up).
 *
 * Backed by the existing VARCHAR `mapping_gap_item.status` column — values are
 * unchanged from the pre-enum string literals so no data migration is needed.
 *
 * This enum is the typed surface that application code (voters, repositories,
 * templates) uses instead of bare strings.
 */
enum MappingGapItemStatus: string
{
    case Identified = 'identified';
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case WontFix = 'wont_fix';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ gap.statusEnum.label|trans({}, 'compliance') }}`.
     */
    public function label(): string
    {
        return 'mapping_gap_item.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral)
     * derived from the lifecycle stage. Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Identified => 'neutral',
            self::Planned    => 'info',
            self::InProgress => 'warning',
            self::Resolved   => 'success',
            self::WontFix    => 'neutral',
        };
    }
}
