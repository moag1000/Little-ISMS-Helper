<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DoraDataFlow;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DoraDataFlow>
 */
class DoraDataFlowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DoraDataFlow::class);
    }

    /**
     * Find all DORA data flows scoped to a tenant.
     *
     * Used by both the CRUD index controller and the XBRL exporter; sort
     * order is supplier-name first then direction for predictable XBRL
     * output (test-friendly determinism).
     *
     * @return list<DoraDataFlow>
     */
    public function findByTenant(Tenant $tenant): array
    {
        /** @var list<DoraDataFlow> $rows */
        $rows = $this->createQueryBuilder('df')
            ->leftJoin('df.supplier', 's')
            ->andWhere('df.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('df.direction', 'ASC')
            ->addOrderBy('df.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * Find data flows by supplier (used on supplier-show drill-down).
     *
     * @return list<DoraDataFlow>
     */
    public function findBySupplier(int $supplierId): array
    {
        /** @var list<DoraDataFlow> $rows */
        $rows = $this->createQueryBuilder('df')
            ->andWhere('df.supplier = :supplier')
            ->setParameter('supplier', $supplierId)
            ->orderBy('df.direction', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
