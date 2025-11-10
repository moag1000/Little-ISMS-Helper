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
}
