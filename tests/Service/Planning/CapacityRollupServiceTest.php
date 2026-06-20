<?php

declare(strict_types=1);

namespace App\Tests\Service\Planning;

use App\Entity\ActionItem;
use App\Entity\Person;
use App\Entity\PlanningSettings;
use App\Entity\RoadmapAllocation;
use App\Entity\RoadmapGroup;
use App\Entity\RoadmapTask;
use App\Entity\Tenant;
use App\Entity\UnavailabilityCalendar;
use App\Repository\ActionItemRepository;
use App\Repository\PersonRepository;
use App\Repository\PlanningSettingsRepository;
use App\Repository\RoadmapAllocationRepository;
use App\Repository\RoadmapGroupRepository;
use App\Repository\RoadmapTaskRepository;
use App\Repository\UnavailabilityCalendarRepository;
use App\Service\Planning\CapacityRollupService;
use App\Service\Planning\CapacityService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CapacityRollupService.
 *
 * All tests pass ISO year+week explicitly — no date calls inside the service core,
 * so results are fully deterministic regardless of when tests run.
 *
 * CapacityService is final, so we construct real instances backed by stubs of
 * its dependencies (UnavailabilityCalendarRepository, PlanningSettingsRepository).
 */
final class CapacityRollupServiceTest extends TestCase
{
    // ── CapacityService factory ─────────────────────────────────────────────

    private function makeCapacityService(?PlanningSettings $settings = null): CapacityService
    {
        $calendarRepo = $this->createStub(UnavailabilityCalendarRepository::class);
        $calendarRepo->method('findForTenant')->willReturn(null);  // no holidays

        $settingsRepo = $this->createStub(PlanningSettingsRepository::class);
        $settingsRepo->method('getOrCreate')->willReturn($settings ?? new PlanningSettings());

        return new CapacityService($calendarRepo, $settingsRepo);
    }

    // ── CapacityRollupService factory (for simple tests) ───────────────────

    private function makeService(
        array $allocations = [],
        array $tasks = [],
        array $groups = [],
        array $persons = [],
        ?PlanningSettings $settings = null,
        array $actionItems = [],
    ): CapacityRollupService {
        $allocationRepo = $this->createStub(RoadmapAllocationRepository::class);
        // findForWindowKeyed is called once per year-slice; always return the same map.
        $allocationRepo->method('findForWindowKeyed')->willReturn($allocations);

        return new CapacityRollupService(
            $this->makeCapacityService($settings),
            $allocationRepo,
            $this->stubTaskRepo($tasks),
            $this->stubGroupRepo($groups),
            $this->stubPersonRepo($persons),
            $this->stubSettingsRepo($settings ?? new PlanningSettings()),
            $this->stubActionItemRepo($actionItems),
        );
    }

    // ── entity builders ─────────────────────────────────────────────────────

    private function makeSettings(int $horizonWeeks = 4, int $overbookingPct = 100): PlanningSettings
    {
        return (new PlanningSettings())
            ->setRoadmapHorizonWeeks($horizonWeeks)
            ->setOverbookingThresholdPct($overbookingPct);
    }

    private function makePerson(float $availabilityPct): Person
    {
        return (new Person())->setIsmsAvailabilityPct($availabilityPct);
    }

    private function makeGroup(int $id, string $name): RoadmapGroup
    {
        $group = new RoadmapGroup();
        // PHP 8.1+ makes typed properties accessible via reflection without setAccessible().
        (new \ReflectionProperty(RoadmapGroup::class, 'id'))->setValue($group, $id);
        $group->setName($name);
        return $group;
    }

    private static int $taskIdSeq = 0;

    private function makeTask(?RoadmapGroup $group = null, ?string $name = null): RoadmapTask
    {
        $task = new RoadmapTask();
        $task->setName($name ?? 'Task-' . (++self::$taskIdSeq));
        // Force a unique id via reflection so allocations can be keyed correctly.
        (new \ReflectionProperty(RoadmapTask::class, 'id'))->setValue($task, self::$taskIdSeq);
        if ($group !== null) {
            $task->setGroup($group);
        }
        return $task;
    }

    private function makeAllocation(RoadmapTask $task, int $week, string $pt): RoadmapAllocation
    {
        return (new RoadmapAllocation())
            ->setRoadmapTask($task)
            ->setIsoWeek($week)
            ->setPlannedPt($pt);
    }

    // ── repository stubs ────────────────────────────────────────────────────

    private function stubTaskRepo(array $tasks): RoadmapTaskRepository
    {
        $stub = $this->createStub(RoadmapTaskRepository::class);
        $stub->method('findActiveByTenant')->willReturn($tasks);
        return $stub;
    }

    private function stubGroupRepo(array $groups): RoadmapGroupRepository
    {
        $stub = $this->createStub(RoadmapGroupRepository::class);
        $stub->method('findActiveByTenant')->willReturn($groups);
        return $stub;
    }

    private function stubPersonRepo(array $persons): PersonRepository
    {
        $stub = $this->createStub(PersonRepository::class);
        $stub->method('findBy')->willReturn($persons);
        return $stub;
    }

    private function stubSettingsRepo(PlanningSettings $settings): PlanningSettingsRepository
    {
        $stub = $this->createStub(PlanningSettingsRepository::class);
        $stub->method('getOrCreate')->willReturn($settings);
        return $stub;
    }

    private function stubActionItemRepo(array $items): ActionItemRepository
    {
        $stub = $this->createStub(ActionItemRepository::class);
        $stub->method('findByTenant')->willReturn($items);
        return $stub;
    }

    // ── buildWindow ─────────────────────────────────────────────────────────

    #[Test]
    public function buildWindowProducesCorrectWeekCount(): void
    {
        $svc    = $this->makeService();
        $window = $svc->buildWindow(2026, 1, 3);

        $this->assertCount(3, $window);
        $this->assertSame(['year' => 2026, 'week' => 1], $window[0]);
        $this->assertSame(['year' => 2026, 'week' => 2], $window[1]);
        $this->assertSame(['year' => 2026, 'week' => 3], $window[2]);
    }

    #[Test]
    public function buildWindowRollsAcrossYearBoundary(): void
    {
        // 2025 has 52 ISO weeks; week 52 + 1 week = 2026 W01.
        $svc    = $this->makeService();
        $window = $svc->buildWindow(2025, 52, 2);

        $this->assertSame(2025, $window[0]['year']);
        $this->assertSame(52, $window[0]['week']);
        $this->assertSame(2026, $window[1]['year']);
        $this->assertSame(1, $window[1]['week']);
    }

    // ── total capacity ──────────────────────────────────────────────────────

    #[Test]
    public function totalCapacityIsSumAcrossPersonsAndWeeks(): void
    {
        // 2 persons × 1.0 availability = 5 PT/week each (default 40 h / 8 h = 5).
        // 2-week horizon → 2 × 5 × 2 = 20 PT total.
        $persons  = [$this->makePerson(1.0), $this->makePerson(1.0)];
        $settings = $this->makeSettings(horizonWeeks: 2);

        $svc    = $this->makeService(persons: $persons, settings: $settings);
        $result = $svc->rollup(new Tenant(), 2026, 10);

        $this->assertEqualsWithDelta(20.0, $result['totalCapacityPt'], 0.01);
        $this->assertSame(2, $result['horizonWeeks']);
    }

    // ── total load ──────────────────────────────────────────────────────────

    #[Test]
    public function totalLoadIsSumOfAllocationsInWindow(): void
    {
        // 1-week horizon. Task with allocation of 3.5 PT in week 10.
        $task  = $this->makeTask();
        $alloc = $this->makeAllocation($task, 10, '3.5');

        $svc = $this->makeService(
            allocations: [$task->getId() . '-10' => $alloc],
            tasks: [$task],
            settings: $this->makeSettings(horizonWeeks: 1),
        );

        $result = $svc->rollup(new Tenant(), 2026, 10);

        $this->assertEqualsWithDelta(3.5, $result['totalLoadPt'], 0.01);
    }

    // ── by-group rollup ─────────────────────────────────────────────────────

    #[Test]
    public function byGroupSortsDescByPlannedPt(): void
    {
        $groupA = $this->makeGroup(1, 'Alpha');
        $groupB = $this->makeGroup(2, 'Beta');

        $taskA = $this->makeTask($groupA);
        $taskB = $this->makeTask($groupB);

        // Alpha gets 6 PT, Beta gets 2 PT → Alpha first (desc).
        $allocations = [
            $taskA->getId() . '-10' => $this->makeAllocation($taskA, 10, '6.0'),
            $taskB->getId() . '-10' => $this->makeAllocation($taskB, 10, '2.0'),
        ];

        $svc = $this->makeService(
            allocations: $allocations,
            tasks: [$taskA, $taskB],
            groups: [$groupA, $groupB],
            settings: $this->makeSettings(horizonWeeks: 1),
        );

        $result  = $svc->rollup(new Tenant(), 2026, 10);
        $byGroup = $result['byGroup'];

        $this->assertCount(2, $byGroup);
        $this->assertSame('Alpha', $byGroup[0]['label']);
        $this->assertEqualsWithDelta(6.0, $byGroup[0]['plannedPt'], 0.01);
        $this->assertSame('Beta', $byGroup[1]['label']);
        $this->assertEqualsWithDelta(2.0, $byGroup[1]['plannedPt'], 0.01);
    }

    #[Test]
    public function byGroupSharePctSumsTo100(): void
    {
        $groupA = $this->makeGroup(1, 'A');
        $groupB = $this->makeGroup(2, 'B');

        $taskA = $this->makeTask($groupA);
        $taskB = $this->makeTask($groupB);

        $allocations = [
            $taskA->getId() . '-10' => $this->makeAllocation($taskA, 10, '3.0'),
            $taskB->getId() . '-10' => $this->makeAllocation($taskB, 10, '7.0'),
        ];

        $svc = $this->makeService(
            allocations: $allocations,
            tasks: [$taskA, $taskB],
            groups: [$groupA, $groupB],
            settings: $this->makeSettings(horizonWeeks: 1),
        );

        $result = $svc->rollup(new Tenant(), 2026, 10);
        $total  = array_sum(array_column($result['byGroup'], 'sharePct'));

        $this->assertEqualsWithDelta(100.0, $total, 0.5); // allow small rounding ε
    }

    // ── overbooking flag ────────────────────────────────────────────────────

    #[Test]
    public function notOverbookedWhenLoadBelowThreshold(): void
    {
        // capacity = 5 PT (1 person 1-week), load = 4 PT → utilization = 80 % ≤ 100 %.
        $task  = $this->makeTask();
        $alloc = $this->makeAllocation($task, 10, '4.0');

        $svc = $this->makeService(
            allocations: [$task->getId() . '-10' => $alloc],
            tasks: [$task],
            persons: [$this->makePerson(1.0)],
            settings: $this->makeSettings(horizonWeeks: 1, overbookingPct: 100),
        );

        $result = $svc->rollup(new Tenant(), 2026, 10);

        $this->assertFalse($result['overbooked']);
        $this->assertEqualsWithDelta(80.0, $result['utilizationPct'], 0.5);
    }

    #[Test]
    public function overbookedWhenLoadExceedsThreshold(): void
    {
        // capacity = 5 PT, load = 6 PT → 120 % > 100 %.
        $task  = $this->makeTask();
        $alloc = $this->makeAllocation($task, 10, '6.0');

        $svc = $this->makeService(
            allocations: [$task->getId() . '-10' => $alloc],
            tasks: [$task],
            persons: [$this->makePerson(1.0)],
            settings: $this->makeSettings(horizonWeeks: 1, overbookingPct: 100),
        );

        $result = $svc->rollup(new Tenant(), 2026, 10);

        $this->assertTrue($result['overbooked']);
        $this->assertEqualsWithDelta(120.0, $result['utilizationPct'], 0.5);
    }

    #[Test]
    public function overbookingThresholdHonoured(): void
    {
        // threshold = 80 %, capacity = 5 PT, load = 5 PT → 100 % > 80 % → overbooked.
        $task  = $this->makeTask();
        $alloc = $this->makeAllocation($task, 10, '5.0');

        $svc = $this->makeService(
            allocations: [$task->getId() . '-10' => $alloc],
            tasks: [$task],
            persons: [$this->makePerson(1.0)],
            settings: $this->makeSettings(horizonWeeks: 1, overbookingPct: 80),
        );

        $result = $svc->rollup(new Tenant(), 2026, 10);

        $this->assertTrue($result['overbooked']);
        $this->assertSame(80, $result['overbookingThresholdPct']);
    }

    // ── utilization is null when capacity is zero ───────────────────────────

    #[Test]
    public function utilizationIsNullWhenNoCapacity(): void
    {
        $svc    = $this->makeService(settings: $this->makeSettings(horizonWeeks: 1));
        $result = $svc->rollup(new Tenant(), 2026, 10);

        $this->assertNull($result['utilizationPct']);
        $this->assertFalse($result['overbooked']);
    }

    // ── per-scope rollup ────────────────────────────────────────────────────

    #[Test]
    public function byScopeIsEmptyWhenNoActionItemsHaveScopes(): void
    {
        $item = (new ActionItem())->setTitle('X')->setScopes([]);

        $svc    = $this->makeService(actionItems: [$item]);
        $result = $svc->rollup(new Tenant(), 2026, 10);

        $this->assertSame([], $result['byScope']);
    }

    #[Test]
    public function byScopeAggregatesPlannedEffortByScope(): void
    {
        // item1 contributes 3.0 PT to HR and IT; item2 contributes 2.0 PT to IT only.
        $item1 = (new ActionItem())->setTitle('A')->setScopes(['HR', 'IT'])->setPlannedEffortPt('3.0');
        $item2 = (new ActionItem())->setTitle('B')->setScopes(['IT'])->setPlannedEffortPt('2.0');

        $svc    = $this->makeService(actionItems: [$item1, $item2]);
        $result = $svc->rollup(new Tenant(), 2026, 10);

        $byScope = $result['byScope'];
        $this->assertNotEmpty($byScope);

        $indexed = array_column($byScope, 'plannedEffortPt', 'scope');
        $this->assertEqualsWithDelta(5.0, $indexed['IT'], 0.01);
        $this->assertEqualsWithDelta(3.0, $indexed['HR'], 0.01);

        // Sorted desc — IT (5 PT) comes first.
        $this->assertSame('IT', $byScope[0]['scope']);
    }

    #[Test]
    public function byScopeOmitsItemsWithNoPlannedEffort(): void
    {
        // An item with a scope but null plannedEffortPt contributes 0 — scope still appears.
        $item = (new ActionItem())->setTitle('Z')->setScopes(['Compliance'])->setPlannedEffortPt(null);

        $svc    = $this->makeService(actionItems: [$item]);
        $result = $svc->rollup(new Tenant(), 2026, 10);

        // The scope appears but with 0 PT — not suppressed (scope tag itself is meaningful).
        $byScope = $result['byScope'];
        $this->assertNotEmpty($byScope);
        $this->assertEqualsWithDelta(0.0, $byScope[0]['plannedEffortPt'], 0.01);
    }
}
