<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoadmapTask;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoadmapTask>
 */
class RoadmapTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoadmapTask::class);
    }

    /**
     * Active tasks for a tenant, name-sorted.
     *
     * @return list<RoadmapTask>
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        /** @var list<RoadmapTask> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.isActive = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Active reactive-reservation tasks for a tenant (standing daily-business rows).
     *
     * @return list<RoadmapTask>
     */
    public function findReactiveByTenant(Tenant $tenant): array
    {
        /** @var list<RoadmapTask> $rows */
        $rows = $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.isActive = :active')
            ->andWhere('t.isReactiveReservation = :reactive')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->setParameter('reactive', true)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
