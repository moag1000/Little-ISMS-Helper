<?php

namespace App\Repository;

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
            ->setParameter('today', new \DateTime())
            ->orderBy('a.plannedDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
