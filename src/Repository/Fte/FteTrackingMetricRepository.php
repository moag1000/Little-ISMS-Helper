<?php

declare(strict_types=1);

namespace App\Repository\Fte;

use App\Entity\Fte\FteTrackingMetric;
use App\Entity\Tenant;
use DateInterval;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FteTrackingMetric>
 */
class FteTrackingMetricRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FteTrackingMetric::class);
    }

    /**
     * Total savings in minutes for a tenant over a rolling window.
     */
    public function getSavingsAggregate(Tenant $tenant, DateInterval $window): int
    {
        $since = (new DateTimeImmutable())->sub($window);

        $result = $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(m.savingsMinutes), 0)')
            ->where('m.tenant = :tenant')
            ->andWhere('m.recordedAt >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * @return array<string, int> source => totalSavingsMinutes
     */
    public function getSavingsBySource(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('m')
            ->select('m.source, COALESCE(SUM(m.savingsMinutes), 0) AS total')
            ->where('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('m.source')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['source']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * Monthly savings trend — returns last $months months keyed by 'YYYY-MM'.
     *
     * @return array<string, int> 'YYYY-MM' => totalSavingsMinutes
     */
    public function getMonthlyTrend(Tenant $tenant, int $months = 12): array
    {
        $since = (new DateTimeImmutable())->modify("-{$months} months")->modify('first day of this month midnight');

        $rows = $this->createQueryBuilder('m')
            ->select("SUBSTRING(m.recordedAt, 1, 7) AS month_key, COALESCE(SUM(m.savingsMinutes), 0) AS total")
            ->where('m.tenant = :tenant')
            ->andWhere('m.recordedAt >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('since', $since)
            ->groupBy('month_key')
            ->orderBy('month_key', 'ASC')
            ->getQuery()
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['month_key']] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * All-time totals for a tenant.
     */
    public function getTotalSavingsAllTime(Tenant $tenant): int
    {
        $result = $this->createQueryBuilder('m')
            ->select('COALESCE(SUM(m.savingsMinutes), 0)')
            ->where('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }
}
