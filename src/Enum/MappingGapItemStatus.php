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

    /**
     * Allowed lifecycle moves. Work moves forward (identified → planned →
     * in_progress → resolved); `wont_fix` can be decided from any open state;
     * closed items (resolved / wont_fix) are only reopened, never jumped
     * between. Staying on the current status is always allowed (no-op).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Identified => [self::Planned, self::InProgress, self::WontFix],
            self::Planned    => [self::Identified, self::InProgress, self::WontFix],
            self::InProgress => [self::Planned, self::Resolved, self::WontFix],
            self::Resolved   => [self::InProgress],
            self::WontFix    => [self::Identified],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return $target === $this || in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Validation message for moving from this status to $target, or null when
     * the move is allowed. A null $target means the requested value is not a
     * known status at all.
     */
    public function transitionError(?self $target): ?string
    {
        $values = static fn (array $statuses): string => implode(', ', array_map(
            static fn (self $s): string => $s->value,
            $statuses,
        ));

        if ($target === null) {
            return 'Invalid status. Must be one of: ' . $values(self::cases());
        }
        if (!$this->canTransitionTo($target)) {
            return sprintf(
                'Status change %s → %s is not allowed. Allowed: %s',
                $this->value,
                $target->value,
                $values($this->allowedTransitions()),
            );
        }

        return null;
    }
}
