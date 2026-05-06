<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AlvaHintDismissal;
use App\Entity\Tenant;
use App\Entity\User;
use DateTimeImmutable;
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
     * Tokens of dismissals that are still active (no expiry, or expiry in
     * the future). Token format `hintKey|entityType|entityId`. Tenant
     * scope is applied so the same user dismissing in tenant A does not
     * affect tenant B.
     *
     * @return array<int, string>
     */
    public function findActiveDismissedTokensForUser(User $user, ?Tenant $tenant, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();

        $qb = $this->createQueryBuilder('d')
            ->select('d.hintKey', 'd.entityType', 'd.entityId')
            ->where('d.user = :user')
            ->andWhere('d.dismissedUntil IS NULL OR d.dismissedUntil > :now')
            ->setParameter('user', $user)
            ->setParameter('now', $now);

        if ($tenant instanceof Tenant) {
            $qb->andWhere('d.tenant = :tenant OR d.tenant IS NULL')
                ->setParameter('tenant', $tenant);
        } else {
            $qb->andWhere('d.tenant IS NULL');
        }

        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn(array $row): string => sprintf('%s|%s|%d', $row['hintKey'], $row['entityType'], $row['entityId']),
            $rows,
        );
    }

    public function findOneFor(User $user, ?Tenant $tenant, string $hintKey, string $entityType, int $entityId): ?AlvaHintDismissal
    {
        return $this->findOneBy([
            'user' => $user,
            'tenant' => $tenant,
            'hintKey' => $hintKey,
            'entityType' => $entityType,
            'entityId' => $entityId,
        ]);
    }
}
