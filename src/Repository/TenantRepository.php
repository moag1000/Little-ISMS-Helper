<?php

namespace App\Repository;

use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Tenant Repository
 *
 * Repository for querying Tenant entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Tenant>
 *
 * @method Tenant|null find($id, $lockMode = null, $lockVersion = null)
 * @method Tenant|null findOneBy(array $criteria, array $orderBy = null)
 * @method Tenant[]    findAll()
 * @method Tenant[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    /**
     * Find all active tenants.
     *
     * @return Tenant[] Array of active Tenant entities sorted by name
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find tenant by Azure AD tenant ID for SSO integration.
     *
     * @param string $azureTenantId Azure AD tenant identifier
     * @return Tenant|null Tenant entity or null if not found
     */
    public function findByAzureTenantId(string $azureTenantId): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->where('t.azureTenantId = :azureTenantId')
            ->setParameter('azureTenantId', $azureTenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find tenant by unique code.
     *
     * @param string $code Tenant code identifier
     * @return Tenant|null Tenant entity or null if not found
     */
    public function findByCode(string $code): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->where('t.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
