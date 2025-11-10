<?php

namespace App\Repository;

use App\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Audit Log Repository
 *
 * Repository for querying audit trail entries with comprehensive filtering and search capabilities.
 * Supports compliance requirements for activity tracking and forensic analysis.
 *
 * Features:
 * - Entity-specific audit history
 * - User activity tracking
 * - Action-based filtering
 * - Date range queries
 * - Statistical analysis by action and entity type
 * - Recent activity monitoring
 * - Full-text search across multiple criteria
 *
 * @extends ServiceEntityRepository<AuditLog>
 *
 * @method AuditLog|null find($id, $lockMode = null, $lockVersion = null)
 * @method AuditLog|null findOneBy(array $criteria, array $orderBy = null)
 * @method AuditLog[]    findAll()
 * @method AuditLog[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Find all audit logs ordered by date descending
     */
    public function findAllOrdered(int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find audit logs by entity
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find audit logs by user
     */
    public function findByUser(string $userName, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.userName = :userName')
            ->setParameter('userName', $userName)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find audit logs by action
     */
    public function findByAction(string $action, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.action = :action')
            ->setParameter('action', $action)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find audit logs by date range
     */
    public function findByDateRange(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.createdAt >= :start')
            ->andWhere('a.createdAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count total audit logs
     */
    public function countAll(): int
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get statistics by action type
     */
    public function getActionStatistics(): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.action, COUNT(a.id) as count')
            ->groupBy('a.action')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics by entity type
     */
    public function getEntityTypeStatistics(): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.entityType, COUNT(a.id) as count')
            ->groupBy('a.entityType')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get recent activity (last 24 hours)
     */
    public function getRecentActivity(int $hours = 24): array
    {
        $since = new \DateTime("-{$hours} hours");

        return $this->createQueryBuilder('a')
            ->where('a.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search audit logs
     */
    public function search(array $criteria): array
    {
        $qb = $this->createQueryBuilder('a');

        if (!empty($criteria['entityType'])) {
            $qb->andWhere('a.entityType = :entityType')
               ->setParameter('entityType', $criteria['entityType']);
        }

        if (!empty($criteria['action'])) {
            $qb->andWhere('a.action = :action')
               ->setParameter('action', $criteria['action']);
        }

        if (!empty($criteria['userName'])) {
            $qb->andWhere('a.userName LIKE :userName')
               ->setParameter('userName', '%' . $criteria['userName'] . '%');
        }

        if (!empty($criteria['dateFrom'])) {
            $qb->andWhere('a.createdAt >= :dateFrom')
               ->setParameter('dateFrom', $criteria['dateFrom']);
        }

        if (!empty($criteria['dateTo'])) {
            $qb->andWhere('a.createdAt <= :dateTo')
               ->setParameter('dateTo', $criteria['dateTo']);
        }

        return $qb->orderBy('a.createdAt', 'DESC')
                  ->setMaxResults($criteria['limit'] ?? 100)
                  ->getQuery()
                  ->getResult();
    }
}
