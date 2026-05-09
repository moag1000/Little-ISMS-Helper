<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IndustryPresetBundle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IndustryPresetBundle>
 */
class IndustryPresetBundleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IndustryPresetBundle::class);
    }

    public function findByKey(string $key): ?IndustryPresetBundle
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * @return list<IndustryPresetBundle>
     */
    public function findActiveBundles(): array
    {
        /** @var list<IndustryPresetBundle> $rows */
        $rows = $this->createQueryBuilder('b')
            ->andWhere('b.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('b.label', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
