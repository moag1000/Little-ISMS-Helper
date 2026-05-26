<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DoraExitPlan;
use App\Entity\Supplier;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DoraExitPlan>
 */
class DoraExitPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DoraExitPlan::class);
    }

    /**
     * @return DoraExitPlan[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.supplier', 's')
            ->addSelect('s')
            ->where('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneBySupplier(Supplier $supplier): ?DoraExitPlan
    {
        return $this->findOneBy(['supplier' => $supplier]);
    }

    /**
     * RT_06 exporter helper — exit plans for DORA-relevant suppliers of a tenant.
     * Mirrors SupplierRepository::findByTenantAndDoraRelevant() so the XBRL
     * writer can emit one ESA-RT_06 element per critical supplier.
     *
     * @return DoraExitPlan[]
     */
    public function findByTenantAndDoraRelevant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->innerJoin('p.supplier', 's')
            ->addSelect('s')
            ->where('p.tenant = :tenant')
            ->andWhere('s.isDoraRelevant = :dora')
            ->setParameter('tenant', $tenant)
            ->setParameter('dora', true)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
