<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Department;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Department Repository (S18 B3).
 *
 * @extends ServiceEntityRepository<Department>
 *
 * @method Department|null find($id, $lockMode = null, $lockVersion = null)
 * @method Department|null findOneBy(array $criteria, array $orderBy = null)
 * @method Department[]    findAll()
 * @method Department[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DepartmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Department::class);
    }

    /**
     * Find all departments for a tenant, ordered by name.
     *
     * @return Department[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active-only departments for a tenant (for FormType choice lists).
     *
     * @return Department[]
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.tenant = :tenant')
            ->andWhere('d.isActive = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->orderBy('d.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
