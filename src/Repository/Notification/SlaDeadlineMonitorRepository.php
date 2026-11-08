<?php

declare(strict_types=1);

namespace App\Repository\Notification;

use App\Entity\Notification\SlaDeadlineMonitor;
use App\Entity\Tenant;
use App\Enum\SlaDeadlineStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * SLA Deadline Monitor Repository
 *
 * @extends ServiceEntityRepository<SlaDeadlineMonitor>
 *
 * @method SlaDeadlineMonitor|null find($id, $lockMode = null, $lockVersion = null)
 * @method SlaDeadlineMonitor|null findOneBy(array $criteria, array $orderBy = null)
 * @method SlaDeadlineMonitor[]    findAll()
 * @method SlaDeadlineMonitor[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SlaDeadlineMonitorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SlaDeadlineMonitor::class);
    }

    /**
     * Find active SLA monitors with checkpoints that should fire now.
     *
     * A checkpoint fires when:
     *   1. The monitor is active.
     *   2. The deadline is within $hoursAhead hours (i.e. deadlineAt <= now + $hoursAhead).
     *   3. The deadline has not yet passed (deadlineAt > now).
     *   4. The checkpoint hours-before value is greater than lastNotifiedAtHours
     *      (or lastNotifiedAtHours is null), meaning the specific checkpoint
     *      has not been emitted yet.
     *
     * NOTE: The caller (SlaDeadlineWatcher) is responsible for matching
     * individual checkpoint values from notifyAtCheckpoints against the
     * current hours-remaining value to determine which checkpoint fires.
     *
     * @return SlaDeadlineMonitor[]
     */
    public function findApproachingDeadlines(Tenant $tenant, int $hoursAhead): array
    {
        $now      = new DateTimeImmutable();
        $cutoff   = $now->modify('+' . $hoursAhead . ' hours');

        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.status = :status')
            ->andWhere('s.deadlineAt > :now')
            ->andWhere('s.deadlineAt <= :cutoff')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', SlaDeadlineStatus::Active)
            ->setParameter('now', $now)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('s.deadlineAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active monitors whose deadline has already passed.
     * These should be transitioned to status=missed.
     *
     * @return SlaDeadlineMonitor[]
     */
    public function findMissedDeadlines(Tenant $tenant): array
    {
        $now = new DateTimeImmutable();

        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.status = :status')
            ->andWhere('s.deadlineAt < :now')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', SlaDeadlineStatus::Active)
            ->setParameter('now', $now)
            ->orderBy('s.deadlineAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * All SLA monitors of one record (one per deadline type).
     *
     * @return list<SlaDeadlineMonitor>
     */
    public function findAllForEntity(string $entityType, int $entityId, Tenant $tenant): array
    {
        /** @var list<SlaDeadlineMonitor> $rows */
        $rows = $this->createQueryBuilder('s')
            ->where('s.entityType = :entityType')
            ->andWhere('s.entityId = :entityId')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->setParameter('tenant', $tenant)
            ->orderBy('s.deadlineAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Statutory reports of incidents and data breaches not yet made (running
     * or already missed) — the reporting duties in My Day and the reminders.
     *
     * @return list<SlaDeadlineMonitor>
     */
    public function findOpenReporting(Tenant $tenant): array
    {
        /** @var list<SlaDeadlineMonitor> $rows */
        $rows = $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.entityType IN (:types)')
            ->andWhere('s.status IN (:open)')
            ->andWhere('s.satisfiedAt IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('types', \App\Service\Deadline\Source\ReportingDutySource::ENTITY_TYPES)
            ->setParameter('open', [SlaDeadlineStatus::Active->value, SlaDeadlineStatus::Missed->value])
            ->orderBy('s.deadlineAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Count missed SLA monitors for a tenant (used by the Alva-Hint rule).
     */
    public function countMissedForTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.tenant = :tenant')
            ->andWhere('s.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', SlaDeadlineStatus::Missed)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
