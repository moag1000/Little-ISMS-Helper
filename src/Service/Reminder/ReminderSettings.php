<?php

declare(strict_types=1);

namespace App\Service\Reminder;

use App\Entity\Tenant;

/**
 * The organisation's reminder settings, kept in the tenant's notification
 * preferences (key "digest"): the local hour of the daily digest.
 */
final class ReminderSettings
{
    public const int DEFAULT_DIGEST_HOUR = 7;

    public static function digestHour(Tenant $tenant): int
    {
        $hour = ($tenant->getNotificationPreferences() ?? [])['digest']['hour'] ?? self::DEFAULT_DIGEST_HOUR;

        return max(0, min(23, (int) $hour));
    }

    public static function setDigestHour(Tenant $tenant, int $hour): void
    {
        $preferences = $tenant->getNotificationPreferences() ?? [];
        $preferences['digest'] = ['hour' => max(0, min(23, $hour))];
        $tenant->setNotificationPreferences($preferences);
    }
}
