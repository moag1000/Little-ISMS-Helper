<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use App\Repository\AssetRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
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
 * EntityCountAggregator — extracted from DataIntegrityService facade (arch followup).
 *
 * Owns the per-tenant count queries, summary aggregation, and health-score formula
 * that previously lived as inline methods on the facade. Concentrating the 13
 * count-query repositories here keeps the facade's constructor dep-list stable
 * and makes this concern independently testable.
 *
 * Public API:
 *   - countByTenant()                                       → per-tenant count arrays
 *   - summarize(orphaned, missing, broken, dupes, incon)    → flat summary + health_score
 *   - healthScore(orphaned, missing, broken, dupes, incon)  → int 0-100
 *
 * The health-score denominator is computed with tenant_filter disabled so it
 * matches the cross-tenant numerator from findAllOrphanedEntities(). This
 * pattern is preserved from the original DataIntegrityService.calculateHealthScore().
 *
 * @see \App\Service\DataIntegrityService::getEntityCountsByTenant()
 * @see \App\Service\DataIntegrityService::getSummaryStatistics()
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
     * Each entry in the returned map keyed by tenant-ID contains:
     *   'tenant' => Tenant entity
     *   + one integer COUNT per entity-type
     *
     * @return array<int, array<string, mixed>>
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
     * Aggregate raw integrity-check result arrays into summary statistics.
     *
     * @param array<string, mixed> $orphaned     Output of findAllOrphanedEntities()
     * @param array<string, mixed> $missing      Output of findMissingRelationships()
     * @param array<string, mixed> $broken       Output of findBrokenReferences()
     * @param array<string, mixed> $duplicates   Output of findDuplicateEntities()
     * @param array<string, mixed> $inconsistent Output of findInconsistentData()
     * @return array{
     *     total_issues: int,
     *     orphaned_count: int,
     *     missing_relationships_count: int,
     *     broken_references_count: int,
     *     duplicates_count: int,
     *     inconsistent_count: int,
     *     health_score: int,
     * }
     */
    public function summarize(array $orphaned, array $missing, array $broken, array $duplicates, array $inconsistent): array
    {
        $totalOrphaned = 0;
        foreach ($orphaned as $entities) {
            $totalOrphaned += count($entities);
        }

        $totalMissing = 0;
        foreach ($missing as $entities) {
            $totalMissing += count($entities);
        }

        $totalDuplicates = 0;
        foreach ($duplicates as $groups) {
            $totalDuplicates += count($groups);
        }

        $totalInconsistent = 0;
        foreach ($inconsistent as $entities) {
            $totalInconsistent += count($entities);
        }

        return [
            'total_issues' => $totalOrphaned + $totalMissing + count($broken) + $totalDuplicates + $totalInconsistent,
            'orphaned_count' => $totalOrphaned,
            'missing_relationships_count' => $totalMissing,
            'broken_references_count' => count($broken),
            'duplicates_count' => $totalDuplicates,
            'inconsistent_count' => $totalInconsistent,
            'health_score' => $this->healthScore($totalOrphaned, $totalMissing, count($broken), $totalDuplicates, $totalInconsistent),
        ];
    }

    /**
     * Calculate overall data health score (0-100).
     *
     * Denominator (total entities) is computed with tenant_filter disabled so
     * it matches the cross-tenant numerator from findAllOrphanedEntities().
     * Otherwise the score is arithmetically inconsistent on multi-tenant
     * installations (filter-on denominator vs filter-off numerator).
     *
     * This pattern is preserved from DataIntegrityService::calculateHealthScore().
     */
    public function healthScore(int $orphaned, int $missing, int $broken, int $duplicates, int $inconsistent): int
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
