<?php

namespace App\Repository;

use DateTime;
use App\Entity\Risk;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RiskTreatmentPlan>
 */
class RiskTreatmentPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskTreatmentPlan::class);
    }

    /**
     * Find all active (not completed/cancelled) plans for a tenant
     */
    public function findActiveForTenant(?Tenant $tenant): array
    {
        return $this->createQueryBuilder('rtp')
            ->where('rtp.status NOT IN (:completed_statuses)')
            ->andWhere('rtp.tenant = :tenant OR rtp.tenant IS NULL')
            ->setParameter('completed_statuses', ['completed', 'cancelled'])
            ->setParameter('tenant', $tenant)
            ->orderBy('rtp.priority', 'DESC')
            ->addOrderBy('rtp.targetCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find overdue treatment plans
     * Data Reuse: Identify plans needing attention
     */
    public function findOverdueForTenant(?Tenant $tenant): array
    {
        $now = new DateTime();

        return $this->createQueryBuilder('rtp')
            ->where('rtp.targetCompletionDate < :now')
            ->andWhere('rtp.status NOT IN (:completed_statuses)')
            ->andWhere('rtp.tenant = :tenant OR rtp.tenant IS NULL')
            ->setParameter('now', $now)
            ->setParameter('completed_statuses', ['completed', 'cancelled'])
            ->setParameter('tenant', $tenant)
            ->orderBy('rtp.priority', 'DESC')
            ->addOrderBy('rtp.targetCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find plans by status
     */
    public function findByStatusForTenant(string $status, ?Tenant $tenant): array
    {
        return $this->createQueryBuilder('rtp')
            ->where('rtp.status = :status')
            ->andWhere('rtp.tenant = :tenant OR rtp.tenant IS NULL')
            ->setParameter('status', $status)
            ->setParameter('tenant', $tenant)
            ->orderBy('rtp.targetCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find plans for a specific risk
     * Data Reuse: Show all treatment plans for a given risk
     */
    public function findByRisk(Risk $risk): array
    {
        return $this->createQueryBuilder('rtp')
            ->where('rtp.risk = :risk')
            ->setParameter('risk', $risk)
            ->orderBy('rtp.status', 'ASC')
            ->addOrderBy('rtp.targetCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find plans assigned to a specific user
     */
    public function findByResponsiblePerson(int $userId, ?Tenant $tenant): array
    {
        return $this->createQueryBuilder('rtp')
            ->where('rtp.responsiblePerson = :userId')
            ->andWhere('rtp.tenant = :tenant OR rtp.tenant IS NULL')
            ->setParameter('userId', $userId)
            ->setParameter('tenant', $tenant)
            ->orderBy('rtp.priority', 'DESC')
            ->addOrderBy('rtp.targetCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find plans due within next X days
     */
    public function findDueWithinDays(int $days, ?Tenant $tenant): array
    {
        $now = new DateTime();
        $futureDate = new DateTime()->modify("+{$days} days");

        return $this->createQueryBuilder('rtp')
            ->where('rtp.targetCompletionDate BETWEEN :now AND :future')
            ->andWhere('rtp.status NOT IN (:completed_statuses)')
            ->andWhere('rtp.tenant = :tenant OR rtp.tenant IS NULL')
            ->setParameter('now', $now)
            ->setParameter('future', $futureDate)
            ->setParameter('completed_statuses', ['completed', 'cancelled'])
            ->setParameter('tenant', $tenant)
            ->orderBy('rtp.targetCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics for treatment plans
     * Data Reuse: Dashboard metrics
     */
    public function getStatisticsForTenant(?Tenant $tenant): array
    {
        $queryBuilder = $this->createQueryBuilder('rtp')
            ->select(
                'COUNT(rtp.id) as total',
                'SUM(CASE WHEN rtp.status = :planned THEN 1 ELSE 0 END) as planned',
                'SUM(CASE WHEN rtp.status = :in_progress THEN 1 ELSE 0 END) as in_progress',
                'SUM(CASE WHEN rtp.status = :completed THEN 1 ELSE 0 END) as completed',
                'SUM(CASE WHEN rtp.status = :cancelled THEN 1 ELSE 0 END) as cancelled',
                'SUM(CASE WHEN rtp.status = :on_hold THEN 1 ELSE 0 END) as on_hold',
                'AVG(rtp.completionPercentage) as avg_completion'
            )
            ->where('rtp.tenant = :tenant OR rtp.tenant IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('planned', 'planned')
            ->setParameter('in_progress', 'in_progress')
            ->setParameter('completed', 'completed')
            ->setParameter('cancelled', 'cancelled')
            ->setParameter('on_hold', 'on_hold');

        return $queryBuilder->getQuery()->getSingleResult();
    }

    /**
     * Find high priority plans that are overdue or at risk
     */
    public function findCriticalPlans(?Tenant $tenant): array
    {
        new DateTime();

        return $this->createQueryBuilder('rtp')
            ->where('rtp.priority IN (:high_priorities)')
            ->andWhere('rtp.status NOT IN (:completed_statuses)')
            ->andWhere('rtp.tenant = :tenant OR rtp.tenant IS NULL')
            ->andWhere('rtp.targetCompletionDate < :future OR rtp.completionPercentage < 50')
            ->setParameter('high_priorities', ['high', 'critical'])
            ->setParameter('completed_statuses', ['completed', 'cancelled'])
            ->setParameter('tenant', $tenant)
            ->setParameter('future', new DateTime()->modify('+14 days'))
            ->orderBy('rtp.priority', 'DESC')
            ->addOrderBy('rtp.targetCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
