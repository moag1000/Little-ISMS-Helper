<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Numbers from a request that size a loop, a period or a result set.
 *
 * A value that drives work (`?period=100000` months, `?limit=10^9` rows) is
 * never taken as given: either it is refused (`int()`, 400 Bad Request) or it is
 * clamped into its range (`clamped()`). A missing value takes the default. S31.
 */
final class BoundedQuery
{
    /** Strict: absent → default; not a whole number or outside min..max → 400. */
    public static function int(Request $request, string $name, int $default, int $min, int $max): int
    {
        $raw = $request->query->all()[$name] ?? null;
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_string($raw) || preg_match('/^-?\d{1,9}$/', $raw) !== 1 || (int) $raw < $min || (int) $raw > $max) {
            throw new BadRequestHttpException(sprintf('Parameter "%s" must be a whole number from %d to %d.', $name, $min, $max));
        }

        return (int) $raw;
    }

    /** Lenient: absent or not a number → default; otherwise into min..max. */
    public static function clamped(Request $request, string $name, int $default, int $min, int $max): int
    {
        $raw = $request->query->all()[$name] ?? null;

        return self::clamp(is_string($raw) && is_numeric($raw) ? (int) $raw : $default, $min, $max);
    }

    public static function clamp(mixed $value, int $min, int $max): int
    {
        $number = is_int($value) ? $value : (is_numeric($value) ? (int) $value : $min);

        return max($min, min($max, $number));
    }
}
