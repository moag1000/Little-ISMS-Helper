<?php

declare(strict_types=1);

namespace App\Service\Notification;

/**
 * What of a record's state goes into a notification mail (immediate or digest):
 * only the fields that say which record and how urgent. Mail leaves the system;
 * descriptions, personal data and free text stay in the tool behind the login.
 */
final class NotificationMailSummary
{
    /** Keys a notification mail may carry. */
    public const array FIELDS = [
        'entity_type', 'entity_id', 'id', 'title', 'severity', 'status', 'score',
        'category', 'deadline_type', 'deadline_at',
    ];

    /**
     * @param array<string, mixed> $state
     * @return array<string, string>
     */
    public static function fields(array $state): array
    {
        $out = [];
        foreach (self::FIELDS as $key) {
            if (!array_key_exists($key, $state) || $state[$key] === null || !is_scalar($state[$key])) {
                continue;
            }
            $value = str_replace(["\r", "\n"], ' ', (string) $state[$key]);
            $out[$key] = mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
        }

        return $out;
    }
}
