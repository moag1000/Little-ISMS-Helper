<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * NotificationDelivery lifecycle states (4 stages).
 *
 * Backed by the existing VARCHAR(32) `notification_delivery.status` column —
 * values are unchanged from the pre-enum string literals so no data
 * migration is needed.
 *
 * Storage stays string-typed for backward compatibility; this enum is the
 * typed surface for application code in place of bare strings.
 */
enum NotificationDeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Retrying = 'retrying';

    /**
     * Translation-key for the human-readable status label.
     * Used by Twig: `{{ delivery.status.label|trans({}, 'notifications') }}`.
     */
    public function label(): string
    {
        return 'notification_delivery.status.' . $this->value;
    }

    /**
     * Aurora status-pill variant (success/info/warning/danger/neutral).
     * Used by `_fa_status_pill.html.twig`.
     */
    public function pillVariant(): string
    {
        return match ($this) {
            self::Pending  => 'neutral',
            self::Sent     => 'success',
            self::Failed   => 'danger',
            self::Retrying => 'warning',
        };
    }
}
