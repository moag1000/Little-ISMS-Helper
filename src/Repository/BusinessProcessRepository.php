<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\BusinessProcess;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Business Process Repository
 *
 * Repository for querying BusinessProcess entities with business continuity and criticality queries.
 *
 * @extends ServiceEntityRepository<BusinessProcess>
 *
 * @method BusinessProcess|null find($id, $lockMode = null, $lockVersion = null)
 * @method BusinessProcess|null findOneBy(array $criteria, array $orderBy = null)
 * @method BusinessProcess[]    findAll()
 * @method BusinessProcess[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class BusinessProcessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BusinessProcess::class);
    }

    /**
     * Find processes with critical or high criticality for BCP/BCM prioritization.
     *
     * @return BusinessProcess[] Array of critical processes sorted by criticality (critical first) then name
     */
    public function findCriticalProcesses(): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.criticality IN (:criticalities)')
            ->setParameter('criticalities', ['critical', 'high'])
            ->orderBy('bp.criticality', 'DESC')
            ->addOrderBy('bp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find processes with low RTO (Recovery Time Objective) indicating high availability requirements.
     *
     * @param int $maxRto Maximum RTO in hours (default: 4 hours)
     * @return BusinessProcess[] Array of processes sorted by RTO (lowest first)
     */
    public function findHighAvailabilityProcesses(int $maxRto = 4): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.rto <= :maxRto')
            ->setParameter('maxRto', $maxRto)
            ->orderBy('bp.rto', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find processes that depend on a specific supporting asset.
     *
     * @param int $assetId Asset identifier
     * @return BusinessProcess[] Array of processes sorted by name
     */
    public function findByAsset(int $assetId): array
    {
        return $this->createQueryBuilder('bp')
            ->join('bp.supportingAssets', 'a')
            ->where('a.id = :assetId')
            ->setParameter('assetId', $assetId)
            ->orderBy('bp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get comprehensive statistics about business processes for dashboard/reporting.
     *
     * @return array<string, int|float> Statistics array containing:
     *   - total: Total process count
     *   - critical: Count of critical processes
     *   - high: Count of high-criticality processes
     *   - avg_rto: Average Recovery Time Objective in hours
     *   - avg_mtpd: Average Maximum Tolerable Period of Disruption in hours
     */
    public function getStatistics(): array
    {
        $queryBuilder = $this->createQueryBuilder('bp');

        return [
            'total' => $queryBuilder->select('COUNT(bp.id)')
                ->getQuery()
                ->getSingleScalarResult(),

            'critical' => $this->createQueryBuilder('bp')
                ->select('COUNT(bp.id)')
                ->where('bp.criticality = :criticality')
                ->setParameter('criticality', 'critical')
                ->getQuery()
                ->getSingleScalarResult(),

            'high' => $this->createQueryBuilder('bp')
                ->select('COUNT(bp.id)')
                ->where('bp.criticality = :criticality')
                ->setParameter('criticality', 'high')
                ->getQuery()
                ->getSingleScalarResult(),

            'avg_rto' => $this->createQueryBuilder('bp')
                ->select('AVG(bp.rto)')
                ->getQuery()
                ->getSingleScalarResult() ?? 0,

            'avg_mtpd' => $this->createQueryBuilder('bp')
                ->select('AVG(bp.mtpd)')
                ->getQuery()
                ->getSingleScalarResult() ?? 0,
        ];
    }

    /**
     * Find processes with high impact scores (financial, reputational, or operational).
     *
     * @param int $threshold Minimum impact score (1-10 scale, default: 8)
     * @return BusinessProcess[] Array of processes sorted by impact (financial, reputational, operational)
     */
    public function findHighImpactProcesses(int $threshold = 8): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.financialImpact >= :threshold OR bp.reputationalImpact >= :threshold OR bp.operationalImpact >= :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('bp.financialImpact', 'DESC')
            ->addOrderBy('bp.reputationalImpact', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all business processes for a tenant (own processes only)
     *
     * @param Tenant $tenant The tenant to find processes for
     * @return BusinessProcess[] Array of BusinessProcess entities
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('bp')
            ->where('bp.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('bp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find business processes by tenant including all ancestors (for hierarchical governance)
     * This allows viewing inherited processes from parent companies, grandparents, etc.
     *
     * @param Tenant $tenant The tenant to find processes for
     * @param Tenant|null $parentTenant DEPRECATED: Use tenant's getAllAncestors() instead
     * @return BusinessProcess[] Array of BusinessProcess entities (own + inherited from all ancestors)
     */
    public function findByTenantIncludingParent(Tenant $tenant, Tenant|null $parentTenant = null): array
    {
        // Get all ancestors (parent, grandparent, great-grandparent, etc.)
        $ancestors = $tenant->getAllAncestors();

        $queryBuilder = $this->createQueryBuilder('bp')
            ->where('bp.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include processes from all ancestors in the hierarchy
        if ($ancestors !== []) {
            $queryBuilder->orWhere('bp.tenant IN (:ancestors)')
               ->setParameter('ancestors', $ancestors);
        }

        return $queryBuilder
            ->orderBy('bp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find business processes by tenant including all subsidiaries (for corporate parent view)
     * This allows viewing aggregated processes from all subsidiary companies
     *
     * @param Tenant $tenant The tenant to find processes for
     * @return BusinessProcess[] Array of BusinessProcess entities (own + from all subsidiaries)
     */
    public function findByTenantIncludingSubsidiaries(Tenant $tenant): array
    {
        // Get all subsidiaries recursively
        $subsidiaries = $tenant->getAllSubsidiaries();

        $queryBuilder = $this->createQueryBuilder('bp')
            ->where('bp.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include processes from all subsidiaries in the hierarchy
        if ($subsidiaries !== []) {
            $queryBuilder->orWhere('bp.tenant IN (:subsidiaries)')
               ->setParameter('subsidiaries', $subsidiaries);
        }

        return $queryBuilder
            ->orderBy('bp.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
