<?php

declare(strict_types=1);

namespace App\Service\Planning;

use App\Entity\Tenant;
use App\Repository\ActionItemRepository;
use App\Repository\PersonRepository;
use App\Repository\PlanningSettingsRepository;
use App\Repository\RoadmapAllocationRepository;
use App\Repository\RoadmapGroupRepository;
use App\Repository\RoadmapTaskRepository;

/**
 * Capacity rollup for ISO 27001 Cl. 9.3 Management-Review input (Cl. 7.1 resourcing).
 *
 * Produces a deterministic, testable summary over a fixed ISO week window — the
 * controller resolves the current ISO year + week and passes them in; this class
 * never calls date functions so it can be exercised with stubs in unit tests.
 *
 * Output shape:
 *   totalCapacityPt  — Σ person-available PT across all horizon weeks
 *   totalLoadPt      — Σ roadmap allocation PT across all horizon weeks
 *   utilizationPct   — (totalLoad / totalCapacity) × 100, or null when capacity = 0
 *   overbooked       — true when utilization > overbookingThresholdPct
 *   byGroup          — per-RoadmapGroup breakdown sorted desc by plannedPt
 *   byScope          — per-scope breakdown from ActionItem.scopes, or [] when no scopes set
 *   horizonWeeks     — the effective horizon (for template labelling)
 *   overbookingThresholdPct — the configured threshold (for template labelling)
 */
final class CapacityRollupService
{
    public function __construct(
        private readonly CapacityService $capacityService,
        private readonly RoadmapAllocationRepository $allocationRepository,
        private readonly RoadmapTaskRepository $taskRepository,
        private readonly RoadmapGroupRepository $groupRepository,
        private readonly PersonRepository $personRepository,
        private readonly PlanningSettingsRepository $settingsRepository,
        private readonly ActionItemRepository $actionItemRepository,
    ) {
    }

    /**
     * @param int $currentIsoYear  The current ISO-8601 year  (injected by controller, not Date::now)
     * @param int $currentIsoWeek  The current ISO-8601 week  (injected by controller, not Date::now)
     *
     * @return array{
     *   totalCapacityPt: float,
     *   totalLoadPt: float,
     *   utilizationPct: float|null,
     *   overbooked: bool,
     *   byGroup: list<array{label: string, plannedPt: float, sharePct: float}>,
     *   byScope: list<array{scope: string, plannedEffortPt: float}>,
     *   horizonWeeks: int,
     *   overbookingThresholdPct: int,
     * }
     */
    public function rollup(Tenant $tenant, int $currentIsoYear, int $currentIsoWeek): array
    {
        $settings = $this->settingsRepository->getOrCreate($tenant);
        $horizonWeeks = $settings->getRoadmapHorizonWeeks();
        $overbookingThresholdPct = $settings->getOverbookingThresholdPct();

        // Build the ISO week window (identical logic to RoadmapController::buildWindow,
        // but pure-data — no DateTimeImmutable('now') — we set a fixed start point).
        $window = $this->buildWindow($currentIsoYear, $currentIsoWeek, $horizonWeeks);

        // ── 1. Available capacity ──────────────────────────────────────────────
        $persons = $this->personRepository->findBy(['tenant' => $tenant, 'active' => true]);
        $totalCapacityPt = 0.0;
        foreach ($window as ['year' => $year, 'week' => $week]) {
            foreach ($persons as $person) {
                $totalCapacityPt += $this->capacityService->personAvailablePt($person, $tenant, $year, $week);
            }
        }
        $totalCapacityPt = round($totalCapacityPt, 1);

        // ── 2. Planned load (roadmap allocations) ─────────────────────────────
        $tasks = $this->taskRepository->findActiveByTenant($tenant);

        // Fetch allocations grouped by year-slice.
        // Repository keys: "taskId-week" (week only, no year prefix — scoped by the year arg).
        // We collect them per year-slice into a two-level map: year => ["taskId-week" => float].
        /** @var array<int, array<string, float>> $allocationsByYear */
        $allocationsByYear = [];
        $byYear = [];
        foreach ($window as ['year' => $year, 'week' => $week]) {
            $byYear[(int) $year][] = (int) $week;
        }
        foreach ($byYear as $year => $weeks) {
            $allocationsByYear[$year] = [];
            foreach ($this->allocationRepository->findForWindowKeyed($tenant, $year, $weeks) as $key => $alloc) {
                // $key = "taskId-week" within $year
                $allocationsByYear[$year][$key] = (float) $alloc->getPlannedPt();
            }
        }

        // Σ load per task across the full horizon.
        /** @var array<int|string, float> $loadByTaskId */
        $loadByTaskId = [];
        foreach ($tasks as $task) {
            $sum = 0.0;
            foreach ($window as ['year' => $year, 'week' => $week]) {
                $sum += $allocationsByYear[$year][$task->getId() . '-' . $week] ?? 0.0;
            }
            $loadByTaskId[(int) $task->getId()] = $sum;
        }

        $totalLoadPt = round(array_sum($loadByTaskId), 1);

        // ── 3. By-group breakdown ─────────────────────────────────────────────
        // Build a group-id → name map, include a synthetic null bucket for ungrouped tasks.
        $groups = $this->groupRepository->findActiveByTenant($tenant);
        $groupNames = [];
        foreach ($groups as $group) {
            $groupNames[(int) $group->getId()] = $group->getName() ?? '—';
        }

        /** @var array<string, float> $loadByGroupLabel */
        $loadByGroupLabel = [];
        foreach ($tasks as $task) {
            $groupEntity = $task->getGroup();
            $label = $groupEntity !== null
                ? ($groupNames[(int) $groupEntity->getId()] ?? '—')
                : '—';
            $loadByGroupLabel[$label] = ($loadByGroupLabel[$label] ?? 0.0) + ($loadByTaskId[(int) $task->getId()] ?? 0.0);
        }

        // Sort desc by planned PT.
        arsort($loadByGroupLabel);

        $byGroup = [];
        foreach ($loadByGroupLabel as $label => $plannedPt) {
            $byGroup[] = [
                'label'      => $label,
                'plannedPt'  => round($plannedPt, 1),
                'sharePct'   => $totalLoadPt > 0.0 ? round($plannedPt / $totalLoadPt * 100, 1) : 0.0,
            ];
        }

        // ── 4. By-scope breakdown (ActionItem.scopes) ─────────────────────────
        // ActionItem.scopes is a list<string> of free-tag scope labels per item.
        // We sum plannedEffortPt (an estimate, not the roadmap-allocation column)
        // grouped by scope label. If no items carry scopes, we return [].
        $byScope = $this->buildScopeRollup($tenant);

        // ── 5. Utilization ────────────────────────────────────────────────────
        $utilizationPct = $totalCapacityPt > 0.0
            ? round($totalLoadPt / $totalCapacityPt * 100, 1)
            : null;

        $overbooked = $utilizationPct !== null && $utilizationPct > $overbookingThresholdPct;

        return [
            'totalCapacityPt'        => $totalCapacityPt,
            'totalLoadPt'            => $totalLoadPt,
            'utilizationPct'         => $utilizationPct,
            'overbooked'             => $overbooked,
            'byGroup'                => $byGroup,
            'byScope'                => $byScope,
            'horizonWeeks'           => $horizonWeeks,
            'overbookingThresholdPct' => $overbookingThresholdPct,
        ];
    }

    /**
     * Build the ISO week window starting from the given year+week.
     *
     * @return list<array{year: int, week: int}>
     */
    public function buildWindow(int $startYear, int $startWeek, int $count): array
    {
        // Use setISODate to advance weeks correctly across year boundaries.
        $cursor = (new \DateTimeImmutable())->setISODate($startYear, $startWeek, 1)->setTime(0, 0, 0);
        $window = [];
        for ($i = 0; $i < $count; $i++) {
            $window[] = [
                'year' => (int) $cursor->format('o'),
                'week' => (int) $cursor->format('W'),
            ];
            $cursor = $cursor->modify('+1 week');
        }
        return $window;
    }

    /**
     * Aggregate ActionItem.plannedEffortPt grouped by scope label.
     * Returns [] when no action items carry any scope tags (section is suppressed in template).
     *
     * @return list<array{scope: string, plannedEffortPt: float}>
     */
    private function buildScopeRollup(Tenant $tenant): array
    {
        $items = $this->actionItemRepository->findByTenant($tenant);

        /** @var array<string, float> $byScope */
        $byScope = [];
        foreach ($items as $item) {
            $scopes = $item->getScopes();
            if ($scopes === []) {
                continue;
            }
            $effortPt = (float) ($item->getPlannedEffortPt() ?? '0');
            foreach ($scopes as $scope) {
                $byScope[$scope] = ($byScope[$scope] ?? 0.0) + $effortPt;
            }
        }

        if ($byScope === []) {
            return [];
        }

        arsort($byScope);

        $out = [];
        foreach ($byScope as $scope => $pt) {
            $out[] = ['scope' => $scope, 'plannedEffortPt' => round($pt, 1)];
        }
        return $out;
    }
}
