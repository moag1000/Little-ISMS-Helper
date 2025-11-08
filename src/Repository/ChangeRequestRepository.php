<?php

namespace App\Repository;

use App\Entity\ChangeRequest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChangeRequest>
 */
class ChangeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChangeRequest::class);
    }

    /**
     * Find pending approval requests
     */
    public function findPendingApproval(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', ['submitted', 'under_review'])
            ->orderBy('c.priority', 'ASC')
            ->addOrderBy('c.requestedDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find overdue implementations
     */
    public function findOverdue(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.plannedImplementationDate < :now')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('now', new \DateTime())
            ->setParameter('statuses', ['approved', 'scheduled'])
            ->orderBy('c.plannedImplementationDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get change statistics
     */
    public function getStatistics(): array
    {
        $total = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $pending = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', ['submitted', 'under_review'])
            ->getQuery()
            ->getSingleScalarResult();

        $inImplementation = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', ['approved', 'scheduled'])
            ->getQuery()
            ->getSingleScalarResult();

        $implemented = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', ['implemented', 'verified', 'closed'])
            ->getQuery()
            ->getSingleScalarResult();

        $overdue = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.plannedImplementationDate < :now')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('now', new \DateTime())
            ->setParameter('statuses', ['approved', 'scheduled'])
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'pending_approval' => $pending,
            'in_implementation' => $inImplementation,
            'implemented' => $implemented,
            'overdue' => $overdue
        ];
    }
}
