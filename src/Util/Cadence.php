<?php

declare(strict_types=1);

namespace App\Util;

/**
 * The one reading of the free cadence words stored on records
 * (AuditProgram.frequency, InterestedParty.communicationFrequency,
 * ISMSObjective.measurementFrequency) as a number of months.
 *
 * The stored words stay as they are (forms, imports and the API use them);
 * every date calculation goes through this mapping. Cadences shorter than a
 * month (daily, weekly) and event-driven ones (as_needed, on_event) have no
 * month interval and return null: they are not calendar duties.
 */
final class Cadence
{
    private const array MONTHS = [
        'monthly' => 1,
        'quarterly' => 3,
        'biannually' => 6,
        'semiannually' => 6,
        'annual' => 12,
        'annually' => 12,
        'biennial' => 24,
    ];

    public static function months(?string $cadence): ?int
    {
        return $cadence === null ? null : (self::MONTHS[strtolower(trim($cadence))] ?? null);
    }
}
