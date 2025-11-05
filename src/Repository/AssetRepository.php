<?php

namespace App\Repository;

use App\Entity\Asset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asset::class);
    }

    public function findActiveAssets(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('a.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

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
