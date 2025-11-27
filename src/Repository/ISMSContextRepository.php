<?php

namespace App\Repository;

use App\Entity\ISMSContext;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * ISMS Context Repository
 *
 * Repository for querying ISMSContext entities (ISO 27001 Clause 4).
 *
 * @extends ServiceEntityRepository<ISMSContext>
 *
 * @method ISMSContext|null find($id, $lockMode = null, $lockVersion = null)
 * @method ISMSContext|null findOneBy(array $criteria, array $orderBy = null)
 * @method ISMSContext[]    findAll()
 * @method ISMSContext[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ISMSContextRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ISMSContext::class);
    }

    /**
     * Get the current ISMS context (most recently updated).
     *
     * @return ISMSContext|null Current context entity or null if none exists
     */
    public function getCurrentContext(): ?ISMSContext
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.updatedAt', 'DESC')
            ->addOrderBy('c.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Get ISMS context for a specific tenant.
     *
     * @param Tenant|null $tenant The tenant to get context for
     * @return ISMSContext|null Context for tenant or null if none exists
     */
    public function getContextForTenant(?Tenant $tenant): ?ISMSContext
    {
        if (!$tenant instanceof Tenant) {
            return $this->getCurrentContext();
        }

        return $this->findOneBy(['tenant' => $tenant]);
    }
}
