<?php

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WizardSession>
 *
 * @method WizardSession|null find($id, $lockMode = null, $lockVersion = null)
 * @method WizardSession|null findOneBy(array $criteria, array $orderBy = null)
 * @method WizardSession[]    findAll()
 * @method WizardSession[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WizardSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WizardSession::class);
    }

    /**
     * Find active session for user and wizard type
     */
    public function findActiveSession(User $user, string $wizardType): ?WizardSession
    {
        return $this->createQueryBuilder('ws')
            ->andWhere('ws.user = :user')
            ->andWhere('ws.wizardType = :wizardType')
            ->andWhere('ws.status = :status')
            ->setParameter('user', $user)
            ->setParameter('wizardType', $wizardType)
            ->setParameter('status', WizardSession::STATUS_IN_PROGRESS)
            ->orderBy('ws.lastActivityAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all sessions for a user
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('ws')
            ->andWhere('ws.user = :user')
            ->setParameter('user', $user)
            ->orderBy('ws.lastActivityAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all sessions for a tenant
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('ws')
            ->andWhere('ws.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('ws.lastActivityAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find completed sessions for a user
     */
    public function findCompletedByUser(User $user): array
    {
        return $this->createQueryBuilder('ws')
            ->andWhere('ws.user = :user')
            ->andWhere('ws.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', WizardSession::STATUS_COMPLETED)
            ->orderBy('ws.completedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find most recent completed session for wizard type
     */
    public function findLatestCompletedForTenant(Tenant $tenant, string $wizardType): ?WizardSession
    {
        return $this->createQueryBuilder('ws')
            ->andWhere('ws.tenant = :tenant')
            ->andWhere('ws.wizardType = :wizardType')
            ->andWhere('ws.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('wizardType', $wizardType)
            ->setParameter('status', WizardSession::STATUS_COMPLETED)
            ->orderBy('ws.completedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find abandoned sessions older than given days
     */
    public function findAbandonedSessions(int $daysOld = 30): array
    {
        $cutoff = new \DateTimeImmutable("-{$daysOld} days");

        return $this->createQueryBuilder('ws')
            ->andWhere('ws.status = :status')
            ->andWhere('ws.lastActivityAt < :cutoff')
            ->setParameter('status', WizardSession::STATUS_IN_PROGRESS)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics for a tenant
     */
    public function getStatisticsForTenant(Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('ws')
            ->select('ws.wizardType, ws.status, COUNT(ws.id) as count, AVG(ws.overallScore) as avgScore')
            ->andWhere('ws.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('ws.wizardType, ws.status');

        $results = $qb->getQuery()->getResult();

        $stats = [];
        foreach ($results as $row) {
            $wizardType = $row['wizardType'];
            if (!isset($stats[$wizardType])) {
                $stats[$wizardType] = [
                    'total' => 0,
                    'completed' => 0,
                    'in_progress' => 0,
                    'abandoned' => 0,
                    'avg_score' => 0,
                ];
            }
            $stats[$wizardType]['total'] += $row['count'];
            $stats[$wizardType][$row['status']] = $row['count'];
            if ($row['status'] === WizardSession::STATUS_COMPLETED && $row['avgScore'] !== null) {
                $stats[$wizardType]['avg_score'] = round((float) $row['avgScore']);
            }
        }

        return $stats;
    }

    /**
     * Clean up old abandoned sessions
     */
    public function cleanupAbandonedSessions(int $daysOld = 90): int
    {
        $cutoff = new \DateTimeImmutable("-{$daysOld} days");

        return $this->createQueryBuilder('ws')
            ->delete()
            ->andWhere('ws.status = :status')
            ->andWhere('ws.lastActivityAt < :cutoff')
            ->setParameter('status', WizardSession::STATUS_ABANDONED)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
