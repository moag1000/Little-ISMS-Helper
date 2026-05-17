<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Threat-led-penetration-test (TLPT) lifecycle states (DORA Art. 26).
 *
 * Backed by the existing VARCHAR(30) `threat_led_penetration_test.status`
 * column — values are unchanged from the pre-enum string literals so no data
 * migration is needed.
 *
 * This enum is the typed surface that application code (voters, repositories,
 * templates) uses instead of bare strings.
 */
enum ThreatLedPenetrationTestStatus: string
{
    case Planned = 'planned';
    case Scoping = 'scoping';
    case RedTeam = 'red_team';
    case Reporting = 'reporting';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ tlpt.statusEnum.label|trans({}, 'tlpt') }}`.
     */
    public function label(): string
    {
        return 'tlpt.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral)
     * derived from the lifecycle stage. Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Planned   => 'info',
            self::Scoping   => 'info',
            self::RedTeam   => 'warning',
            self::Reporting => 'warning',
            self::Closed    => 'success',
            self::Cancelled => 'neutral',
        };
    }
}
