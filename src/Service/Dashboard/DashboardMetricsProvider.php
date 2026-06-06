<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

use App\Entity\AuditFinding;
use App\Entity\CorrectiveAction;
use App\Entity\Tenant;
use App\Repository\AuditFindingRepository;
use App\Repository\ControlRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\KpiSnapshotRepository;

/**
 * Real, tenant-scoped numbers behind the Auditor and Board dashboards.
 *
 * Extracted from RoleDashboardService so the honest-metric logic (findings,
 * non-conformities, CAPAs, evidence coverage, KpiSnapshot trend deltas) has a
 * single home and the facade stays within its constructor-dependency budget.
 *
 * All counts are tenant-scoped. Trend deltas compare against the tenant's
 * KpiSnapshot history and return null when no comparable snapshot exists — an
 * honest "unknown" rather than a fabricated "0 / no change".
 */
class DashboardMetricsProvider
{
    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly ?AuditFindingRepository $auditFindingRepository = null,
        private readonly ?CorrectiveActionRepository $correctiveActionRepository = null,
        private readonly ?KpiSnapshotRepository $kpiSnapshotRepository = null,
    ) {
    }

    /**
     * Control evidence coverage for the tenant's applicable controls.
     *
     * @return array{total_controls: int, with_evidence: int, coverage_percentage: int}
     */
    public function evidenceStatus(?Tenant $tenant): array
    {
        $controls = $tenant
            ? $this->controlRepository->findApplicableControls($tenant)
            : [];
        $total = count($controls);

        // Controls with evidence (having a recorded review)
        $withEvidence = count(array_filter($controls, fn($c) => $c->getLastReviewDate() !== null));

        return [
            'total_controls' => $total,
            'with_evidence' => $withEvidence,
            'coverage_percentage' => $total > 0 ? (int) round(($withEvidence / $total) * 100) : 0,
        ];
    }

    /**
     * Open audit findings (not closed/verified) for the tenant.
     *
     * @return AuditFinding[]
     */
    public function openFindings(?Tenant $tenant): array
    {
        if ($tenant === null || $this->auditFindingRepository === null) {
            return [];
        }

        return $this->auditFindingRepository->findOpenByTenant($tenant);
    }

    /**
     * Non-conformity counts by ISO 19011 type, derived from already-fetched
     * open findings (major/minor NC + observation; opportunities are not NCs).
     *
     * @param AuditFinding[] $openFindings
     * @return array{major: int, minor: int, observations: int}
     */
    public function nonConformities(array $openFindings): array
    {
        $counts = ['major' => 0, 'minor' => 0, 'observations' => 0];

        foreach ($openFindings as $finding) {
            match ($finding->getType()) {
                AuditFinding::TYPE_MAJOR_NC => $counts['major']++,
                AuditFinding::TYPE_MINOR_NC => $counts['minor']++,
                AuditFinding::TYPE_OBSERVATION => $counts['observations']++,
                default => null,
            };
        }

        return $counts;
    }

    /**
     * Corrective-action (CAPA) counts for the tenant: total, still-open and
     * overdue — ISO 27001 10.1.
     *
     * @return array{total: int, open: int, overdue_count: int, items: array}
     */
    public function correctiveActions(?Tenant $tenant): array
    {
        $empty = ['total' => 0, 'open' => 0, 'overdue_count' => 0, 'items' => []];

        if ($tenant === null || $this->correctiveActionRepository === null) {
            return $empty;
        }

        $overdue = $this->correctiveActionRepository->findOverdue($tenant);
        $all = $this->correctiveActionRepository->findBy(['tenant' => $tenant]);

        // Post-completion (closed) set per CAPA lifecycle — mirrors
        // CorrectiveAction::isOverdue(); anything else is still open.
        $closedStatuses = [
            CorrectiveAction::STATUS_COMPLETED,
            CorrectiveAction::STATUS_VERIFIED,
            CorrectiveAction::STATUS_VERIFIED_EFFECTIVE,
            CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE,
        ];

        $open = 0;
        foreach ($all as $action) {
            if (!in_array($action->getStatus(), $closedStatuses, true)) {
                $open++;
            }
        }

        return [
            'total' => count($all),
            'open' => $open,
            'overdue_count' => count($overdue),
            'items' => $overdue,
        ];
    }

    /**
     * Board trend deltas vs the ~30-day-old KpiSnapshot. Null where no
     * comparable history exists, so the KPI card shows no arrow instead of a
     * fabricated "0 / no change".
     *
     * @return array{compliance: float|null, risks: int|float, critical: float|null, incidents: null, controls: float|null}
     */
    public function boardTrends(
        array $riskVelocity,
        ?Tenant $tenant,
        float $currentCompliance,
        int $currentCritical,
        float $currentControls,
    ): array {
        return [
            'compliance' => $this->trendDelta($tenant, 'average_compliance', '-30 days', $currentCompliance),
            'risks' => $riskVelocity['last_30_days']['net_change'] ?? 0,
            'critical' => $this->trendDelta($tenant, 'critical_risks', '-30 days', (float) $currentCritical),
            // Board shows incidents YTD (cumulative); the snapshot stores
            // currently-open incidents — not comparable, so no honest delta.
            'incidents' => null,
            'controls' => $this->trendDelta($tenant, 'control_compliance', '-30 days', $currentControls),
        ];
    }

    /**
     * Quarter-over-quarter comparison rows. Previous-quarter values come from
     * the ~90-day-old KpiSnapshot; when no snapshot exists yet the previous
     * value renders as "n/a" and the change is null instead of a fabricated
     * delta.
     *
     * @return list<array{name: string, previous: float|string, current: float, change: float|null, unit: string, positive_is_good: bool, target: float, on_target: bool}>
     */
    public function quarterlyMetrics(?Tenant $tenant, float $compliance, int $criticalRisks): array
    {
        return [
            $this->quarterlyRow(
                'Compliance',
                $this->historicalKpiValue($tenant, 'average_compliance', '-90 days'),
                $compliance,
                '%',
                positiveIsGood: true,
                target: 80,
            ),
            $this->quarterlyRow(
                'Kritische Risiken',
                $this->historicalKpiValue($tenant, 'critical_risks', '-90 days'),
                (float) $criticalRisks,
                '',
                positiveIsGood: false,
                target: 0,
            ),
        ];
    }

    /**
     * Signed delta of $current vs the historical snapshot value for $key, or
     * null when no comparable history exists.
     */
    private function trendDelta(?Tenant $tenant, string $key, string $ago, float $current): ?float
    {
        $previous = $this->historicalKpiValue($tenant, $key, $ago);
        if ($previous === null) {
            return null;
        }

        return round($current - $previous, 1);
    }

    /**
     * Read a single numeric KPI value from the tenant's closest snapshot before
     * $ago (e.g. '-30 days', '-90 days'). Null when unavailable / non-numeric.
     */
    private function historicalKpiValue(?Tenant $tenant, string $key, string $ago): ?float
    {
        if ($tenant === null || $this->kpiSnapshotRepository === null) {
            return null;
        }

        $snapshot = $this->kpiSnapshotRepository->findClosestBefore($tenant, new \DateTimeImmutable($ago));
        if ($snapshot === null) {
            return null;
        }

        $value = $snapshot->getKpiData()[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array{name: string, previous: float|string, current: float, change: float|null, unit: string, positive_is_good: bool, target: float, on_target: bool}
     */
    private function quarterlyRow(
        string $name,
        ?float $previous,
        float $current,
        string $unit,
        bool $positiveIsGood,
        float $target,
    ): array {
        $hasHistory = $previous !== null;

        return [
            'name' => $name,
            'previous' => $hasHistory ? round($previous, 1) : 'n/a',
            'current' => $current,
            'change' => $hasHistory ? round($current - $previous, 1) : null,
            'unit' => $unit,
            'positive_is_good' => $positiveIsGood,
            'target' => $target,
            'on_target' => $positiveIsGood ? $current >= $target : $current <= $target,
        ];
    }
}
