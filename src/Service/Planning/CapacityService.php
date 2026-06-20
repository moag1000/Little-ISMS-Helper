<?php

declare(strict_types=1);

namespace App\Service\Planning;

use App\Entity\Person;
use App\Entity\Team;
use App\Entity\Tenant;
use App\Entity\UnavailabilityPeriod;
use App\Repository\PlanningSettingsRepository;
use App\Repository\UnavailabilityCalendarRepository;
use DateTimeImmutable;

/**
 * Capacity computation in person-days per ISO week (Engineering-Spec §5).
 *
 *   fullTimePtPerWeek = fullTimeHoursPerWeek / hoursPerDay         (40/8 = 5)
 *   person.baseline   = ismsAvailabilityPct * fullTimePtPerWeek
 *   person.available  = baseline * (1 - unavailableWorkdays/5)
 *   team.available    = Σ members person.available                 (Σ, not ⌀)
 *
 * Only structural non-availability (public holidays + shutdown spans) is
 * subtracted — individual leave/sickness is already folded into
 * ismsAvailabilityPct (no time-tracking, no feature creep). Tenant-configurable
 * constants are deferred to PlanningSettings (PR-5); sensible defaults here.
 */
final class CapacityService
{
    public const float DEFAULT_FULLTIME_HOURS_PER_WEEK = 40.0;
    public const float DEFAULT_HOURS_PER_DAY = 8.0;
    public const int WORKDAYS_PER_WEEK = 5;

    public function __construct(
        private readonly UnavailabilityCalendarRepository $calendarRepository,
        private readonly PlanningSettingsRepository $planningSettingsRepository,
    ) {
    }

    /**
     * Full-time person-days per week for the given tenant.
     *
     * Resolves the two constants from the tenant's PlanningSettings when
     * available; falls back to the class constants (40 h/week ÷ 8 h/day = 5)
     * when no settings row exists or when either field is null.
     */
    public function fullTimePtPerWeek(?Tenant $tenant = null): float
    {
        $hoursPerWeek = self::DEFAULT_FULLTIME_HOURS_PER_WEEK;
        $hoursPerDay  = self::DEFAULT_HOURS_PER_DAY;

        if ($tenant !== null) {
            $settings = $this->planningSettingsRepository->getOrCreate($tenant);
            $hoursPerWeek = $settings->getFullTimeHoursPerWeek() ?? self::DEFAULT_FULLTIME_HOURS_PER_WEEK;
            $hoursPerDay  = $settings->getHoursPerDay()          ?? self::DEFAULT_HOURS_PER_DAY;
        }

        // Defensive: legacy/invalid stored values must never divide-by-zero (the
        // settings form rejects <= 0, but pre-existing rows are not re-validated).
        if ($hoursPerWeek <= 0.0 || $hoursPerDay <= 0.0) {
            $hoursPerWeek = self::DEFAULT_FULLTIME_HOURS_PER_WEEK;
            $hoursPerDay  = self::DEFAULT_HOURS_PER_DAY;
        }

        return $hoursPerWeek / $hoursPerDay;
    }

    /**
     * Number of Mon–Fri workdays in the given ISO week that fall on a holiday or
     * within a shutdown period of the tenant's calendar (0..5).
     */
    public function unavailableWorkdays(Tenant $tenant, int $isoYear, int $isoWeek): int
    {
        $calendar = $this->calendarRepository->findForTenant($tenant);
        if ($calendar === null) {
            return 0;
        }
        $periods = $calendar->getPeriods();
        if ($periods->isEmpty()) {
            return 0;
        }

        $count = 0;
        for ($dow = 1; $dow <= self::WORKDAYS_PER_WEEK; $dow++) {
            $day = (new DateTimeImmutable())->setISODate($isoYear, $isoWeek, $dow)->setTime(0, 0);
            foreach ($periods as $period) {
                /** @var UnavailabilityPeriod $period */
                $start = $period->getStartDate();
                $end = $period->getEffectiveEndDate();
                if ($start === null || $end === null) {
                    continue;
                }
                if ($day >= $start->setTime(0, 0) && $day <= $end->setTime(0, 0)) {
                    $count++;
                    break; // this workday already counted
                }
            }
        }

        return $count;
    }

    /** Available person-days for one person in one ISO week. */
    public function personAvailablePt(Person $person, Tenant $tenant, int $isoYear, int $isoWeek): float
    {
        $baseline = $person->getBaselinePtPerWeek($this->fullTimePtPerWeek($tenant));
        $unavailableFraction = $this->unavailableWorkdays($tenant, $isoYear, $isoWeek) / self::WORKDAYS_PER_WEEK;

        return round($baseline * (1.0 - $unavailableFraction), 2);
    }

    /** Available person-days for a whole team in one ISO week (Σ of members). */
    public function teamAvailablePt(Team $team, Tenant $tenant, int $isoYear, int $isoWeek): float
    {
        $sum = 0.0;
        foreach ($team->getMembers() as $person) {
            $sum += $this->personAvailablePt($person, $tenant, $isoYear, $isoWeek);
        }

        return round($sum, 2);
    }
}
