<?php

namespace App\Repository;

use App\Entity\RiskAppetite;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RiskAppetite>
 */
class RiskAppetiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskAppetite::class);
    }

    /**
     * Find active risk appetite for a specific category
     * Returns null if no active appetite found
     */
    public function findActiveByCategoryAndTenant(?string $category, ?Tenant $tenant): ?RiskAppetite
    {
        return $this->createQueryBuilder('ra')
            ->where('ra.category = :category')
            ->andWhere('ra.isActive = :active')
            ->andWhere('ra.tenant = :tenant OR ra.tenant IS NULL')
            ->setParameter('category', $category)
            ->setParameter('active', true)
            ->setParameter('tenant', $tenant)
            ->orderBy('ra.tenant', 'DESC') // Prefer tenant-specific over global
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find global (default) risk appetite
     */
    public function findGlobalAppetiteForTenant(?Tenant $tenant): ?RiskAppetite
    {
        return $this->createQueryBuilder('ra')
            ->where('ra.category IS NULL')
            ->andWhere('ra.isActive = :active')
            ->andWhere('ra.tenant = :tenant OR ra.tenant IS NULL')
            ->setParameter('active', true)
            ->setParameter('tenant', $tenant)
            ->orderBy('ra.tenant', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find applicable risk appetite for a specific category or fall back to global
     * Data Reuse: Auto-select most appropriate appetite
     */
    public function findApplicableAppetite(?string $category, ?Tenant $tenant): ?RiskAppetite
    {
        // Try category-specific first
        if ($category !== null) {
            $appetite = $this->findActiveByCategoryAndTenant($category, $tenant);
            if ($appetite !== null) {
                return $appetite;
            }
        }

        // Fall back to global appetite
        return $this->findGlobalAppetiteForTenant($tenant);
    }

    /**
     * Find all active appetites for a tenant
     */
    public function findAllActiveForTenant(?Tenant $tenant): array
    {
        return $this->createQueryBuilder('ra')
            ->where('ra.isActive = :active')
            ->andWhere('ra.tenant = :tenant OR ra.tenant IS NULL')
            ->setParameter('active', true)
            ->setParameter('tenant', $tenant)
            ->orderBy('ra.category', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all distinct categories that have appetite definitions
     */
    public function findDistinctCategories(?Tenant $tenant): array
    {
        $result = $this->createQueryBuilder('ra')
            ->select('DISTINCT ra.category')
            ->where('ra.category IS NOT NULL')
            ->andWhere('ra.tenant = :tenant OR ra.tenant IS NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('ra.category', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($result, 'category');
    }
}
