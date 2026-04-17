<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;

/**
 * WS-6 (DATA_REUSE_IMPROVEMENT_PLAN.md v1.1) — Gap-Report Effort Calculator.
 *
 * Computes the remaining person-day effort per requirement of a framework
 * using:
 *     effective_effort_days = adjusted_effort_days ?? base_effort_days
 *     remaining_effort_days = effective_effort_days × (1 − fulfillment_% / 100)
 *
 * Quick-Wins are the cheapest 20 % of the gap list (by effort) that still
 * carry a meaningful fulfillment gap (< 100 %) — i.e. high impact for low
 * effort, the classic "pick these up first" recommendation.
 */
class GapEffortCalculator
{
    public const SORT_REMAINING_EFFORT = 'effort';
    public const SORT_QUICK_WINS = 'quick-wins';

    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly CompliancePolicyService $policy,
    ) {
    }

    private function quickWinEffortPercentile(): float
    {
        return $this->policy->getInt(CompliancePolicyService::KEY_QUICK_WIN_EFFORT_PERCENTILE, 20) / 100.0;
    }

    private function quickWinMinGapPct(): int
    {
        return $this->policy->getInt(CompliancePolicyService::KEY_QUICK_WIN_MIN_GAP_PERCENT, 10);
    }

    /**
     * Build the sorted gap list for a tenant × framework.
     *
     * @return array<int, array{
     *     requirement: ComplianceRequirement,
     *     fulfillment: ?ComplianceRequirementFulfillment,
     *     fulfillment_percentage: int,
     *     base_effort_days: ?int,
     *     adjusted_effort_days: ?int,
     *     effective_effort_days: ?int,
     *     remaining_effort_days: float,
     *     category: ?string,
     *     priority: ?string,
     *     is_estimated: bool,
     *     is_quick_win: bool,
     * }>
     */
    public function calculate(
        Tenant $tenant,
        ComplianceFramework $framework,
        string $sort = self::SORT_REMAINING_EFFORT,
    ): array {
        $requirements = $this->requirementRepository->findBy(
            ['complianceFramework' => $framework],
            ['requirementId' => 'ASC'],
        );

        // Pre-index fulfillments for O(1) lookup
        $fulfillments = [];
        foreach ($this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant) as $fulfillment) {
            $requirementId = $fulfillment->getRequirement()?->getId();
            if ($requirementId !== null) {
                $fulfillments[$requirementId] = $fulfillment;
            }
        }

        $rows = [];
        foreach ($requirements as $requirement) {
            $fulfillment = $fulfillments[$requirement->getId()] ?? null;

            // Non-applicable requirements carry no gap effort
            if ($fulfillment !== null && !$fulfillment->isApplicable()) {
                continue;
            }

            $fulfillmentPct = $fulfillment?->getFulfillmentPercentage() ?? 0;
            $adjusted = $fulfillment?->getAdjustedEffortDays();
            $base = $requirement->getBaseEffortDays();
            $effective = $adjusted ?? $base;

            $remaining = 0.0;
            if ($effective !== null) {
                $remaining = round(
                    $effective * max(0.0, 1.0 - ($fulfillmentPct / 100)),
                    2,
                );
            }

            $rows[] = [
                'requirement' => $requirement,
                'fulfillment' => $fulfillment,
                'fulfillment_percentage' => $fulfillmentPct,
                'base_effort_days' => $base,
                'adjusted_effort_days' => $adjusted,
                'effective_effort_days' => $effective,
                'remaining_effort_days' => $remaining,
                'category' => $requirement->getCategory(),
                'priority' => $requirement->getPriority(),
                'is_estimated' => $effective !== null,
                'is_quick_win' => false,
            ];
        }

        // Mark Quick-Wins before sorting so the flag survives any sort order
        $this->markQuickWins($rows);

        return $this->sortRows($rows, $sort);
    }

    /**
     * Aggregate totals + Quick-Win bucket for dashboards / slider.
     *
     * @return array{
     *     total_effort: float,
     *     total_effort_days: float,
     *     remaining_effort_days: float,
     *     estimated_count: int,
     *     unestimated_count: int,
     *     quick_wins: array<int, array<string, mixed>>,
     *     rows: array<int, array<string, mixed>>,
     * }
     */
    public function calculateTotalEffort(
        Tenant $tenant,
        ComplianceFramework $framework,
        string $sort = self::SORT_REMAINING_EFFORT,
    ): array {
        $rows = $this->calculate($tenant, $framework, $sort);

        $totalRemaining = 0.0;
        $totalEffective = 0.0;
        $estimated = 0;
        $unestimated = 0;
        $quickWins = [];

        foreach ($rows as $row) {
            $totalRemaining += (float) $row['remaining_effort_days'];
            if ($row['effective_effort_days'] !== null) {
                $totalEffective += (float) $row['effective_effort_days'];
                $estimated++;
            } else {
                $unestimated++;
            }

            if ($row['is_quick_win']) {
                $quickWins[] = $row;
            }
        }

        return [
            'total_effort' => round($totalRemaining, 2),
            'total_effort_days' => round($totalRemaining, 2),
            'remaining_effort_days' => round($totalRemaining, 2),
            'total_effective_effort_days' => round($totalEffective, 2),
            'estimated_count' => $estimated,
            'unestimated_count' => $unestimated,
            'quick_wins' => $quickWins,
            'rows' => $rows,
        ];
    }

    /**
     * Identify Quick-Wins: cheapest 20 % of estimated gaps that still have
     * meaningful remaining work. Sets the `is_quick_win` flag in-place.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function markQuickWins(array &$rows): void
    {
        $minGap = $this->quickWinMinGapPct();
        // Only estimated gaps with real remaining effort qualify
        $candidates = array_filter(
            $rows,
            static fn (array $row): bool => $row['is_estimated']
                && $row['remaining_effort_days'] > 0
                && (100 - (int) $row['fulfillment_percentage']) >= $minGap,
        );

        if ($candidates === []) {
            return;
        }

        // Sort by effort ascending, take the cheapest N %
        usort(
            $candidates,
            static fn (array $a, array $b): int
                => $a['remaining_effort_days'] <=> $b['remaining_effort_days'],
        );

        $bucketSize = max(1, (int) ceil(count($candidates) * $this->quickWinEffortPercentile()));
        $quickWinRequirementIds = [];
        foreach (array_slice($candidates, 0, $bucketSize) as $candidate) {
            $requirementId = $candidate['requirement']->getId();
            if ($requirementId !== null) {
                $quickWinRequirementIds[$requirementId] = true;
            }
        }

        foreach ($rows as &$row) {
            $requirementId = $row['requirement']->getId();
            if ($requirementId !== null && isset($quickWinRequirementIds[$requirementId])) {
                $row['is_quick_win'] = true;
            }
        }
        unset($row);
    }

    /**
     * Sort the gap list. Default is descending remaining effort (biggest
     * chunks first). Quick-Wins mode sorts by impact-per-day score.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function sortRows(array $rows, string $sort): array
    {
        if ($sort === self::SORT_QUICK_WINS) {
            // Impact-per-effort score: gap_percentage / effective_effort
            // Higher score = better Quick-Win candidate
            usort($rows, static function (array $a, array $b): int {
                $scoreA = self::quickWinScore($a);
                $scoreB = self::quickWinScore($b);

                return $scoreB <=> $scoreA;
            });

            return $rows;
        }

        // Default: biggest remaining effort first
        usort(
            $rows,
            static fn (array $a, array $b): int
                => $b['remaining_effort_days'] <=> $a['remaining_effort_days'],
        );

        return $rows;
    }

    /**
     * Compute a Quick-Win score: high gap × low effort = high score.
     * Unestimated rows get score 0 so they sink to the bottom.
     *
     * @param array<string, mixed> $row
     */
    private static function quickWinScore(array $row): float
    {
        $effective = $row['effective_effort_days'];
        if ($effective === null || $effective <= 0) {
            return 0.0;
        }

        $gapPct = 100 - (int) $row['fulfillment_percentage'];
        if ($gapPct <= 0) {
            return 0.0;
        }

        return round($gapPct / (float) $effective, 4);
    }
}
