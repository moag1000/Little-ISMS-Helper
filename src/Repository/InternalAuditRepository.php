<?php

namespace App\Repository;

use DateTime;
use App\Entity\Tenant;
use App\Entity\InternalAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Internal Audit Repository
 *
 * Repository for querying InternalAudit entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<InternalAudit>
 *
 * @method InternalAudit|null find($id, $lockMode = null, $lockVersion = null)
 * @method InternalAudit|null findOneBy(array $criteria, array $orderBy = null)
 * @method InternalAudit[]    findAll()
 * @method InternalAudit[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class InternalAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternalAudit::class);
    }

    /**
     * Find upcoming planned audits (planned status with future dates).
     *
     * @return InternalAudit[] Array of InternalAudit entities sorted by planned date
     */
    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->andWhere('a.plannedDate >= :today')
            ->setParameter('status', 'planned')
            ->setParameter('today', new DateTime())
            ->orderBy('a.plannedDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all audits visible to a tenant (own audits + corporate audits covering this tenant)
     *
     * @param Tenant $tenant The tenant to find audits for
     * @return InternalAudit[] Array of InternalAudit entities
     */
    public function findByTenantIncludingCorporate($tenant): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.auditedSubsidiaries', 's')
            ->where('a.tenant = :tenant OR s.id = :tenantId')
            ->setParameter('tenant', $tenant)
            ->setParameter('tenantId', $tenant->getId())
            ->orderBy('a.plannedDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all corporate-wide audits created by a parent tenant
     *
     * @param Tenant $tenant The parent tenant
     * @return InternalAudit[] Array of corporate audit entities
     */
    public function findCorporateAudits($tenant): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.tenant = :tenant')
            ->andWhere('a.scopeType IN (:corporateTypes)')
            ->setParameter('tenant', $tenant)
            ->setParameter('corporateTypes', ['corporate_wide', 'corporate_subsidiaries'])
            ->orderBy('a.plannedDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all audits that cover a specific subsidiary
     *
     * @param Tenant $subsidiary The subsidiary tenant
     * @return InternalAudit[] Array of audits covering this subsidiary
     */
    public function findAuditsCoveringSubsidiary($subsidiary): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.auditedSubsidiaries', 's')
            ->where('s.id = :subsidiaryId')
            ->setParameter('subsidiaryId', $subsidiary->getId())
            ->orderBy('a.plannedDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
