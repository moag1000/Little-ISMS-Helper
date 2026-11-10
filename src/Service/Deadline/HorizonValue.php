<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * The horizon as query parameter for a date column: DBAL's `date`/`datetime` types
 * take a mutable DateTime, the `*_immutable` types an immutable one.
 */
final class HorizonValue
{
    public static function forColumn(DateTimeImmutable $horizon, ?string $type): DateTimeInterface
    {
        return $type !== null && str_ends_with($type, '_immutable') ? $horizon : DateTime::createFromImmutable($horizon);
    }
}
