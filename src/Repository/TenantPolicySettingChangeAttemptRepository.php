<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\TenantPolicySettingChangeAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantPolicySettingChangeAttempt>
 *
 * @method TenantPolicySettingChangeAttempt|null find($id, $lockMode = null, $lockVersion = null)
 * @method TenantPolicySettingChangeAttempt|null findOneBy(array $criteria, array $orderBy = null)
 * @method TenantPolicySettingChangeAttempt[]    findAll()
 * @method TenantPolicySettingChangeAttempt[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TenantPolicySettingChangeAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantPolicySettingChangeAttempt::class);
    }

    /**
     * Recent rejection attempts for a key, newest first. Drives the
     * "ask Konzern-CISO to relax X" UI surface.
     *
     * @return TenantPolicySettingChangeAttempt[]
     */
    public function findRecentByTenantAndKey(Tenant $tenant, string $key, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.tenant = :tenant')
            ->andWhere('a.key = :key')
            ->setParameter('tenant', $tenant)
            ->setParameter('key', $key)
            ->orderBy('a.attemptedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
