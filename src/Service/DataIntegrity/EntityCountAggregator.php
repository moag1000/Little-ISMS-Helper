<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use App\Repository\AssetRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ControlRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\KpiSnapshotRepository;
use App\Repository\LocationRepository;
use App\Repository\PersonRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Repository\TenantRepository;
use App\Repository\TrainingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aggregates entity counts per tenant and computes health-score statistics.
 *
 * Extracted from {@see \App\Service\DataIntegrityService} to remove the 13
 * inline createQueryBuilder calls and the private scoring formula from the
 * facade, keeping it a thin delegation layer.
 *
 * Public API:
 *   - countByTenant()        → per-tenant count map (used for admin dashboard)
 *   - summaryStatistics()    → issue totals + health score (delegates find* to facade)
 *   - calculateHealthScore() → 0-100 score from weighted issue counts
 */
final class EntityCountAggregator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantRepository $tenantRepository,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly InternalAuditRepository $auditRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly LocationRepository $locationRepository,
        private readonly PersonRepository $personRepository,
        private readonly ?DataSubjectRequestRepository $dataSubjectRequestRepository = null,
        private readonly ?KpiSnapshotRepository $kpiSnapshotRepository = null,
    ) {
    }

    /**
     * Get entity counts grouped by tenant.
     *
     * @return array<int, array{
     *     tenant: \App\Entity\Tenant,
     *     assets: int,
     *     risks: int,
     *     incidents: int,
     *     audits: int,
     *     documents: int,
     *     trainings: int,
     *     business_processes: int,
     *     bc_plans: int,
     *     data_breaches: int,
     *     processing_activities: int,
     *     suppliers: int,
     *     locations: int,
     *     people: int,
     *     data_subject_requests?: int,
     *     kpi_snapshots?: int,
     * }>
     */
    public function countByTenant(): array
    {
        $tenants = $this->tenantRepository->findAll();
        $counts = [];

        foreach ($tenants as $tenant) {
            $counts[$tenant->getId()] = [
                'tenant' => $tenant,
                'assets' => (int) $this->assetRepository->createQueryBuilder('a')->select('COUNT(a.id)')->where('a.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'risks' => (int) $this->riskRepository->createQueryBuilder('r')->select('COUNT(r.id)')->where('r.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'incidents' => (int) $this->incidentRepository->createQueryBuilder('i')->select('COUNT(i.id)')->where('i.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'audits' => (int) $this->auditRepository->createQueryBuilder('au')->select('COUNT(au.id)')->where('au.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'documents' => (int) $this->documentRepository->createQueryBuilder('d')->select('COUNT(d.id)')->where('d.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'trainings' => (int) $this->trainingRepository->createQueryBuilder('tr')->select('COUNT(tr.id)')->where('tr.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'business_processes' => (int) $this->businessProcessRepository->createQueryBuilder('bp')->select('COUNT(bp.id)')->where('bp.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'bc_plans' => (int) $this->bcPlanRepository->createQueryBuilder('bc')->select('COUNT(bc.id)')->where('bc.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'data_breaches' => (int) $this->dataBreachRepository->createQueryBuilder('db')->select('COUNT(db.id)')->where('db.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'processing_activities' => (int) $this->processingActivityRepository->createQueryBuilder('pa')->select('COUNT(pa.id)')->where('pa.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'suppliers' => (int) $this->supplierRepository->createQueryBuilder('s')->select('COUNT(s.id)')->where('s.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'locations' => (int) $this->locationRepository->createQueryBuilder('l')->select('COUNT(l.id)')->where('l.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
                'people' => (int) $this->personRepository->createQueryBuilder('p')->select('COUNT(p.id)')->where('p.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult(),
            ];

            if ($this->dataSubjectRequestRepository !== null) {
                $counts[$tenant->getId()]['data_subject_requests'] = (int) $this->dataSubjectRequestRepository->createQueryBuilder('dsr')->select('COUNT(dsr.id)')->where('dsr.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult();
            }
            if ($this->kpiSnapshotRepository !== null) {
                $counts[$tenant->getId()]['kpi_snapshots'] = (int) $this->kpiSnapshotRepository->createQueryBuilder('ks')->select('COUNT(ks.id)')->where('ks.tenant = :t')->setParameter('t', $tenant)->getQuery()->getSingleScalarResult();
            }
        }

        return $counts;
    }

    /**
     * Calculate overall data health score (0-100).
     *
     * Denominator (total entities) is computed with tenant_filter disabled so
     * it matches the cross-tenant numerator from findAllOrphanedEntities().
     * Otherwise the score is arithmetically inconsistent on multi-tenant
     * installations (filter-on denominator vs filter-off numerator).
     *
     * The try/finally guarantees the filter is always re-enabled, even on error.
     */
    public function calculateHealthScore(int $orphaned, int $missing, int $broken, int $duplicates, int $inconsistent): int
    {
        $filters = $this->entityManager->getFilters();
        $filterWasEnabled = $filters->isEnabled('tenant_filter');
        if ($filterWasEnabled) {
            $filters->disable('tenant_filter');
        }
        try {
            $totalEntities = count($this->assetRepository->findAll()) +
                            count($this->riskRepository->findAll()) +
                            count($this->incidentRepository->findAll()) +
                            count($this->auditRepository->findAll()) +
                            count($this->documentRepository->findAll());
        } finally {
            if ($filterWasEnabled) {
                $filters->enable('tenant_filter');
            }
        }

        if ($totalEntities === 0) {
            return 100;
        }

        $totalIssues = ($orphaned * 3) + ($broken * 5) + ($missing) + ($duplicates * 2) + ($inconsistent);
        $maxPossibleIssues = $totalEntities * 5; // Max severity weight

        $healthScore = max(0, 100 - (($totalIssues / $maxPossibleIssues) * 100));

        return (int) round($healthScore);
    }
}
