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

    /**
     * Deletes all dismissal records for a given entity so that hints
     * re-surface after a workflow transition changes the entity state.
     *
     * Called by AlvaHintInvalidator on every workflow.completed event.
     * Returns the number of rows deleted (0 when no dismissals existed).
     */
    public function invalidateForEntity(string $entityClass, int $entityId): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()
            ->where('d.entityType = :class')
            ->andWhere('d.entityId = :id')
            ->setParameter('class', $entityClass)
            ->setParameter('id', $entityId)
            ->getQuery()
            ->execute();
    }

    /**
     * Aggregate dismissal counts per hint key for telemetry / "which
     * hints get nuked the most" dashboards. Tenant-scoped to keep
     * cross-tenant leakage impossible.
     *
     * @return array<int, array{hintKey: string, total: int, snoozed: int, distinctUsers: int}>
     */
    public function statisticsByTenant(?Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('d')
            ->select('d.hintKey AS hintKey')
            ->addSelect('COUNT(d.id) AS total')
            ->addSelect('SUM(CASE WHEN d.dismissedUntil IS NULL THEN 0 ELSE 1 END) AS snoozed')
            ->addSelect('COUNT(DISTINCT d.user) AS distinctUsers')
            ->groupBy('d.hintKey')
            ->orderBy('total', 'DESC');

        if ($tenant instanceof Tenant) {
            $qb->where('d.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        $rows = $qb->getQuery()->getArrayResult();

        return array_map(
            static fn(array $row): array => [
                'hintKey' => (string) $row['hintKey'],
                'total' => (int) $row['total'],
                'snoozed' => (int) $row['snoozed'],
                'distinctUsers' => (int) $row['distinctUsers'],
            ],
            $rows,
        );
    }
}
