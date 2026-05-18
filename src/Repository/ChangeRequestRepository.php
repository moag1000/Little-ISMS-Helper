<?php

declare(strict_types=1);

namespace App\Repository;

use DateTime;
use App\Entity\ChangeRequest;
use App\Entity\Tenant;
use App\Enum\ChangeRequestStatus;
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
            ->setParameter('statuses', [ChangeRequestStatus::Submitted->value, ChangeRequestStatus::UnderReview->value])
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
            ->setParameter('now', new DateTime())
            ->setParameter('statuses', [ChangeRequestStatus::Approved->value, ChangeRequestStatus::Scheduled->value])
            ->orderBy('c.plannedImplementationDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Audit V4 V4-LB-1 Round-2 — Change requests pending approval, scoped
     * to a tenant. ISO 27001 Clause 6.3 (Planning of Changes) requires
     * approval-tracking; the bucket surfaces to Manager/Admin/CAB-members
     * because `approvedBy` is a free-text auto-fill on approval, not a
     * routing FK.
     *
     * `status IN (submitted, under_review)` AND `tenant = :tenant`.
     *
     * @return ChangeRequest[]
     */
    public function findPendingApprovalByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.tenant = :tenant')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', [ChangeRequestStatus::Submitted->value, ChangeRequestStatus::UnderReview->value])
            ->orderBy('c.priority', 'ASC')
            ->addOrderBy('c.requestedDate', 'ASC')
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
            ->setParameter('statuses', [ChangeRequestStatus::Submitted->value, ChangeRequestStatus::UnderReview->value])
            ->getQuery()
            ->getSingleScalarResult();

        $inImplementation = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', [ChangeRequestStatus::Approved->value, ChangeRequestStatus::Scheduled->value])
            ->getQuery()
            ->getSingleScalarResult();

        $implemented = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.status IN (:statuses)')
            ->setParameter('statuses', [ChangeRequestStatus::Implemented->value, ChangeRequestStatus::Verified->value, ChangeRequestStatus::Closed->value])
            ->getQuery()
            ->getSingleScalarResult();

        $overdue = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.plannedImplementationDate < :now')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('now', new DateTime())
            ->setParameter('statuses', [ChangeRequestStatus::Approved->value, ChangeRequestStatus::Scheduled->value])
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
