<?php

namespace App\Repository;

use App\Entity\Patch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Patch Repository
 *
 * Repository for querying Patch entities for NIS2 compliance.
 *
 * Features:
 * - Find patches by status and priority
 * - Find overdue patches
 * - Find patches requiring deployment
 * - Statistics and analytics
 *
 * @extends ServiceEntityRepository<Patch>
 *
 * @method Patch|null find($id, $lockMode = null, $lockVersion = null)
 * @method Patch|null findOneBy(array $criteria, array $orderBy = null)
 * @method Patch[]    findAll()
 * @method Patch[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Patch::class);
    }

    /**
     * Find all pending patches
     *
     * @return Patch[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status IN (:statuses)')
            ->setParameter('statuses', ['pending', 'testing', 'approved'])
            ->orderBy('p.priority', 'DESC')
            ->addOrderBy('p.releaseDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find overdue patches
     *
     * @return Patch[]
     */
    public function findOverdue(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.deploymentDeadline IS NOT NULL')
            ->andWhere('p.deploymentDeadline < :now')
            ->andWhere('p.status NOT IN (:statuses)')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('statuses', ['deployed', 'not_applicable'])
            ->orderBy('p.deploymentDeadline', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find patches by priority
     *
     * @return Patch[]
     */
    public function findByPriority(string $priority): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.priority = :priority')
            ->andWhere('p.status != :deployed')
            ->setParameter('priority', $priority)
            ->setParameter('deployed', 'deployed')
            ->orderBy('p.releaseDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find critical patches due soon (within X days)
     *
     * @return Patch[]
     */
    public function findCriticalDueSoon(int $days = 7): array
    {
        $deadline = (new \DateTimeImmutable())->modify("+{$days} days");

        return $this->createQueryBuilder('p')
            ->where('p.priority IN (:priorities)')
            ->andWhere('p.deploymentDeadline IS NOT NULL')
            ->andWhere('p.deploymentDeadline <= :deadline')
            ->andWhere('p.status NOT IN (:statuses)')
            ->setParameter('priorities', ['critical', 'high'])
            ->setParameter('deadline', $deadline)
            ->setParameter('statuses', ['deployed', 'not_applicable'])
            ->orderBy('p.deploymentDeadline', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count patches by status
     */
    public function countByStatus(): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('p.status, COUNT(p.id) as count')
            ->groupBy('p.status')
            ->getQuery()
            ->getResult();

        $counts = [
            'pending' => 0,
            'testing' => 0,
            'approved' => 0,
            'deployed' => 0,
            'failed' => 0,
            'rolled_back' => 0,
            'not_applicable' => 0,
        ];

        foreach ($result as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Get deployment statistics (alias for countByStatus)
     */
    public function getDeploymentStatistics(): array
    {
        return $this->countByStatus();
    }

    /**
     * Find recently deployed patches
     *
     * @return Patch[]
     */
    public function findRecentlyDeployed(int $days = 30): array
    {
        $since = (new \DateTimeImmutable())->modify("-{$days} days");

        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.deployedDate >= :since')
            ->setParameter('status', 'deployed')
            ->setParameter('since', $since)
            ->orderBy('p.deployedDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
