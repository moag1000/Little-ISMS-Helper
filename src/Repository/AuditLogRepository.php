<?php

declare(strict_types=1);

namespace App\Repository;

use App\Util\LikePattern;
use DateTimeInterface;
use DateTime;
use DateTimeImmutable;
use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
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
            ->orderBy('a.createdAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find audit logs scoped to a specific tenant.
     *
     * Symfony-BP audit #5 (2026-05-18): AuditLog now carries an explicit
     * `tenant_id` FK captured at write time. The prior string-JOIN
     * (userName → User.email → User.tenant_id) leaked rows whenever a
     * user was renamed or moved between tenants. The legacy backfill
     * migration sets the FK from the user lookup for historic rows.
     *
     * Rows with NULL tenant (system/CLI actions, deleted users) are
     * excluded — surface them via {@see findSystemActions()} if needed.
     *
     * @return AuditLog[]
     */
    public function findAllOrderedForTenant(Tenant $tenant, int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('a.createdAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find audit logs by entity
     */
    public function findByEntity(string $entityType, int $entityId, int $limit = 500): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.createdAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * The newest audit logs of one record (history block on detail pages).
     *
     * @return list<AuditLog>
     */
    public function findRecentByEntity(string $entityType, int $entityId, int $limit = 10): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('a.createdAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Latest audit row per record — the date the record last changed (status
     * included), for retention counted from completion. Not tenant-filtered on
     * purpose: older cron rows carry no tenant, and (type, id) names one record.
     *
     * @param list<int> $entityIds
     * @return array<int, DateTimeImmutable> entity id => last change
     */
    public function lastChangeAt(string $entityType, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('a')
            ->select('a.entityId AS id, MAX(a.createdAt) AS last')
            ->where('a.entityType = :type')
            ->andWhere('a.entityId IN (:ids)')
            ->setParameter('type', $entityType)
            ->setParameter('ids', $entityIds)
            ->groupBy('a.entityId')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            if ($row['last'] !== null) {
                $out[(int) $row['id']] = new DateTimeImmutable((string) $row['last']);
            }
        }

        return $out;
    }

    public function countByEntity(string $entityType, int $entityId): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.entityType = :entityType')
            ->andWhere('a.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find audit logs by user
     */
    public function findByUser(string $userName, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.userName = :userName')
            ->setParameter('userName', $userName)
            ->orderBy('a.createdAt', \SortDirection::Descending)
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
            ->orderBy('a.createdAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find audit logs by date range
     *
     * @param \App\Entity\Tenant[]|null $tenants restrict to logs of these tenants (null = all)
     */
    public function findByDateRange(DateTimeInterface $start, DateTimeInterface $end, ?array $tenants = null): array
    {
        if ($tenants === []) {
            return [];
        }

        $queryBuilder = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :start')
            ->andWhere('a.createdAt <= :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($tenants !== null) {
            $queryBuilder->andWhere('a.tenant IN (:tenants)')
                ->setParameter('tenants', $tenants);
        }

        return $queryBuilder
            ->orderBy('a.createdAt', \SortDirection::Descending)
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
            ->orderBy('count', \SortDirection::Descending)
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
            ->orderBy('count', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get recent activity (last 24 hours)
     */
    public function getRecentActivity(int $hours = 24, int $limit = 500): array
    {
        $since = new DateTime("-{$hours} hours");

        return $this->createQueryBuilder('a')
            ->where('a.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('a.createdAt', \SortDirection::Descending)
            ->setMaxResults(max(1, min(1000, $limit)))
            ->getQuery()
            ->getResult();
    }

    /**
     * Distinct non-empty values of one column, for filter dropdowns (S31: never load the table for them).
     *
     * @param string $field `entityType` or `action` (fixed list — the name goes into DQL)
     * @return list<string>
     */
    public function distinctValues(string $field): array
    {
        if (!in_array($field, ['entityType', 'action'], true)) {
            // @intentional-assertion: fixed whitelist of two columns
            throw new \InvalidArgumentException('Unsupported field ' . $field);
        }
        $rows = $this->createQueryBuilder('a')
            ->select("DISTINCT a.$field AS v")
            ->where("a.$field IS NOT NULL")
            ->orderBy('v', \SortDirection::Ascending)
            ->setMaxResults(1000)
            ->getQuery()
            ->getScalarResult();

        return array_values(array_filter(
            array_map(static fn (array $r): string => (string) $r['v'], $rows),
            static fn (string $v): bool => $v !== '',
        ));
    }

    /**
     * Activity of the last N hours as aggregates: total, distinct users, per action and per entity type
     * (S31: GROUP BY queries instead of hydrating every row of the window).
     *
     * @return array{total: int, users: int, actions: array<string, int>, entityTypes: array<string, int>}
     */
    public function recentActivitySummary(int $hours = 24): array
    {
        $since = new DateTimeImmutable("-{$hours} hours");
        $totals = $this->createQueryBuilder('a')
            ->select('COUNT(a.id) AS total', 'COUNT(DISTINCT a.userName) AS users')
            ->where('a.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleResult();

        return [
            'total' => (int) $totals['total'],
            'users' => (int) $totals['users'],
            'actions' => $this->countsSince('action', $since),
            'entityTypes' => $this->countsSince('entityType', $since),
        ];
    }

    /** @return array<string, int> */
    private function countsSince(string $field, DateTimeImmutable $since): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select("a.$field AS k", 'COUNT(a.id) AS n')
            ->where('a.createdAt >= :since')
            ->andWhere("a.$field IS NOT NULL")
            ->setParameter('since', $since)
            ->groupBy("a.$field")
            ->orderBy('n', \SortDirection::Descending)
            ->setMaxResults(50)
            ->getQuery()
            ->getScalarResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['k']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Number of entries per calendar day for the last $days days (oldest first), in one query.
     *
     * @return array<string, int> Y-m-d => count
     */
    public function countPerDay(int $days, ?DateTimeImmutable $today = null): array
    {
        $days = max(1, min(366, $days));
        $start = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0)->modify('-' . ($days - 1) . ' days');
        $perDay = $this->createQueryBuilder('a')
            ->where('a.createdAt >= :start')
            ->setParameter('start', $start);
        $keys = [];
        $columns = [];
        for ($i = 0; $i < $days; ++$i) {
            $from = $start->modify("+$i days");
            $keys["d$i"] = $from->format('Y-m-d');
            $columns[] = sprintf('SUM(CASE WHEN a.createdAt >= :f%1$d AND a.createdAt < :t%1$d THEN 1 ELSE 0 END) AS d%1$d', $i);
            $perDay->setParameter("f$i", $from)->setParameter("t$i", $from->modify('+1 day'));
        }
        $row = $perDay->select($columns)->getQuery()->getSingleResult();
        $out = [];
        foreach ($keys as $alias => $date) {
            $out[$date] = (int) ($row[$alias] ?? 0);
        }

        return $out;
    }

    /**
     * Count audit entries of the last N hours for one tenant (SQL COUNT, no hydration).
     */
    public function countRecentActivityForTenant(Tenant $tenant, int $hours = 24): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt >= :since')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('since', new DateTime("-{$hours} hours"))
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Search audit logs
     */
    public function search(array $criteria): array
    {
        $queryBuilder = $this->createQueryBuilder('a');
        $this->applySearchCriteria($queryBuilder, $criteria);

        $queryBuilder->orderBy('a.createdAt', \SortDirection::Descending)
            ->setMaxResults($criteria['limit'] ?? 100);

        // Offset enables real pagination of filtered results (previously the
        // controller paged in PHP off a capped result set, so page >1 was empty
        // and the total was wrong).
        if (isset($criteria['offset'])) {
            $queryBuilder->setFirstResult((int) $criteria['offset']);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Total number of audit logs matching the same filters as search() — used
     * for honest pagination totals (not the capped page-size count).
     */
    public function countSearch(array $criteria): int
    {
        $queryBuilder = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        $this->applySearchCriteria($queryBuilder, $criteria);

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Apply the shared WHERE filters used by both search() and countSearch().
     */
    private function applySearchCriteria(\Doctrine\ORM\QueryBuilder $queryBuilder, array $criteria): void
    {
        if (!empty($criteria['entityType'])) {
            $queryBuilder->andWhere('a.entityType = :entityType')
               ->setParameter('entityType', $criteria['entityType']);
        }

        if (!empty($criteria['action'])) {
            $queryBuilder->andWhere('a.action = :action')
               ->setParameter('action', $criteria['action']);
        }

        if (!empty($criteria['userName'])) {
            $queryBuilder->andWhere('a.userName LIKE :userName')
               ->setParameter('userName', LikePattern::contains($criteria['userName']));
        }

        if (!empty($criteria['dateFrom'])) {
            $queryBuilder->andWhere('a.createdAt >= :dateFrom')
               ->setParameter('dateFrom', $criteria['dateFrom']);
        }

        if (!empty($criteria['dateTo'])) {
            $queryBuilder->andWhere('a.createdAt <= :dateTo')
               ->setParameter('dateTo', $criteria['dateTo']);
        }
    }

    /**
     * Count audit logs older than cutoff date
     *
     * @param DateTimeImmutable $cutoffDate Logs older than this date will be counted
     * @return int Number of logs older than cutoff date
     */
    public function countOldLogs(DateTimeImmutable $cutoffDate): int
    {
        return $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.createdAt < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find audit logs older than cutoff date
     *
     * @param DateTimeImmutable $cutoffDate Logs older than this date will be returned
     * @param int $limit Maximum number of logs to return
     * @return AuditLog[] Array of audit logs
     */
    public function findOldLogs(DateTimeImmutable $cutoffDate, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.createdAt < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->orderBy('a.createdAt', \SortDirection::Ascending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Delete audit logs older than cutoff date
     *
     * DSGVO Art. 5.1(e) - Storage Limitation compliance
     * NIS2 Art. 21.2 - Audit log retention policy
     *
     * @param DateTimeImmutable $cutoffDate Logs older than this date will be deleted
     * @return int Number of deleted logs
     */
    public function deleteOldLogs(DateTimeImmutable $cutoffDate): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->where('a.createdAt < :cutoffDate')
            ->setParameter('cutoffDate', $cutoffDate)
            ->getQuery()
            ->execute();
    }
}
