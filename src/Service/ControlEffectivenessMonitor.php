<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use DateTimeImmutable;

/**
 * Control Effectiveness Monitor — ISO 27001 §9.1 / Annex A 5.35/5.36.
 *
 * Dedicated service for the Wirksamkeits-Monitor page: surfaces controls
 * whose last effectiveness check (`lastEffectivenessTest`) is overdue (> X months),
 * coming due soon, or recently checked.  Separate from the existing
 * ControlEffectivenessService (Phase 7B) which analyses risk-reduction scores.
 */
class ControlEffectivenessMonitor
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
    ) {
    }

    /**
     * Controls where lastEffectivenessTest < (now - thresholdMonths) OR is null.
     * Sorted by most-overdue first (null = never tested = worst).
     *
     * @return array<int, array{control: Control, overdueDays: int|null, lastChecked: DateTimeImmutable|null}>
     */
    public function findOverdueControls(Tenant $tenant, int $thresholdMonths = 12): array
    {
        $threshold = new DateTimeImmutable("-{$thresholdMonths} months");

        $controls = $this->controlRepository->findByTenant($tenant);

        $overdue = [];
        foreach ($controls as $control) {
            if (!$control->isApplicable()) {
                continue;
            }
            $last = $control->getLastEffectivenessTest();
            if ($last === null || $last < $threshold) {
                $overdueDays = $last !== null
                    ? (int) (new \DateTime())->diff($last)->days
                    : null;
                $overdue[] = [
                    'control'     => $control,
                    'overdueDays' => $overdueDays,
                    'lastChecked' => $last,
                ];
            }
        }

        // Sort: never-tested first, then most-overdue first
        usort($overdue, static function (array $a, array $b): int {
            if ($a['overdueDays'] === null && $b['overdueDays'] === null) {
                return 0;
            }
            if ($a['overdueDays'] === null) {
                return -1;
            }
            if ($b['overdueDays'] === null) {
                return 1;
            }
            return $b['overdueDays'] <=> $a['overdueDays'];
        });

        return $overdue;
    }

    /**
     * Controls where nextEffectivenessTest falls within the next `thresholdDays` days.
     *
     * @return array<int, array{control: Control, daysUntilDue: int, nextDue: DateTimeImmutable}>
     */
    public function findUpcomingDueControls(Tenant $tenant, int $thresholdDays = 30): array
    {
        $now  = new DateTimeImmutable();
        $soon = new DateTimeImmutable("+{$thresholdDays} days");

        $controls = $this->controlRepository->findByTenant($tenant);

        $upcoming = [];
        foreach ($controls as $control) {
            if (!$control->isApplicable()) {
                continue;
            }
            $next = $control->getNextEffectivenessTest();
            if ($next !== null && $next >= $now && $next <= $soon) {
                $daysUntilDue = (int) $now->diff($next)->days;
                $upcoming[] = [
                    'control'      => $control,
                    'daysUntilDue' => $daysUntilDue,
                    'nextDue'      => $next,
                ];
            }
        }

        usort($upcoming, static fn(array $a, array $b): int =>
            $a['daysUntilDue'] <=> $b['daysUntilDue']
        );

        return $upcoming;
    }

    /**
     * Controls checked within the last `lookbackDays` days (for audit confirmation).
     *
     * @return array<int, array{control: Control, daysSinceCheck: int, lastChecked: DateTimeImmutable}>
     */
    public function findRecentlyChecked(Tenant $tenant, int $lookbackDays = 90): array
    {
        $since = new DateTimeImmutable("-{$lookbackDays} days");

        $controls = $this->controlRepository->findByTenant($tenant);

        $recent = [];
        foreach ($controls as $control) {
            if (!$control->isApplicable()) {
                continue;
            }
            $last = $control->getLastEffectivenessTest();
            if ($last !== null && $last >= $since) {
                $daysSinceCheck = (int) (new \DateTime())->diff($last)->days;
                $recent[] = [
                    'control'        => $control,
                    'daysSinceCheck' => $daysSinceCheck,
                    'lastChecked'    => $last,
                ];
            }
        }

        usort($recent, static fn(array $a, array $b): int =>
            $b['lastChecked'] <=> $a['lastChecked']
        );

        return $recent;
    }

    /**
     * Aggregate statistics for the monitor dashboard.
     *
     * @return array{
     *     total: int,
     *     overdue: int,
     *     overduePct: float,
     *     dueSoon: int,
     *     dueSoonPct: float,
     *     recentlyChecked: int,
     *     recentlyCheckedPct: float,
     *     neverChecked: int,
     *     neverCheckedPct: float,
     * }
     */
    public function calculateSummaryStats(Tenant $tenant): array
    {
        $all = $this->controlRepository->findByTenant($tenant);

        $applicable = array_filter($all, static fn(Control $c): bool => (bool) $c->isApplicable());
        $total = count($applicable);

        if ($total === 0) {
            return [
                'total'               => 0,
                'overdue'             => 0,
                'overduePct'          => 0.0,
                'dueSoon'             => 0,
                'dueSoonPct'          => 0.0,
                'recentlyChecked'     => 0,
                'recentlyCheckedPct'  => 0.0,
                'neverChecked'        => 0,
                'neverCheckedPct'     => 0.0,
            ];
        }

        $overdueList   = $this->findOverdueControls($tenant);
        $dueSoonList   = $this->findUpcomingDueControls($tenant);
        $recentList    = $this->findRecentlyChecked($tenant);
        $neverChecked  = count(array_filter(
            $applicable,
            static fn(Control $c): bool => $c->getLastEffectivenessTest() === null
        ));

        $overdue  = count($overdueList);
        $dueSoon  = count($dueSoonList);
        $recent   = count($recentList);

        return [
            'total'               => $total,
            'overdue'             => $overdue,
            'overduePct'          => round($overdue / $total * 100, 1),
            'dueSoon'             => $dueSoon,
            'dueSoonPct'          => round($dueSoon / $total * 100, 1),
            'recentlyChecked'     => $recent,
            'recentlyCheckedPct'  => round($recent / $total * 100, 1),
            'neverChecked'        => $neverChecked,
            'neverCheckedPct'     => round($neverChecked / $total * 100, 1),
        ];
    }
}
