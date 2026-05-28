<?php

declare(strict_types=1);

namespace App\Util;

/**
 * CsvSanitizer
 *
 * Sanitizes CSV cell values to prevent formula injection (OWASP — Injection).
 * Extracted from 15 independent private copies across controllers and jobs.
 *
 * Rule: Prefixes values starting with =, +, -, @, TAB or CR with a single quote.
 */
final class CsvSanitizer
{
    /**
     * Sanitize a single CSV cell value.
     *
     * @param mixed $value Cell value (non-strings are returned as-is)
     * @return mixed Sanitized value
     */
    public static function sanitize(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
