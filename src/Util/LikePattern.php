<?php

declare(strict_types=1);

namespace App\Util;

/**
 * LIKE patterns from user input. `%` and `_` in the input are matched
 * literally — a search for `__` must not match every record (S24). The
 * backslash is the escape character of MySQL/MariaDB and PostgreSQL LIKE
 * by default, so the queries need no ESCAPE clause.
 */
final class LikePattern
{
    /** `%input%` with the input's wildcards escaped. */
    public static function contains(mixed $input): string
    {
        return '%' . self::escape(is_scalar($input) ? (string) $input : '') . '%';
    }

    /** The input with `\`, `%` and `_` escaped for use inside a LIKE pattern. */
    public static function escape(string $input): string
    {
        return addcslashes($input, '\\%_');
    }
}
