<?php

namespace App\Repository;

use App\Entity\ScheduledTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledTask>
 */
class ScheduledTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledTask::class);
    }

    /**
     * Find all enabled scheduled tasks
     *
     * @return ScheduledTask[]
     */
    public function findEnabled(): array
    {
        return $this->createQueryBuilder('st')
            ->where('st.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('st.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find enabled scheduled tasks for a specific tenant
     *
     * @param int $tenantId
     * @return ScheduledTask[]
     */
    public function findEnabledByTenant(int $tenantId): array
    {
        return $this->createQueryBuilder('st')
            ->where('st.enabled = :enabled')
            ->andWhere('st.tenantId = :tenantId')
            ->setParameter('enabled', true)
            ->setParameter('tenantId', $tenantId)
            ->orderBy('st.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
