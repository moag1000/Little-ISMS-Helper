<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoadmapGroup;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoadmapGroup>
 */
class RoadmapGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoadmapGroup::class);
    }

    /**
     * Active groups for a tenant, ordered by sortOrder then name.
     *
     * @return list<RoadmapGroup>
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        /** @var list<RoadmapGroup> $rows */
        $rows = $this->createQueryBuilder('g')
            ->andWhere('g.tenant = :tenant')
            ->andWhere('g.isActive = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->orderBy('g.sortOrder', 'ASC')
            ->addOrderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
