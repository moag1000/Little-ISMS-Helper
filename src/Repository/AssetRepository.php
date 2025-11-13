<?php

namespace App\Repository;

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
     * @param \App\Entity\Tenant $tenant The tenant to find assets for
     * @return Asset[] Array of Asset entities
     */
    public function findByTenant($tenant): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find assets by tenant or parent tenant (for hierarchical governance)
     * This allows viewing inherited assets from parent companies
     *
     * @param \App\Entity\Tenant $tenant The tenant to find assets for
     * @param \App\Entity\Tenant|null $parentTenant Optional parent tenant for inherited assets
     * @return Asset[] Array of Asset entities (own + inherited)
     */
    public function findByTenantIncludingParent($tenant, $parentTenant = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        if ($parentTenant) {
            $qb->orWhere('a.tenant = :parentTenant')
               ->setParameter('parentTenant', $parentTenant);
        }

        return $qb
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get asset statistics for a specific tenant
     *
     * @param \App\Entity\Tenant $tenant The tenant
     * @return array{total: int, active: int, inactive: int} Asset statistics
     */
    public function getAssetStatsByTenant($tenant): array
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
     * @param \App\Entity\Tenant $tenant The tenant
     * @return Asset[] Array of active asset entities
     */
    public function findActiveAssetsByTenant($tenant): array
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
}
