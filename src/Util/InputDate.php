<?php

declare(strict_types=1);

namespace App\Util;

use DateTime;

/**
 * Parses a date that comes from a request (query / form field) without letting
 * a malformed value turn into a 500: `new DateTime($userInput)` throws on
 * "garbage", and `?x[]=1` hands over an array.
 */
final class InputDate
{
    /** Lenient: anything DateTime understands ("2026-11-07", "today"); otherwise null. */
    public static function parse(mixed $value): ?DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTime($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Strict `Y-m-d` (HTML date input); null for empty or invalid input. */
    public static function ymd(mixed $value): ?DateTime
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return DateTime::createFromFormat('!Y-m-d', $value) ?: null;
    }
}
