<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * NotificationDelivery lifecycle states.
 *
 * Junior-ISB-Audit Phase-2 Lifecycle — extended from the original 4-state set
 * (`pending|sent|failed|retrying`) with two additional terminal stages:
 *   - `Delivered`: positive end-to-end ACK from the receiver (read-receipt,
 *     webhook ACK body, SMTP 250). Distinguished from `Sent` which only means
 *     "handed off to transport". ISO 27001 Cl. 7.4 + DORA Art. 19.
 *   - `Archived`: retention window expired; row retained for forensic audit
 *     but excluded from operational dashboards.
 *
 * Backed by the existing VARCHAR(32) `notification_delivery.status` column —
 * legacy string values are unchanged so no data backfill is needed; the two
 * new values appear only on entities that transition through the canonical
 * `notification_delivery_lifecycle` state-machine.
 *
 * Storage stays string-typed for backward compatibility; this enum is the
 * typed surface for application code in place of bare strings.
 */
enum NotificationDeliveryStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Retrying = 'retrying';
    case Archived = 'archived';
    /**
     * F3 email-digest mode — delivery is queued for batching.
     * The `app:notification:send-digests` command collects all
     * `pending_digest` rows per channel, sends ONE batched email,
     * and transitions them to `Sent`.
     */
    case PendingDigest = 'pending_digest';

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
            self::Pending   => 'neutral',
            self::Sent      => 'info',
            self::Delivered => 'success',
            self::Failed    => 'danger',
            self::Retrying      => 'warning',
            self::Archived      => 'neutral',
            self::PendingDigest => 'info',
        };
    }
}
