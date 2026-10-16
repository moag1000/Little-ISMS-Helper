<?php

declare(strict_types=1);

namespace App\Repository;

use DateTime;
use DateTimeInterface;
use App\Entity\Tenant;
use App\Entity\InternalAudit;
use App\Enum\InternalAuditStatus;
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
 * @method InternalAudit[]    findByTenant(Tenant $tenant)
 */
class InternalAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InternalAudit::class);
    }

    /**
     * Find upcoming planned audits (planned status with future dates),
     * optionally restricted to one tenant and/or a planning horizon.
     *
     * @return InternalAudit[] Array of InternalAudit entities sorted by planned date
     */
    public function findUpcoming(?Tenant $tenant = null, ?DateTimeInterface $until = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->andWhere('a.plannedDate >= :today')
            ->setParameter('status', InternalAuditStatus::Planned->value)
            ->setParameter('today', new DateTime())
            ->orderBy('a.plannedDate', 'ASC');

        if ($tenant instanceof Tenant) {
            $qb->andWhere('a.tenant = :tenant')->setParameter('tenant', $tenant);
        }
        if ($until instanceof DateTimeInterface) {
            $qb->andWhere('a.plannedDate <= :until')->setParameter('until', $until);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Find all audits visible to a tenant (own audits + corporate audits covering this tenant)
     *
     * @param Tenant $tenant The tenant to find audits for
     * @return InternalAudit[] Array of InternalAudit entities
     */
    public function findByTenantIncludingCorporate(Tenant $tenant): array
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
    public function findCorporateAudits(Tenant $tenant): array
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
    public function findAuditsCoveringSubsidiary(Tenant $subsidiary): array
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
