<?php

namespace App\Repository;

use DateTimeImmutable;
use App\Entity\User;
use DateTimeInterface;
use App\Entity\WorkflowInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
    public function __construct(ManagerRegistry $registry)
    {
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
            ->setParameter('statuses', ['pending', 'in_progress'])
            ->orderBy('wi.startedAt', 'DESC')
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
            ->setParameter('statuses', ['pending', 'in_progress'])
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('wi.dueDate', 'ASC')
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
            ->orderBy('wi.startedAt', 'DESC')
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
            'pending' => (clone $queryBuilder)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', 'pending')->getQuery()->getSingleScalarResult(),
            'in_progress' => (clone $queryBuilder)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', 'in_progress')->getQuery()->getSingleScalarResult(),
            'approved' => (clone $queryBuilder)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', 'approved')->getQuery()->getSingleScalarResult(),
            'rejected' => (clone $queryBuilder)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', 'rejected')->getQuery()->getSingleScalarResult(),
            'cancelled' => (clone $queryBuilder)->select('COUNT(wi.id)')->where('wi.status = :status')->setParameter('status', 'cancelled')->getQuery()->getSingleScalarResult(),
        ];
    }

    /**
     * Find pending workflow instances for a specific user.
     *
     * @param User $user User to filter by
     * @return WorkflowInstance[] Array of pending instances sorted by due date (earliest first)
     */
    public function findPendingForUser(User $user): array
    {
        // Get user roles for role-based filtering
        $userRoles = $user->getRoles();

        // Query for workflows where user is approver by user ID or role
        $queryBuilder = $this->createQueryBuilder('wi')
            ->leftJoin('wi.workflowStep', 'step')
            ->where('wi.status IN (:statuses)')
            ->setParameter('statuses', ['pending', 'in_progress'])
            ->orderBy('wi.dueDate', 'ASC');

        // Build OR conditions for user matching
        $orx = $queryBuilder->expr()->orX();

        // Match by user ID in approverUsers array
        $orx->add($queryBuilder->expr()->like('step.approverUsers', ':userId'));
        $queryBuilder->setParameter('userId', '%"' . $user->getId() . '"%');

        // Match by role in approverRole field
        foreach ($userRoles as $userRole) {
            $paramName = 'role_' . md5($userRole);
            $orx->add($queryBuilder->expr()->eq('step.approverRole', ':' . $paramName));
            $queryBuilder->setParameter($paramName, $userRole);
        }

        $queryBuilder->andWhere($orx);

        return $queryBuilder->getQuery()->getResult();
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
            ->setParameter('statuses', ['pending', 'in_progress'])
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('within', $within)
            ->orderBy('wi.dueDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
