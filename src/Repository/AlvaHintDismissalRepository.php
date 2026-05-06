<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AlvaHintDismissal;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlvaHintDismissal>
 */
class AlvaHintDismissalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlvaHintDismissal::class);
    }

    /**
     * Return the set of (hintKey|entityType|entityId) tokens dismissed by
     * the given user. Used to pre-filter rule evaluation.
     *
     * @return array<int, string>  list of "hintKey|entityType|entityId" tokens
     */
    public function findDismissedTokensForUser(User $user): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.hintKey', 'd.entityType', 'd.entityId')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn(array $row): string => sprintf('%s|%s|%d', $row['hintKey'], $row['entityType'], $row['entityId']),
            $rows,
        );
    }

    public function findOneFor(User $user, string $hintKey, string $entityType, int $entityId): ?AlvaHintDismissal
    {
        return $this->findOneBy([
            'user' => $user,
            'hintKey' => $hintKey,
            'entityType' => $entityType,
            'entityId' => $entityId,
        ]);
    }
}
