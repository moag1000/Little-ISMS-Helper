<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AssetSubType;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AssetSubType>
 *
 * @method AssetSubType|null find($id, $lockMode = null, $lockVersion = null)
 * @method AssetSubType|null findOneBy(array $criteria, array $orderBy = null)
 * @method AssetSubType[]    findAll()
 * @method AssetSubType[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AssetSubTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssetSubType::class);
    }

    /**
     * Filter sub-types for the dependent-select widget — by tenant + top-level type.
     *
     * @return list<AssetSubType>
     */
    public function findByTenantAndTopType(Tenant $tenant, string $topType): array
    {
        /** @var list<AssetSubType> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenant')
            ->andWhere('s.topType = :topType')
            ->andWhere('s.isActive = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('topType', $topType)
            ->setParameter('active', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * All active sub-types for the given tenant — used by Admin index.
     *
     * @return list<AssetSubType>
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        /** @var list<AssetSubType> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenant')
            ->andWhere('s.isActive = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->orderBy('s.topType', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * All sub-types (active + inactive) for admin index — sorted by top-type then name.
     *
     * @return list<AssetSubType>
     */
    public function findAllByTenant(Tenant $tenant): array
    {
        /** @var list<AssetSubType> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('s.topType', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Count sub-types per top-type for the given tenant — used for KPI display.
     *
     * @return array<string, int>
     */
    public function countByTopType(Tenant $tenant): array
    {
        $results = $this->createQueryBuilder('s')
            ->select('s.topType AS topType, COUNT(s.id) AS cnt')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('s.topType')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[(string) $row['topType']] = (int) $row['cnt'];
        }

        return $counts;
    }
}
