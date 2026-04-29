<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\FulfillmentInheritanceLogRepository;

/**
 * Inheritance Metrics Service
 *
 * CM Quick-Wins (CM-1, CM-5):
 *   - Q7: Inheritance rate per framework & tenant ("Wieviel % unserer Fulfillments kamen aus WS-1?")
 *   - Q8: FTE-days saved via WS-1 mapping-based inheritance.
 *
 * Data source:
 *   - FulfillmentInheritanceLog rows with reviewStatus CONFIRMED or OVERRIDDEN
 *     count as "generated through inheritance".
 *   - ComplianceRequirementFulfillment counts the denominator (manual vs inherited).
 *
 * Policy:
 *   - FTE factor = CompliancePolicyService::KEY_REUSE_DAYS_PER_REQUIREMENT (default 0.3).
 *
 * Tenant-scoping: every query filters by tenant to preserve multi-tenant isolation.
 *
 * @see docs/CM_JUNIOR_RESPONSE.md CM-1, CM-5
 */
class InheritanceMetricsService
{
    public function __construct(
        private readonly FulfillmentInheritanceLogRepository $logRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly CompliancePolicyService $policy,
    ) {
    }

    /**
     * Metrics for a single framework within a tenant.
     *
     * @return array{
     *   framework_code: string,
     *   fulfillments_total: int,
     *   fulfillments_from_inheritance_confirmed: int,
     *   fulfillments_from_inheritance_overridden: int,
     *   fulfillments_manual: int,
     *   inheritance_rate_percent: int,
     *   pending_review_count: int
     * }
     */
    public function metricsForFramework(Tenant $tenant, ComplianceFramework $framework): array
    {
        $total = $this->countFulfillments($tenant, $framework);
        $statusCounts = $this->countLogStatuses($tenant, $framework);

        $confirmed = $statusCounts[FulfillmentInheritanceLog::STATUS_CONFIRMED] ?? 0;
        $overridden = $statusCounts[FulfillmentInheritanceLog::STATUS_OVERRIDDEN] ?? 0;
        $pending = ($statusCounts[FulfillmentInheritanceLog::STATUS_PENDING_REVIEW] ?? 0)
            + ($statusCounts[FulfillmentInheritanceLog::STATUS_SOURCE_UPDATED] ?? 0);

        $inherited = $confirmed + $overridden;
        $manual = max(0, $total - $inherited);
        $rate = $total > 0 ? (int) round(($inherited / $total) * 100) : 0;

        return [
            'framework_code' => (string) $framework->getCode(),
            'fulfillments_total' => $total,
            'fulfillments_from_inheritance_confirmed' => $confirmed,
            'fulfillments_from_inheritance_overridden' => $overridden,
            'fulfillments_manual' => $manual,
            'inheritance_rate_percent' => $rate,
            'pending_review_count' => $pending,
        ];
    }

    /**
     * Metrics aggregated over all active frameworks for a tenant.
     *
     * @return array{
     *   per_framework: list<array{
     *     framework_code: string,
     *     fulfillments_total: int,
     *     fulfillments_from_inheritance_confirmed: int,
     *     fulfillments_from_inheritance_overridden: int,
     *     fulfillments_manual: int,
     *     inheritance_rate_percent: int,
     *     pending_review_count: int
     *   }>,
     *   total: array{
     *     fulfillments_total: int,
     *     fulfillments_from_inheritance_confirmed: int,
     *     fulfillments_from_inheritance_overridden: int,
     *     fulfillments_manual: int,
     *     inheritance_rate_percent: int,
     *     pending_review_count: int
     *   }
     * }
     */
    public function metricsForTenant(Tenant $tenant): array
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();

        $perFramework = [];
        $totals = [
            'fulfillments_total' => 0,
            'fulfillments_from_inheritance_confirmed' => 0,
            'fulfillments_from_inheritance_overridden' => 0,
            'fulfillments_manual' => 0,
            'pending_review_count' => 0,
        ];

        foreach ($frameworks as $framework) {
            $metrics = $this->metricsForFramework($tenant, $framework);
            $perFramework[] = $metrics;

            $totals['fulfillments_total'] += $metrics['fulfillments_total'];
            $totals['fulfillments_from_inheritance_confirmed'] += $metrics['fulfillments_from_inheritance_confirmed'];
            $totals['fulfillments_from_inheritance_overridden'] += $metrics['fulfillments_from_inheritance_overridden'];
            $totals['fulfillments_manual'] += $metrics['fulfillments_manual'];
            $totals['pending_review_count'] += $metrics['pending_review_count'];
        }

        $inheritedTotal = $totals['fulfillments_from_inheritance_confirmed']
            + $totals['fulfillments_from_inheritance_overridden'];
        $totals['inheritance_rate_percent'] = $totals['fulfillments_total'] > 0
            ? (int) round(($inheritedTotal / $totals['fulfillments_total']) * 100)
            : 0;

        return [
            'per_framework' => $perFramework,
            'total' => $totals,
        ];
    }

    /**
     * FTE days saved through mapping-based inheritance for a single framework.
     */
    public function fteSavedForFramework(Tenant $tenant, ComplianceFramework $framework): float
    {
        $metrics = $this->metricsForFramework($tenant, $framework);
        $inherited = $metrics['fulfillments_from_inheritance_confirmed']
            + $metrics['fulfillments_from_inheritance_overridden'];

        return $this->computeFteSaved($inherited);
    }

    /**
     * FTE days saved through mapping-based inheritance across all frameworks for a tenant.
     */
    public function fteSavedForTenant(Tenant $tenant): float
    {
        $metrics = $this->metricsForTenant($tenant);
        $inherited = $metrics['total']['fulfillments_from_inheritance_confirmed']
            + $metrics['total']['fulfillments_from_inheritance_overridden'];

        return $this->computeFteSaved($inherited);
    }

    /**
     * FTE days saved formula: inherited-count * days_per_requirement policy factor.
     * Rounded to 1 decimal place for executive display.
     */
    private function computeFteSaved(int $inheritedCount): float
    {
        $factor = $this->policy->getFloat(
            CompliancePolicyService::KEY_REUSE_DAYS_PER_REQUIREMENT,
            0.3,
        );

        return round($inheritedCount * $factor, 1);
    }

    /**
     * Count fulfillments for a framework & tenant in one aggregate query.
     */
    private function countFulfillments(Tenant $tenant, ComplianceFramework $framework): int
    {
        $result = $this->fulfillmentRepository->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->innerJoin('f.requirement', 'r')
            ->where('f.tenant = :tenant')
            ->andWhere('r.complianceFramework = :framework')
            ->setParameter('tenant', $tenant)
            ->setParameter('framework', $framework)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Count inheritance-log rows grouped by reviewStatus, for a tenant & framework.
     * Single aggregate query - no N+1.
     *
     * @return array<string, int>
     */
    private function countLogStatuses(Tenant $tenant, ComplianceFramework $framework): array
    {
        $rows = $this->logRepository->createQueryBuilder('l')
            ->select('l.reviewStatus AS status, COUNT(l.id) AS cnt')
            ->innerJoin('l.fulfillment', 'f')
            ->innerJoin('f.requirement', 'r')
            ->where('l.tenant = :tenant')
            ->andWhere('r.complianceFramework = :framework')
            ->groupBy('l.reviewStatus')
            ->setParameter('tenant', $tenant)
            ->setParameter('framework', $framework)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
