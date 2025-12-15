<?php

namespace App\Repository;

use App\Entity\ScheduledReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ScheduledReport>
 */
class ScheduledReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ScheduledReport::class);
    }

    /**
     * Find all active scheduled reports for a tenant
     *
     * @return ScheduledReport[]
     */
    public function findActiveByTenant(int $tenantId): array
    {
        return $this->createQueryBuilder('sr')
            ->andWhere('sr.tenantId = :tenantId')
            ->andWhere('sr.isActive = :active')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('active', true)
            ->orderBy('sr.nextRunAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all reports that are due to run
     *
     * @return ScheduledReport[]
     */
    public function findDueReports(): array
    {
        return $this->createQueryBuilder('sr')
            ->andWhere('sr.isActive = :active')
            ->andWhere('sr.nextRunAt <= :now')
            ->setParameter('active', true)
            ->setParameter('now', new \DateTime())
            ->orderBy('sr.nextRunAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find reports by type for a tenant
     *
     * @return ScheduledReport[]
     */
    public function findByTypeAndTenant(string $reportType, int $tenantId): array
    {
        return $this->createQueryBuilder('sr')
            ->andWhere('sr.tenantId = :tenantId')
            ->andWhere('sr.reportType = :reportType')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('reportType', $reportType)
            ->orderBy('sr.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all reports for a tenant
     *
     * @return ScheduledReport[]
     */
    public function findByTenant(int $tenantId): array
    {
        return $this->createQueryBuilder('sr')
            ->andWhere('sr.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->orderBy('sr.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count active reports for a tenant
     */
    public function countActiveByTenant(int $tenantId): int
    {
        return (int) $this->createQueryBuilder('sr')
            ->select('COUNT(sr.id)')
            ->andWhere('sr.tenantId = :tenantId')
            ->andWhere('sr.isActive = :active')
            ->setParameter('tenantId', $tenantId)
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get statistics for scheduled reports
     */
    public function getStatistics(int $tenantId): array
    {
        $total = $this->count(['tenantId' => $tenantId]);
        $active = $this->countActiveByTenant($tenantId);

        $byType = $this->createQueryBuilder('sr')
            ->select('sr.reportType, COUNT(sr.id) as count')
            ->andWhere('sr.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->groupBy('sr.reportType')
            ->getQuery()
            ->getResult();

        $bySchedule = $this->createQueryBuilder('sr')
            ->select('sr.schedule, COUNT(sr.id) as count')
            ->andWhere('sr.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId)
            ->groupBy('sr.schedule')
            ->getQuery()
            ->getResult();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $total - $active,
            'by_type' => array_column($byType, 'count', 'reportType'),
            'by_schedule' => array_column($bySchedule, 'count', 'schedule'),
        ];
    }
}
