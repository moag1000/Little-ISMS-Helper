<?php

namespace App\Repository;

use DateTimeImmutable;
use App\Entity\User;
use App\Entity\UserSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserSession>
 */
class UserSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserSession::class);
    }

    /**
     * Find active sessions for a specific user
     *
     * @return UserSession[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->andWhere('s.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->orderBy('s.lastActivityAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all currently active sessions
     *
     * @return UserSession[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('s.lastActivityAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find session by Symfony session ID
     */
    public function findBySessionId(string $sessionId): ?UserSession
    {
        return $this->findOneBy(['sessionId' => $sessionId]);
    }

    /**
     * Count active sessions for a user
     */
    public function countActiveByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.user = :user')
            ->andWhere('s.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get active sessions with optional filters
     *
     * @return UserSession[]
     */
    public function getActiveSessions(?int $limit = null, ?string $userEmail = null): array
    {
        $queryBuilder = $this->createQueryBuilder('s')
            ->leftJoin('s.user', 'u')
            ->addSelect('u')
            ->where('s.isActive = :active')
            ->setParameter('active', true);

        if ($userEmail) {
            $queryBuilder->andWhere('u.email LIKE :email')
                ->setParameter('email', '%' . $userEmail . '%');
        }

        $queryBuilder->orderBy('s.lastActivityAt', 'DESC');

        if ($limit) {
            $queryBuilder->setMaxResults($limit);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Get session statistics
     */
    public function getStatistics(): array
    {
        $queryBuilder = $this->createQueryBuilder('s');

        $totalActive = (int) $queryBuilder->select('COUNT(s.id)')
            ->where('s.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();

        // Get sessions from last 24 hours
        $yesterday = new DateTimeImmutable('-24 hours');
        $totalLast24h = (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.createdAt >= :since')
            ->setParameter('since', $yesterday)
            ->getQuery()
            ->getSingleScalarResult();

        // Get unique users with active sessions
        $uniqueUsers = (int) $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT s.user)')
            ->where('s.isActive = :active')
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_active' => $totalActive,
            'total_last_24h' => $totalLast24h,
            'unique_users' => $uniqueUsers,
        ];
    }

    /**
     * Clean up expired sessions
     * Returns number of sessions cleaned up
     */
    public function cleanupExpiredSessions(int $maxLifetime = 3600): int
    {
        $expiryTime = new DateTimeImmutable("-{$maxLifetime} seconds");

        return $this->createQueryBuilder('s')
            ->update()
            ->set('s.isActive', ':inactive')
            ->set('s.endedAt', ':endedAt')
            ->set('s.endReason', ':reason')
            ->where('s.isActive = :active')
            ->andWhere('s.lastActivityAt < :expiryTime')
            ->setParameter('inactive', false)
            ->setParameter('endedAt', new DateTimeImmutable())
            ->setParameter('reason', 'timeout')
            ->setParameter('active', true)
            ->setParameter('expiryTime', $expiryTime)
            ->getQuery()
            ->execute();
    }

    /**
     * Terminate all active sessions for a user
     */
    public function terminateUserSessions(User $user, string $reason = 'forced', ?string $terminatedBy = null): int
    {
        return $this->createQueryBuilder('s')
            ->update()
            ->set('s.isActive', ':inactive')
            ->set('s.endedAt', ':endedAt')
            ->set('s.endReason', ':reason')
            ->set('s.terminatedBy', ':terminatedBy')
            ->where('s.user = :user')
            ->andWhere('s.isActive = :active')
            ->setParameter('inactive', false)
            ->setParameter('endedAt', new DateTimeImmutable())
            ->setParameter('reason', $reason)
            ->setParameter('terminatedBy', $terminatedBy)
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->getQuery()
            ->execute();
    }

    /**
     * Terminate a specific session by session ID
     */
    public function terminateSession(string $sessionId, string $reason = 'forced', ?string $terminatedBy = null): int
    {
        return $this->createQueryBuilder('s')
            ->update()
            ->set('s.isActive', ':inactive')
            ->set('s.endedAt', ':endedAt')
            ->set('s.endReason', ':reason')
            ->set('s.terminatedBy', ':terminatedBy')
            ->where('s.sessionId = :sessionId')
            ->andWhere('s.isActive = :active')
            ->setParameter('inactive', false)
            ->setParameter('endedAt', new DateTimeImmutable())
            ->setParameter('reason', $reason)
            ->setParameter('terminatedBy', $terminatedBy)
            ->setParameter('sessionId', $sessionId)
            ->setParameter('active', true)
            ->getQuery()
            ->execute();
    }

    /**
     * Get recent session history for a user
     *
     * @return UserSession[]
     */
    public function getUserSessionHistory(User $user, int $limit = 50): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.user = :user')
            ->setParameter('user', $user)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
