<?php

namespace App\Repository;

use App\Entity\WorkflowInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowInstance>
 */
class WorkflowInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowInstance::class);
    }

    /**
     * Find active instances (pending or in progress)
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('wi')
            ->where('wi.status IN (:statuses)')
            ->setParameter('statuses', ['pending', 'in_progress'])
            ->orderBy('wi.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find overdue instances
     */
    public function findOverdue(): array
    {
        return $this->createQueryBuilder('wi')
            ->where('wi.status IN (:statuses)')
            ->andWhere('wi.dueDate < :now')
            ->setParameter('statuses', ['pending', 'in_progress'])
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('wi.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find instances by entity
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('wi')
            ->where('wi.entityType = :entityType')
            ->andWhere('wi.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('wi.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics
     */
    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('wi');

        return [
            'total' => $qb->select('COUNT(wi.id)')->getQuery()->getSingleScalarResult(),
            'pending' => (clone $qb)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', 'pending')->getQuery()->getSingleScalarResult(),
            'in_progress' => (clone $qb)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', 'in_progress')->getQuery()->getSingleScalarResult(),
            'approved' => (clone $qb)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', 'approved')->getQuery()->getSingleScalarResult(),
            'rejected' => (clone $qb)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', 'rejected')->getQuery()->getSingleScalarResult(),
            'cancelled' => (clone $qb)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', 'cancelled')->getQuery()->getSingleScalarResult(),
        ];
    }
}
