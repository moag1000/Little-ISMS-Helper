<?php

namespace App\Repository;

use App\Entity\RiskTreatmentPlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Risk Treatment Plan Repository
 *
 * Repository for querying Risk Treatment Plan entities for ISO 27005 compliance.
 *
 * Features:
 * - Find plans by status
 * - Track overdue plans
 * - Monitor progress
 * - Approval tracking
 *
 * @extends ServiceEntityRepository<RiskTreatmentPlan>
 *
 * @method RiskTreatmentPlan|null find($id, $lockMode = null, $lockVersion = null)
 * @method RiskTreatmentPlan|null findOneBy(array $criteria, array $orderBy = null)
 * @method RiskTreatmentPlan[]    findAll()
 * @method RiskTreatmentPlan[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RiskTreatmentPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskTreatmentPlan::class);
    }

    /**
     * Find plans by status
     *
     * @return RiskTreatmentPlan[]
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('rtp')
            ->where('rtp.status = :status')
            ->setParameter('status', $status)
            ->orderBy('rtp.priority', 'DESC')
            ->addOrderBy('rtp.plannedCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active plans (in_progress or approved)
     *
     * @return RiskTreatmentPlan[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('rtp')
            ->where('rtp.status IN (:statuses)')
            ->setParameter('statuses', ['in_progress', 'approved'])
            ->orderBy('rtp.priority', 'DESC')
            ->addOrderBy('rtp.plannedCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find overdue plans
     *
     * @return RiskTreatmentPlan[]
     */
    public function findOverdue(): array
    {
        $now = new \DateTimeImmutable();

        return $this->createQueryBuilder('rtp')
            ->where('rtp.plannedCompletionDate < :now')
            ->andWhere('rtp.status NOT IN (:completedStatuses)')
            ->setParameter('now', $now)
            ->setParameter('completedStatuses', ['completed', 'cancelled'])
            ->orderBy('rtp.plannedCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find plans by priority
     *
     * @return RiskTreatmentPlan[]
     */
    public function findByPriority(string $priority): array
    {
        return $this->createQueryBuilder('rtp')
            ->where('rtp.priority = :priority')
            ->andWhere('rtp.status NOT IN (:completedStatuses)')
            ->setParameter('priority', $priority)
            ->setParameter('completedStatuses', ['completed', 'cancelled'])
            ->orderBy('rtp.plannedCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find unapproved plans
     *
     * @return RiskTreatmentPlan[]
     */
    public function findUnapproved(): array
    {
        return $this->createQueryBuilder('rtp')
            ->where('rtp.approvedBy IS NULL OR rtp.approvedAt IS NULL')
            ->andWhere('rtp.status = :status')
            ->setParameter('status', 'planned')
            ->orderBy('rtp.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find plans due soon (within next N days)
     *
     * @return RiskTreatmentPlan[]
     */
    public function findDueSoon(int $days = 7): array
    {
        $now = new \DateTimeImmutable();
        $future = $now->modify("+{$days} days");

        return $this->createQueryBuilder('rtp')
            ->where('rtp.plannedCompletionDate BETWEEN :now AND :future')
            ->andWhere('rtp.status NOT IN (:completedStatuses)')
            ->setParameter('now', $now)
            ->setParameter('future', $future)
            ->setParameter('completedStatuses', ['completed', 'cancelled'])
            ->orderBy('rtp.plannedCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find plans by assigned user
     *
     * @return RiskTreatmentPlan[]
     */
    public function findByAssignedUser(int $userId): array
    {
        return $this->createQueryBuilder('rtp')
            ->where('rtp.assignedTo = :userId')
            ->andWhere('rtp.status NOT IN (:completedStatuses)')
            ->setParameter('userId', $userId)
            ->setParameter('completedStatuses', ['completed', 'cancelled'])
            ->orderBy('rtp.priority', 'DESC')
            ->addOrderBy('rtp.plannedCompletionDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find plans with low progress (below threshold)
     *
     * @return RiskTreatmentPlan[]
     */
    public function findWithLowProgress(int $threshold = 50): array
    {
        return $this->createQueryBuilder('rtp')
            ->where('rtp.progressPercentage < :threshold')
            ->andWhere('rtp.status = :status')
            ->setParameter('threshold', $threshold)
            ->setParameter('status', 'in_progress')
            ->orderBy('rtp.progressPercentage', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count plans by status
     */
    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('rtp')
            ->select('COUNT(rtp.id)')
            ->where('rtp.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get total estimated cost of all active plans
     */
    public function getTotalEstimatedCost(): float
    {
        $result = $this->createQueryBuilder('rtp')
            ->select('SUM(rtp.estimatedCost)')
            ->where('rtp.status NOT IN (:completedStatuses)')
            ->setParameter('completedStatuses', ['completed', 'cancelled'])
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Get total actual cost of completed plans
     */
    public function getTotalActualCost(): float
    {
        $result = $this->createQueryBuilder('rtp')
            ->select('SUM(rtp.actualCost)')
            ->where('rtp.status = :status')
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }
}
