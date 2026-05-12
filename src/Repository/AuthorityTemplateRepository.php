<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuthorityTemplate;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AuthorityTemplate>
 */
class AuthorityTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthorityTemplate::class);
    }

    /** @return AuthorityTemplate[] */
    public function findActiveByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('t.authorityKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByTenantAndKey(Tenant $tenant, string $authorityKey): ?AuthorityTemplate
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.tenant = :tenant')
            ->andWhere('t.authorityKey = :key')
            ->setParameter('tenant', $tenant)
            ->setParameter('key', $authorityKey)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
