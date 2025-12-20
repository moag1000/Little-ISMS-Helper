<?php

namespace App\Repository;

use App\Entity\PushSubscription;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PushSubscription>
 */
class PushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PushSubscription::class);
    }

    /**
     * Find all active subscriptions for a user
     *
     * @return PushSubscription[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('ps')
            ->where('ps.user = :user')
            ->andWhere('ps.isActive = true')
            ->setParameter('user', $user)
            ->orderBy('ps.lastUsedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active subscriptions for a tenant
     *
     * @return PushSubscription[]
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('ps')
            ->where('ps.tenant = :tenant')
            ->andWhere('ps.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('ps.lastUsedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find subscription by endpoint hash
     */
    public function findByEndpointHash(string $endpointHash): ?PushSubscription
    {
        return $this->findOneBy(['endpointHash' => $endpointHash]);
    }

    /**
     * Find subscription by endpoint
     */
    public function findByEndpoint(string $endpoint): ?PushSubscription
    {
        return $this->findByEndpointHash(hash('sha256', $endpoint));
    }

    /**
     * Delete inactive subscriptions older than given days
     */
    public function deleteInactiveOlderThan(int $days = 30): int
    {
        $cutoff = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('ps')
            ->delete()
            ->where('ps.isActive = false')
            ->andWhere('ps.updatedAt < :cutoff OR (ps.updatedAt IS NULL AND ps.createdAt < :cutoff)')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    /**
     * Find subscriptions with high failure count
     *
     * @return PushSubscription[]
     */
    public function findWithHighFailureCount(int $minFailures = 3): array
    {
        return $this->createQueryBuilder('ps')
            ->where('ps.failureCount >= :minFailures')
            ->setParameter('minFailures', $minFailures)
            ->orderBy('ps.failureCount', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count active subscriptions per tenant
     *
     * @return array<int, int> [tenant_id => count]
     */
    public function countActivePerTenant(): array
    {
        $results = $this->createQueryBuilder('ps')
            ->select('IDENTITY(ps.tenant) as tenant_id, COUNT(ps.id) as count')
            ->where('ps.isActive = true')
            ->groupBy('ps.tenant')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $row) {
            $counts[$row['tenant_id']] = (int) $row['count'];
        }

        return $counts;
    }

    public function save(PushSubscription $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(PushSubscription $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
