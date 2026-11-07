<?php

declare(strict_types=1);

namespace App\Service\Deadline;

/**
 * What kind of duty a {@see DeadlineSource} delivers.
 *
 * - bucket: My Day group and digest section; labels come from
 *   `my_day.section.<bucket>` / `my_day.kpi.<bucket>` (domain my_day).
 * - leadDays: how many days before the deadline the item shows up;
 *   null = while open, whatever the date (tasks without a horizon).
 * - normReference: the clause the duty comes from, shown in the digest.
 * - decision: a decision someone has to take (approval, suggestion). My Day
 *   shows these where they can be decided; the digest lists them first.
 * - urgentHours: legal deadlines counted in hours (NIS-2 24/72 h,
 *   GDPR Art. 33 72 h). The urgent mail goes out as soon as an item falls
 *   inside this window, instead of waiting for the morning digest.
 */
final readonly class DeadlineProfile
{
    public function __construct(
        public string $bucket,
        public ?int $leadDays = 14,
        public ?string $normReference = null,
        public bool $decision = false,
        public ?int $urgentHours = null,
    ) {
    }
}
