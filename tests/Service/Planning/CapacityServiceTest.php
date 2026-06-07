<?php

declare(strict_types=1);

namespace App\Tests\Service\Planning;

use App\Entity\Person;
use App\Entity\Team;
use App\Entity\Tenant;
use App\Entity\UnavailabilityCalendar;
use App\Entity\UnavailabilityPeriod;
use App\Repository\UnavailabilityCalendarRepository;
use App\Service\Planning\CapacityService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CapacityServiceTest extends TestCase
{
    private function serviceWithCalendar(?UnavailabilityCalendar $calendar): CapacityService
    {
        $repo = $this->createStub(UnavailabilityCalendarRepository::class);
        $repo->method('findForTenant')->willReturn($calendar);
        return new CapacityService($repo);
    }

    private function person(float $pct): Person
    {
        return (new Person())->setIsmsAvailabilityPct($pct);
    }

    #[Test]
    public function fullTimeIsFivePtPerWeek(): void
    {
        $this->assertSame(5.0, $this->serviceWithCalendar(null)->fullTimePtPerWeek());
    }

    #[Test]
    public function personBaselineWithoutCalendar(): void
    {
        $service = $this->serviceWithCalendar(null);
        // 0.4 net availability × 5 PT = 2.0
        $this->assertEqualsWithDelta(2.0, $service->personAvailablePt($this->person(0.4), new Tenant(), 2026, 10), 0.001);
        // full person → 5.0
        $this->assertEqualsWithDelta(5.0, $service->personAvailablePt($this->person(1.0), new Tenant(), 2026, 10), 0.001);
    }

    #[Test]
    public function holidayReducesAvailabilityByOneWorkday(): void
    {
        // Neujahr 2026-01-01 is a Thursday in ISO week 2026-W01.
        $calendar = new UnavailabilityCalendar();
        $holiday = (new UnavailabilityPeriod())
            ->setKind(UnavailabilityPeriod::KIND_HOLIDAY)
            ->setStartDate(new \DateTimeImmutable('2026-01-01'))
            ->setLabel('Neujahr');
        $calendar->addPeriod($holiday);

        $service = $this->serviceWithCalendar($calendar);

        $this->assertSame(1, $service->unavailableWorkdays(new Tenant(), 2026, 1));
        // full person: 5 × (1 - 1/5) = 4.0
        $this->assertEqualsWithDelta(4.0, $service->personAvailablePt($this->person(1.0), new Tenant(), 2026, 1), 0.001);
        // a week without the holiday is unaffected
        $this->assertSame(0, $service->unavailableWorkdays(new Tenant(), 2026, 10));
    }

    #[Test]
    public function shutdownSpanCountsAllContainedWorkdays(): void
    {
        // Shutdown covering a full week (Mon–Sun) zeroes its 5 workdays.
        $calendar = new UnavailabilityCalendar();
        $shutdown = (new UnavailabilityPeriod())
            ->setKind(UnavailabilityPeriod::KIND_SHUTDOWN)
            ->setStartDate(new \DateTimeImmutable('2026-03-02'))   // Mon W10
            ->setEndDate(new \DateTimeImmutable('2026-03-08'));    // Sun W10
        $calendar->addPeriod($shutdown);

        $service = $this->serviceWithCalendar($calendar);

        $this->assertSame(5, $service->unavailableWorkdays(new Tenant(), 2026, 10));
        $this->assertEqualsWithDelta(0.0, $service->personAvailablePt($this->person(1.0), new Tenant(), 2026, 10), 0.001);
    }

    #[Test]
    public function teamCapacityIsSumOfMembers(): void
    {
        $service = $this->serviceWithCalendar(null);
        $team = new Team();
        $team->addMember($this->person(0.4));  // 2.0
        $team->addMember($this->person(1.0));  // 5.0

        $this->assertEqualsWithDelta(7.0, $service->teamAvailablePt($team, new Tenant(), 2026, 10), 0.001);
    }
}
