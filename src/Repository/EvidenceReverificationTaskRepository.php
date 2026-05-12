<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EvidenceReverificationTask;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * F4 Evidence-Versioning — EvidenceReverificationTask repository.
 *
 * @extends ServiceEntityRepository<EvidenceReverificationTask>
 *
 * @method EvidenceReverificationTask|null find($id, $lockMode = null, $lockVersion = null)
 * @method EvidenceReverificationTask|null findOneBy(array $criteria, array $orderBy = null)
 * @method EvidenceReverificationTask[]    findAll()
 * @method EvidenceReverificationTask[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class EvidenceReverificationTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EvidenceReverificationTask::class);
    }

    /**
     * All open (pending + in_progress) tasks for a tenant, ordered by due_date ASC (earliest first).
     *
     * @return EvidenceReverificationTask[]
     */
    public function findOpenByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.tenant = :tenant')
            ->andWhere('t.status IN (:open)')
            ->setParameter('tenant', $tenant)
            ->setParameter('open', [
                EvidenceReverificationTask::STATUS_PENDING,
                EvidenceReverificationTask::STATUS_IN_PROGRESS,
            ])
            ->orderBy('t.dueDate', 'ASC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count open tasks for a tenant — used by the Alva-Hint badge and mega-menu badge.
     */
    public function countOpenByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.tenant = :tenant')
            ->andWhere('t.status IN (:open)')
            ->setParameter('tenant', $tenant)
            ->setParameter('open', [
                EvidenceReverificationTask::STATUS_PENDING,
                EvidenceReverificationTask::STATUS_IN_PROGRESS,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * All tasks assigned to a specific user (for "my queue" view).
     *
     * @return EvidenceReverificationTask[]
     */
    public function findOpenByAssignee(User $user, Tenant $tenant): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.tenant = :tenant')
            ->andWhere('t.assignedTo = :user')
            ->andWhere('t.status IN (:open)')
            ->setParameter('tenant', $tenant)
            ->setParameter('user', $user)
            ->setParameter('open', [
                EvidenceReverificationTask::STATUS_PENDING,
                EvidenceReverificationTask::STATUS_IN_PROGRESS,
            ])
            ->orderBy('t.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All tasks for a tenant (including completed), most recent first.
     * Used for full audit history view.
     *
     * @return EvidenceReverificationTask[]
     */
    public function findAllByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All overdue open tasks for a tenant (due_date < today, status open).
     *
     * @return EvidenceReverificationTask[]
     */
    public function findOverdueByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.tenant = :tenant')
            ->andWhere('t.status IN (:open)')
            ->andWhere('t.dueDate < :today')
            ->andWhere('t.dueDate IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('open', [
                EvidenceReverificationTask::STATUS_PENDING,
                EvidenceReverificationTask::STATUS_IN_PROGRESS,
            ])
            ->setParameter('today', new \DateTimeImmutable('today'))
            ->orderBy('t.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
