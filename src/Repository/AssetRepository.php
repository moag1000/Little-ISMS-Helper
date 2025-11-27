<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\Asset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Asset Repository
 *
 * Repository for querying Asset entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Asset>
 *
 * @method Asset|null find($id, $lockMode = null, $lockVersion = null)
 * @method Asset|null findOneBy(array $criteria, array $orderBy = null)
 * @method Asset[]    findAll()
 * @method Asset[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asset::class);
    }

    /**
     * Find all active assets ordered by name.
     *
     * @return Asset[] Array of active Asset entities
     */
    public function findActiveAssets(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count active assets grouped by asset type.
     *
     * @return array<array{assetType: string, count: int}> Array of counts per asset type
     */
    public function countByType(): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.assetType, COUNT(a.id) as count')
            ->where('a.status = :status')
            ->setParameter('status', 'active')
            ->groupBy('a.assetType')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all assets for a tenant (own assets only)
     *
     * @param Tenant $tenant The tenant to find assets for
     * @return Asset[] Array of Asset entities
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find assets by tenant including all ancestors (for hierarchical governance)
     * This allows viewing inherited assets from parent companies, grandparents, etc.
     *
     * @param Tenant $tenant The tenant to find assets for
     * @param Tenant|null $parentTenant DEPRECATED: Use tenant's getAllAncestors() instead
     * @return Asset[] Array of Asset entities (own + inherited from all ancestors)
     */
    public function findByTenantIncludingParent(Tenant $tenant, $parentTenant = null): array
    {
        // Get all ancestors (parent, grandparent, great-grandparent, etc.)
        $ancestors = $tenant->getAllAncestors();

        $queryBuilder = $this->createQueryBuilder('a')
            ->where('a.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include assets from all ancestors in the hierarchy
        if (!empty($ancestors)) {
            $queryBuilder->orWhere('a.tenant IN (:ancestors)')
               ->setParameter('ancestors', $ancestors);
        }

        return $queryBuilder
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get asset statistics for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @return array{total: int, active: int, inactive: int} Asset statistics
     */
    public function getAssetStatsByTenant(Tenant $tenant): array
    {
        $total = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        $active = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.tenant = :tenant')
            ->andWhere('a.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'active')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => (int) $total,
            'active' => (int) $active,
            'inactive' => (int) ($total - $active),
        ];
    }

    /**
     * Find active assets for a specific tenant
     *
     * @param Tenant $tenant The tenant
     * @return Asset[] Array of active asset entities
     */
    public function findActiveAssetsByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.tenant = :tenant')
            ->andWhere('a.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'active')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find assets by tenant including all subsidiaries (for corporate parent view)
     * This allows viewing aggregated assets from all subsidiary companies
     *
     * @param Tenant $tenant The tenant to find assets for
     * @return Asset[] Array of Asset entities (own + from all subsidiaries)
     */
    public function findByTenantIncludingSubsidiaries(Tenant $tenant): array
    {
        // Get all subsidiaries recursively
        $subsidiaries = $tenant->getAllSubsidiaries();

        $queryBuilder = $this->createQueryBuilder('a')
            ->where('a.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include assets from all subsidiaries in the hierarchy
        if (!empty($subsidiaries)) {
            $queryBuilder->orWhere('a.tenant IN (:subsidiaries)')
               ->setParameter('subsidiaries', $subsidiaries);
        }

        return $queryBuilder
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
