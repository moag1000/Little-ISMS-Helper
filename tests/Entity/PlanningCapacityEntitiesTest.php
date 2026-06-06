<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RoadmapAllocation;
use App\Entity\RoadmapTask;
use App\Entity\UnavailabilityCalendar;
use App\Entity\UnavailabilityPeriod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlanningCapacityEntitiesTest extends TestCase
{
    #[Test]
    public function calendarPeriodBackReference(): void
    {
        $cal = new UnavailabilityCalendar();
        $period = (new UnavailabilityPeriod())->setStartDate(new \DateTimeImmutable('2026-01-01'));

        $cal->addPeriod($period);
        $this->assertCount(1, $cal->getPeriods());
        $this->assertSame($cal, $period->getCalendar());

        $cal->removePeriod($period);
        $this->assertCount(0, $cal->getPeriods());
        $this->assertNull($period->getCalendar());
    }

    #[Test]
    public function effectiveEndDateFallsBackToStart(): void
    {
        $holiday = (new UnavailabilityPeriod())->setStartDate(new \DateTimeImmutable('2026-05-01'));
        $this->assertEquals(new \DateTimeImmutable('2026-05-01'), $holiday->getEffectiveEndDate());

        $shutdown = (new UnavailabilityPeriod())
            ->setStartDate(new \DateTimeImmutable('2026-12-24'))
            ->setEndDate(new \DateTimeImmutable('2027-01-02'));
        $this->assertEquals(new \DateTimeImmutable('2027-01-02'), $shutdown->getEffectiveEndDate());
    }

    #[Test]
    public function allocationDefaultsAndSetters(): void
    {
        $alloc = new RoadmapAllocation();
        $this->assertSame('0.0', $alloc->getPlannedPt());

        $task = (new RoadmapTask())->setName('Audits');
        $alloc->setRoadmapTask($task)->setIsoYear(2026)->setIsoWeek(7)->setPlannedPt('2.5');

        $this->assertSame($task, $alloc->getRoadmapTask());
        $this->assertSame(2026, $alloc->getIsoYear());
        $this->assertSame(7, $alloc->getIsoWeek());
        $this->assertSame('2.5', $alloc->getPlannedPt());
    }
}
