<?php

namespace App\Repository;

use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    public function findActive(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findByAzureTenantId(string $azureTenantId): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->where('t.azureTenantId = :azureTenantId')
            ->setParameter('azureTenantId', $azureTenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByCode(string $code): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->where('t.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
