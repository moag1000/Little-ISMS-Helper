<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ObligationNotice;
use App\Entity\User;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ObligationNotice>
 */
class ObligationNoticeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ObligationNotice::class);
    }

    /**
     * Keys out of $keys the user has already been notified about.
     *
     * @param list<string> $keys
     * @return array<string, true>
     */
    public function sentKeys(User $user, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('n')
            ->select('n.noticeKey')
            ->andWhere('n.user = :user')
            ->andWhere('n.noticeKey IN (:keys)')
            ->setParameter('user', $user)
            ->setParameter('keys', $keys)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('strval', $rows), true);
    }

    /**
     * The user's latest notices, newest first.
     *
     * @return list<ObligationNotice>
     */
    public function latestFor(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Drops notices older than $before (the digest history is not an archive). */
    public function purgeOlderThan(DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('n')
            ->delete()
            ->andWhere('n.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
