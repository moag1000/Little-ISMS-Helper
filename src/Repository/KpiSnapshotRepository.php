<?php

namespace App\Repository;

use App\Entity\KpiSnapshot;
use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * KPI Snapshot Repository
 *
 * @extends ServiceEntityRepository<KpiSnapshot>
 *
 * @method KpiSnapshot|null find($id, $lockMode = null, $lockVersion = null)
 * @method KpiSnapshot|null findOneBy(array $criteria, array $orderBy = null)
 * @method KpiSnapshot[]    findAll()
 * @method KpiSnapshot[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class KpiSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, KpiSnapshot::class);
    }

    /**
     * Find the closest snapshot on or before a given date for a tenant.
     *
     * @param Tenant $tenant The tenant to query for
     * @param DateTimeImmutable $date The target date (finds closest snapshot <= this date)
     * @return KpiSnapshot|null The snapshot or null if none found
     */
    public function findClosestBefore(Tenant $tenant, DateTimeImmutable $date): ?KpiSnapshot
    {
        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.snapshotDate <= :date')
            ->setParameter('tenant', $tenant)
            ->setParameter('date', $date)
            ->orderBy('s.snapshotDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Check if a snapshot already exists for a tenant on a given date.
     *
     * @param Tenant $tenant The tenant
     * @param DateTimeImmutable $date The date to check
     * @return bool True if a snapshot exists
     */
    public function existsForDate(Tenant $tenant, DateTimeImmutable $date): bool
    {
        $count = $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('s.snapshotDate = :date')
            ->setParameter('tenant', $tenant)
            ->setParameter('date', $date)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Find monthly snapshots for a tenant (last snapshot of each month) over N months.
     * Used for board reporting with 12-month trend views.
     *
     * @param Tenant $tenant The tenant
     * @param int $months Number of months to look back (default: 12)
     * @return KpiSnapshot[] Array of monthly snapshots, ordered oldest first
     */
    /**
     * Liefert alle Snapshots der letzten N Tage in chronologischer Reihenfolge.
     * Geeignet fuer Sparkline-Trends.
     *
     * @return KpiSnapshot[]
     */
    public function findRecentByTenant(Tenant $tenant, int $days = 90): array
    {
        $since = new DateTimeImmutable("-{$days} days");
        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.snapshotDate >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('since', $since)
            ->orderBy('s.snapshotDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findMonthlySnapshots(Tenant $tenant, int $months = 12): array
    {
        $since = new DateTimeImmutable("-{$months} months");

        // Get the last snapshot per month using a subquery approach
        $allSnapshots = $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.snapshotDate >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('since', $since)
            ->orderBy('s.snapshotDate', 'ASC')
            ->getQuery()
            ->getResult();

        // Group by year-month, keep last per month
        $byMonth = [];
        foreach ($allSnapshots as $snapshot) {
            $key = $snapshot->getSnapshotDate()->format('Y-m');
            $byMonth[$key] = $snapshot; // Last one wins (ordered ASC)
        }

        return array_values($byMonth);
    }

    /**
     * Cleanup old daily snapshots while preserving monthly snapshots.
     *
     * Retention policy:
     * - Daily snapshots: keep last 30 days
     * - Monthly snapshots (last per month): keep 12 months
     * - Everything older: delete
     *
     * @param Tenant $tenant The tenant to clean up
     * @return int Number of deleted snapshots
     */
    public function cleanupOldSnapshots(Tenant $tenant, int $dailyRetentionDays = 30, int $monthlyRetentionMonths = 12): int
    {
        $thirtyDaysAgo = new DateTimeImmutable("-{$dailyRetentionDays} days");
        $twelveMonthsAgo = new DateTimeImmutable("-{$monthlyRetentionMonths} months");

        // Step 1: Find all snapshots older than 30 days
        $oldSnapshots = $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.snapshotDate < :cutoff')
            ->setParameter('tenant', $tenant)
            ->setParameter('cutoff', $thirtyDaysAgo)
            ->orderBy('s.snapshotDate', 'ASC')
            ->getQuery()
            ->getResult();

        // Step 2: Group by month, identify which to keep (last per month)
        $byMonth = [];
        foreach ($oldSnapshots as $snapshot) {
            $key = $snapshot->getSnapshotDate()->format('Y-m');
            $byMonth[$key][] = $snapshot;
        }

        $deleted = 0;
        $em = $this->getEntityManager();

        foreach ($byMonth as $yearMonth => $monthSnapshots) {
            // Keep the last snapshot of each month (within 12-month window)
            $lastOfMonth = end($monthSnapshots);
            $monthDate = $lastOfMonth->getSnapshotDate();

            foreach ($monthSnapshots as $snapshot) {
                if ($snapshot === $lastOfMonth && $monthDate >= $twelveMonthsAgo) {
                    // Keep: this is the monthly representative within retention
                    continue;
                }
                if ($snapshot === $lastOfMonth) {
                    // Older than 12 months but last of month — also delete
                    $em->remove($snapshot);
                    $deleted++;
                    continue;
                }
                // Not the last of month — always delete
                $em->remove($snapshot);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $em->flush();
        }

        return $deleted;
    }
}
