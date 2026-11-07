<?php

declare(strict_types=1);

namespace App\Util;

use DateTimeImmutable;

/**
 * Periods expressed in months, counted the way EU law counts them
 * (Regulation 1182/71 Art. 3 (2) (c)): the period ends on the same day of
 * the target month, or on its last day when that day does not exist.
 *
 * Plain `modify('+1 month')` rolls 31 January over to 3 March, which would
 * put a reporting deadline (NIS-2 Art. 23 final report) after the legal one.
 */
final class LegalPeriod
{
    public static function addMonths(DateTimeImmutable $from, int $months): DateTimeImmutable
    {
        $firstOfTarget = $from->modify('first day of this month')->modify(sprintf('%+d months', $months));
        $day = min((int) $from->format('j'), (int) $firstOfTarget->format('t'));

        return $firstOfTarget->setDate(
            (int) $firstOfTarget->format('Y'),
            (int) $firstOfTarget->format('n'),
            $day,
        );
    }
}
