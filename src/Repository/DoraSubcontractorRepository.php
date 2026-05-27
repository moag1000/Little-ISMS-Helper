<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DoraSubcontractor;
use App\Entity\Supplier;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DoraSubcontractor>
 */
class DoraSubcontractorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DoraSubcontractor::class);
    }

    /**
     * All subcontractors for a tenant, ordered by parent-supplier-name then tier.
     *
     * @return DoraSubcontractor[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('s.parentSupplier', 'ASC')
            ->addOrderBy('s.tier', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Tier-2 (direct) subcontractors of a given Supplier. Tree-roots for the
     * recursive chain-walker in the XBRL exporter.
     *
     * @return DoraSubcontractor[]
     */
    public function findDirectChildrenOfSupplier(Supplier $supplier): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.parentSupplier = :supplier')
            ->andWhere('s.parentSubcontractor IS NULL')
            ->setParameter('supplier', $supplier)
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All subcontractors anywhere in the chain rooted at the given Supplier
     * (all tiers, flat list). Used by the XBRL exporter to detect chain
     * presence without walking the tree first.
     *
     * @return DoraSubcontractor[]
     */
    public function findAllForSupplier(Supplier $supplier): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.parentSupplier = :supplier')
            ->setParameter('supplier', $supplier)
            ->orderBy('s.tier', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
