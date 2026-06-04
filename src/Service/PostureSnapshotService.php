<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use App\Entity\Tenant;
use App\Enum\InternalAuditStatus;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\KpiSnapshotRepository;

/**
 * F43 Trust-Center — safe read-only compliance-posture snapshot.
 *
 * TENANT-DISCLOSURE-SAFE CONTRACT
 * ================================
 * The DTO returned by getSnapshot() carries ONLY the following §4 data:
 *
 *   - tenantName:           Display name of the organisation (non-secret)
 *   - frameworks:           List of active framework names + codes (no control texts)
 *   - frameworkCompliance:  Per-framework fulfilled/applicable counts + percent
 *   - overallControlPct:    Overall control-compliance % from latest KpiSnapshot
 *                           (kpiData['control_compliance']) — null when no snapshot
 *   - lastAuditDate:        Date-only string (Y-m-d) of the last completed/closed
 *                           internal audit — null when none exists
 *   - snapshotAt:           Freshness timestamp (DateTimeImmutable, current time)
 *
 * NEVER included (verified by unit test PostureSnapshotServiceTest):
 *   risk, asset, user, incident, monetary amounts (€/$), findings,
 *   DPO contact, evidence, personal data, control requirement texts.
 */
final class PostureSnapshotService
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly KpiSnapshotRepository $kpiSnapshotRepository,
        private readonly InternalAuditRepository $auditRepository,
    ) {}

    /**
     * Build a safe, read-only posture DTO for the given tenant.
     *
     * The returned array is the canonical §4-surface — add nothing else here.
     *
     * @return array{
     *     tenantName: string,
     *     frameworks: list<array{code: string, name: string}>,
     *     frameworkCompliance: list<array{code: string, name: string, fulfilled: int, applicable: int, percent: float}>,
     *     overallControlPct: float|null,
     *     lastAuditDate: string|null,
     *     snapshotAt: DateTimeImmutable,
     * }
     */
    public function getSnapshot(Tenant $tenant): array
    {
        // ── Frameworks ───────────────────────────────────────────────────────
        $activeFrameworks = $this->frameworkRepository->findActiveFrameworks();

        $frameworks = [];
        $frameworkCompliance = [];

        foreach ($activeFrameworks as $fw) {
            $frameworks[] = [
                'code' => (string) $fw->getCode(),
                'name' => (string) $fw->getName(),
            ];

            $stats = $this->requirementRepository->getFrameworkStatisticsForTenant($fw, $tenant);
            $applicable = $stats['applicable'] ?? 0;
            $fulfilled  = $stats['fulfilled'] ?? 0;
            $percent    = $applicable > 0 ? round(($fulfilled / $applicable) * 100, 1) : 0.0;

            $frameworkCompliance[] = [
                'code'       => (string) $fw->getCode(),
                'name'       => (string) $fw->getName(),
                'fulfilled'  => $fulfilled,
                'applicable' => $applicable,
                'percent'    => $percent,
            ];
        }

        // ── Overall control-compliance % (from KpiSnapshot) ──────────────────
        $overallControlPct = null;
        $latestSnapshot = $this->kpiSnapshotRepository->findClosestBefore($tenant, new DateTimeImmutable());
        if ($latestSnapshot !== null) {
            $kpiData = $latestSnapshot->getKpiData();
            if (isset($kpiData['control_compliance']) && is_numeric($kpiData['control_compliance'])) {
                $overallControlPct = (float) $kpiData['control_compliance'];
            }
        }

        // ── Last completed internal audit date (DATE ONLY) ───────────────────
        $lastAuditDate = $this->resolveLastAuditDate($tenant);

        return [
            'tenantName'          => (string) $tenant->getName(),
            'frameworks'          => $frameworks,
            'frameworkCompliance' => $frameworkCompliance,
            'overallControlPct'   => $overallControlPct,
            'lastAuditDate'       => $lastAuditDate,
            'snapshotAt'          => new DateTimeImmutable(),
        ];
    }

    /**
     * Find the most recent "completed" or "closed" internal audit date for the
     * tenant. Returns a Y-m-d date string or null when no qualifying audit exists.
     *
     * Only the date component is exposed — no audit title, scope, findings, or
     * other detail leaves the perimeter.
     */
    private function resolveLastAuditDate(Tenant $tenant): ?string
    {
        // Use actualDate (the date when the audit was actually conducted) for
        // "completed" / "in_progress" / "conducted" / "closed" status variants.
        // Iterate the tenant's audits sorted desc and find the first qualifying one.
        $completedStatuses = [
            InternalAuditStatus::Completed->value,
            InternalAuditStatus::Closed->value,
            InternalAuditStatus::Conducted->value,
        ];

        $audits = $this->auditRepository->findBy(
            ['tenant' => $tenant],
            ['actualDate' => 'DESC'],
            10
        );

        foreach ($audits as $audit) {
            if (!in_array($audit->getStatus(), $completedStatuses, true)) {
                continue;
            }
            $date = $audit->getActualDate();
            if ($date !== null) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }
}
