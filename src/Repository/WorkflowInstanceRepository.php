<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeInterface;
use App\Entity\WorkflowInstance;
use App\Enum\WorkflowInstanceStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Workflow Instance Repository
 *
 * Repository for querying WorkflowInstance entities with status and SLA tracking.
 *
 * @extends ServiceEntityRepository<WorkflowInstance>
 *
 * @method WorkflowInstance|null find($id, $lockMode = null, $lockVersion = null)
 * @method WorkflowInstance|null findOneBy(array $criteria, array $orderBy = null)
 * @method WorkflowInstance[]    findAll()
 * @method WorkflowInstance[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WorkflowInstanceRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ?RoleHierarchyInterface $roleHierarchy = null,
    ) {
        parent::__construct($registry, WorkflowInstance::class);
    }

    /**
     * Find active workflow instances (pending or in progress).
     *
     * @return WorkflowInstance[] Array of active instances sorted by start date (newest first)
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('wi')
            ->where('wi.status IN (:statuses)')
            ->setParameter('statuses', [WorkflowInstanceStatus::Pending->value, WorkflowInstanceStatus::InProgress->value])
            ->orderBy('wi.startedAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active workflow instances scoped to a specific tenant.
     *
     * Prevents Cross-Tenant-Leakage in ActivityFeed.
     *
     * @return WorkflowInstance[] Array of active instances sorted by start date (newest first)
     */
    public function findActiveForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('wi')
            ->andWhere('wi.tenant = :tenant')
            ->andWhere('wi.status IN (:statuses)')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', [WorkflowInstanceStatus::Pending->value, WorkflowInstanceStatus::InProgress->value])
            ->orderBy('wi.startedAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find overdue workflow instances (past due date but still active).
     *
     * @return WorkflowInstance[] Array of overdue instances sorted by due date (oldest first)
     */
    public function findOverdue(): array
    {
        return $this->createQueryBuilder('wi')
            ->where('wi.status IN (:statuses)')
            ->andWhere('wi.dueDate < :now')
            ->setParameter('statuses', [WorkflowInstanceStatus::Pending->value, WorkflowInstanceStatus::InProgress->value])
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('wi.dueDate', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find overdue workflow instances scoped to a specific tenant.
     *
     * Prevents Cross-Tenant-Leakage in MyDayAggregator.
     *
     * @return WorkflowInstance[] Array of overdue instances sorted by due date (oldest first)
     */
    public function findOverdueForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('wi')
            ->andWhere('wi.tenant = :tenant')
            ->andWhere('wi.status IN (:statuses)')
            ->andWhere('wi.dueDate < :now')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', [WorkflowInstanceStatus::Pending->value, WorkflowInstanceStatus::InProgress->value])
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('wi.dueDate', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find workflow instances for a specific entity.
     *
     * @param string $entityType Entity class name (e.g., 'Risk', 'Control', 'Asset')
     * @param int $entityId Entity identifier
     * @return WorkflowInstance[] Array of instances sorted by start date (newest first)
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->createQueryBuilder('wi')
            ->where('wi.entityType = :entityType')
            ->andWhere('wi.entityId = :entityId')
            ->setParameter('entityType', $entityType)
            ->setParameter('entityId', $entityId)
            ->orderBy('wi.startedAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Running (pending / in progress) instances for many records of one type in
     * one query, newest first — the list-row workflow pill reads this per page.
     *
     * @param list<string> $entityTypes stored type names (short name, some older rows the FQCN)
     * @param list<int>    $entityIds
     * @return WorkflowInstance[]
     */
    public function findActiveForEntities(array $entityTypes, array $entityIds): array
    {
        if ($entityIds === [] || $entityTypes === []) {
            return [];
        }

        return $this->createQueryBuilder('wi')
            ->where('wi.entityType IN (:entityTypes)')
            ->andWhere('wi.entityId IN (:ids)')
            ->andWhere('wi.status IN (:statuses)')
            ->setParameter('entityTypes', $entityTypes)
            ->setParameter('ids', $entityIds)
            ->setParameter('statuses', [WorkflowInstanceStatus::Pending->value, WorkflowInstanceStatus::InProgress->value])
            ->orderBy('wi.startedAt', \SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get workflow statistics for dashboard reporting.
     *
     * @return array<string, int> Statistics array with counts by status
     */
    public function getStatistics(): array
    {
        $queryBuilder = $this->createQueryBuilder('wi');

        return [
            'total' => $queryBuilder->select('COUNT(wi.id)')->getQuery()->getSingleScalarResult(),
            'pending' => (clone $queryBuilder)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', WorkflowInstanceStatus::Pending->value)->getQuery()->getSingleScalarResult(),
            'in_progress' => (clone $queryBuilder)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', WorkflowInstanceStatus::InProgress->value)->getQuery()->getSingleScalarResult(),
            'approved' => (clone $queryBuilder)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', WorkflowInstanceStatus::Approved->value)->getQuery()->getSingleScalarResult(),
            'rejected' => (clone $queryBuilder)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', WorkflowInstanceStatus::Rejected->value)->getQuery()->getSingleScalarResult(),
            'cancelled' => (clone $queryBuilder)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', WorkflowInstanceStatus::Cancelled->value)->getQuery()->getSingleScalarResult(),
        ];
    }

    /**
     * Open workflow instances whose current step $user may approve.
     *
     * The one definition of "waiting for my decision" (My Day, sidebar badge,
     * dashboards, workflow pages). It applies the rule of
     * {@see \App\Service\WorkflowService::canUserApprove()}: ROLE_ADMIN, an
     * explicit approver_users entry, or the step's approver_role, both roles
     * through the role hierarchy. The former SQL match (`approver_users LIKE
     * '%"<id>"%'`, `approver_role = <own role>`) never matched the integer ids
     * the step stores and ignored inherited roles, so assigned approvers did
     * not see their approvals.
     *
     * @param Tenant|null $tenant tenant scope; null leaves scoping to the tenant filter
     * @return WorkflowInstance[] sorted by due date (earliest first)
     */
    public function findPendingForUser(User $user, ?Tenant $tenant = null): array
    {
        $queryBuilder = $this->createQueryBuilder('wi')
            ->innerJoin('wi.currentStep', 'step')->addSelect('step')
            ->where('wi.status IN (:statuses)')
            ->setParameter('statuses', [WorkflowInstanceStatus::Pending->value, WorkflowInstanceStatus::InProgress->value])
            ->orderBy('wi.dueDate', \SortDirection::Ascending);
        if ($tenant instanceof Tenant) {
            $queryBuilder->andWhere('wi.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        $roles = $this->roleHierarchy?->getReachableRoleNames($user->getRoles()) ?? $user->getRoles();
        $isAdmin = in_array('ROLE_ADMIN', $roles, true);
        $userId = $user->getId();

        return array_values(array_filter(
            $queryBuilder->getQuery()->getResult(),
            static function (WorkflowInstance $instance) use ($roles, $isAdmin, $userId): bool {
                $step = $instance->getCurrentStep();
                if ($step === null) {
                    return false;
                }
                if ($isAdmin || in_array($userId, $step->getApproverUsers() ?? [], false)) {
                    return true;
                }
                $role = $step->getApproverRole();

                return $role !== null && $role !== '' && in_array($role, $roles, true);
            },
        ));
    }

    /**
     * Find workflow instances with upcoming deadlines.
     *
     * @param DateTimeInterface $within Time window for upcoming deadlines
     * @return WorkflowInstance[] Array of instances with upcoming deadlines
     */
    public function findUpcomingDeadlines(DateTimeInterface $within): array
    {
        return $this->createQueryBuilder('wi')
            ->where('wi.status IN (:statuses)')
            ->andWhere('wi.dueDate IS NOT NULL')
            ->andWhere('wi.dueDate BETWEEN :now AND :within')
            ->setParameter('statuses', [WorkflowInstanceStatus::Pending->value, WorkflowInstanceStatus::InProgress->value])
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('within', $within)
            ->orderBy('wi.dueDate', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }
}
