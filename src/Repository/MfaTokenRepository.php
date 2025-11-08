<?php

namespace App\Repository;

use App\Entity\MfaToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * MfaToken Repository
 *
 * Repository for querying MFA Token entities for NIS2 compliance.
 *
 * Features:
 * - Find active MFA tokens for users
 * - Token type filtering
 * - Expiration checking
 * - Usage statistics
 *
 * @extends ServiceEntityRepository<MfaToken>
 *
 * @method MfaToken|null find($id, $lockMode = null, $lockVersion = null)
 * @method MfaToken|null findOneBy(array $criteria, array $orderBy = null)
 * @method MfaToken[]    findAll()
 * @method MfaToken[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MfaTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MfaToken::class);
    }

    /**
     * Find all active MFA tokens for a user
     *
     * @return MfaToken[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->andWhere('m.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->orderBy('m.isPrimary', 'DESC')
            ->addOrderBy('m.enrolledAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find primary MFA token for a user
     */
    public function findPrimaryByUser(User $user): ?MfaToken
    {
        return $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->andWhere('m.isActive = :active')
            ->andWhere('m.isPrimary = :primary')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->setParameter('primary', true)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find tokens by type
     *
     * @return MfaToken[]
     */
    public function findByType(string $tokenType): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.tokenType = :type')
            ->andWhere('m.isActive = :active')
            ->setParameter('type', $tokenType)
            ->setParameter('active', true)
            ->orderBy('m.enrolledAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count active MFA tokens for a user
     */
    public function countActiveByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.user = :user')
            ->andWhere('m.isActive = :active')
            ->setParameter('user', $user)
            ->setParameter('active', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find expired tokens
     *
     * @return MfaToken[]
     */
    public function findExpired(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.expiresAt IS NOT NULL')
            ->andWhere('m.expiresAt < :now')
            ->andWhere('m.isActive = :active')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();
    }
}
