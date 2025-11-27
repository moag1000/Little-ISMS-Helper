<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\Risk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Risk Repository
 *
 * Repository for querying Risk entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Risk>
 *
 * @method Risk|null find($id, $lockMode = null, $lockVersion = null)
 * @method Risk|null findOneBy(array $criteria, array $orderBy = null)
 * @method Risk[]    findAll()
 * @method Risk[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RiskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Risk::class);
    }

    /**
     * Find risks with high risk scores (probability × impact >= threshold).
     *
     * @param int $threshold Minimum risk score to consider as high risk (default: 12)
     * @return Risk[] Array of Risk entities sorted by severity
     */
    public function findHighRisks(int $threshold = 12): array
    {
        return $this->createQueryBuilder('r')
            ->where('(r.probability * r.impact) >= :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('r.probability', 'DESC')
            ->addOrderBy('r.impact', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count risks grouped by treatment strategy.
     *
     * @return array<array{treatmentStrategy: string, count: int}> Array of counts per strategy
     */
    public function countByTreatmentStrategy(): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.treatmentStrategy, COUNT(r.id) as count')
            ->groupBy('r.treatmentStrategy')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all risks for a tenant (own risks only)
     *
     * @param Tenant $tenant The tenant to find risks for
     * @return Risk[] Array of Risk entities
     */
    public function findByTenant($tenant): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find risks by tenant including all ancestors (for hierarchical governance)
     * This allows viewing inherited risks from parent companies, grandparents, etc.
     *
     * @param Tenant $tenant The tenant to find risks for
     * @param Tenant|null $parentTenant DEPRECATED: Use tenant's getAllAncestors() instead
     * @return Risk[] Array of Risk entities (own + inherited from all ancestors)
     */
    public function findByTenantIncludingParent($tenant, $parentTenant = null): array
    {
        // Get all ancestors (parent, grandparent, great-grandparent, etc.)
        $ancestors = $tenant->getAllAncestors();

        $queryBuilder = $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include risks from all ancestors in the hierarchy
        if (!empty($ancestors)) {
            $queryBuilder->orWhere('r.tenant IN (:ancestors)')
               ->setParameter('ancestors', $ancestors);
        }

        return $queryBuilder
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get risk statistics for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @return array{total: int, high: int, medium: int, low: int} Risk statistics
     */
    public function getRiskStatsByTenant($tenant): array
    {
        $risks = $this->findByTenant($tenant);

        $stats = [
            'total' => count($risks),
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        foreach ($risks as $risk) {
            $riskScore = ($risk->getProbability() ?? 0) * ($risk->getImpact() ?? 0);

            if ($riskScore >= 12) {
                $stats['high']++;
            } elseif ($riskScore >= 6) {
                $stats['medium']++;
            } else {
                $stats['low']++;
            }
        }

        return $stats;
    }

    /**
     * Find high risks for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @param int $threshold Minimum risk score threshold (default: 12)
     * @return Risk[] Array of high-risk entities
     */
    public function findHighRisksByTenant($tenant, int $threshold = 12): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('(r.probability * r.impact) >= :threshold')
            ->setParameter('tenant', $tenant)
            ->setParameter('threshold', $threshold)
            ->orderBy('r.probability', 'DESC')
            ->addOrderBy('r.impact', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find risks by tenant including all subsidiaries (for corporate parent view)
     * This allows viewing aggregated risks from all subsidiary companies
     *
     * @param Tenant $tenant The tenant to find risks for
     * @return Risk[] Array of Risk entities (own + from all subsidiaries)
     */
    public function findByTenantIncludingSubsidiaries($tenant): array
    {
        // Get all subsidiaries recursively
        $subsidiaries = $tenant->getAllSubsidiaries();

        $queryBuilder = $this->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include risks from all subsidiaries in the hierarchy
        if (!empty($subsidiaries)) {
            $queryBuilder->orWhere('r.tenant IN (:subsidiaries)')
               ->setParameter('subsidiaries', $subsidiaries);
        }

        return $queryBuilder
            ->orderBy('r.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
