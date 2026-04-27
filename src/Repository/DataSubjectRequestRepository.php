<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DataSubjectRequest;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataSubjectRequest>
 *
 * Repository for DataSubjectRequest entity (GDPR Art. 15-22)
 * Provides specialized queries for data subject rights management
 */
class DataSubjectRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataSubjectRequest::class);
    }

    /**
     * Find all data subject requests for a tenant
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('dsr')
            ->where('dsr.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('dsr.receivedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find overdue requests: effective deadline < now AND status not completed/rejected
     */
    public function findOverdue(Tenant $tenant): array
    {
        $now = new \DateTimeImmutable();

        // We need to check both deadlineAt and extendedDeadlineAt
        // Overdue = (extendedDeadlineAt IS NULL AND deadlineAt < now)
        //        OR (extendedDeadlineAt IS NOT NULL AND extendedDeadlineAt < now)
        return $this->createQueryBuilder('dsr')
            ->where('dsr.tenant = :tenant')
            ->andWhere('dsr.status NOT IN (:terminalStatuses)')
            ->andWhere(
                '(dsr.extendedDeadlineAt IS NULL AND dsr.deadlineAt < :now) OR ' .
                '(dsr.extendedDeadlineAt IS NOT NULL AND dsr.extendedDeadlineAt < :now)'
            )
            ->setParameter('tenant', $tenant)
            ->setParameter('terminalStatuses', ['completed', 'rejected'])
            ->setParameter('now', $now)
            ->orderBy('dsr.deadlineAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find requests by status
     */
    public function findByStatus(Tenant $tenant, string $status): array
    {
        return $this->createQueryBuilder('dsr')
            ->where('dsr.tenant = :tenant')
            ->andWhere('dsr.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', $status)
            ->orderBy('dsr.receivedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count requests grouped by type for dashboard
     *
     * @return array<string, int>
     */
    public function countByType(Tenant $tenant): array
    {
        $results = $this->createQueryBuilder('dsr')
            ->select('dsr.requestType, COUNT(dsr.id) as count')
            ->where('dsr.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('dsr.requestType')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach (DataSubjectRequest::REQUEST_TYPES as $type) {
            $counts[$type] = 0;
        }
        foreach ($results as $row) {
            $counts[$row['requestType']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Get average response time in days (from received to completed)
     */
    public function getAverageResponseTime(Tenant $tenant): ?float
    {
        $completedRequests = $this->createQueryBuilder('dsr')
            ->where('dsr.tenant = :tenant')
            ->andWhere('dsr.status = :status')
            ->andWhere('dsr.completedAt IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getResult();

        if (count($completedRequests) === 0) {
            return null;
        }

        $totalDays = 0;
        foreach ($completedRequests as $request) {
            $diff = $request->getReceivedAt()->diff($request->getCompletedAt());
            $totalDays += $diff->days;
        }

        return round($totalDays / count($completedRequests), 1);
    }
}
