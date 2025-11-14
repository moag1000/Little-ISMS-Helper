<?php

namespace App\Repository;

use App\Entity\Training;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Training Repository
 *
 * Repository for querying Training entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Training>
 *
 * @method Training|null find($id, $lockMode = null, $lockVersion = null)
 * @method Training|null findOneBy(array $criteria, array $orderBy = null)
 * @method Training[]    findAll()
 * @method Training[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TrainingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Training::class);
    }

    /**
     * Find upcoming trainings (planned or scheduled with future dates).
     *
     * @return Training[] Array of Training entities sorted by scheduled date
     */
    public function findUpcoming(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status IN (:statuses)')
            ->andWhere('t.scheduledDate >= :today')
            ->setParameter('statuses', ['planned', 'scheduled'])
            ->setParameter('today', new \DateTime())
            ->orderBy('t.scheduledDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all trainings for a tenant (own trainings only)
     *
     * @param \App\Entity\Tenant $tenant The tenant to find trainings for
     * @return Training[] Array of Training entities
     */
    public function findByTenant($tenant): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('t.scheduledDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find trainings by tenant including all ancestors (for hierarchical governance)
     * This allows viewing inherited trainings from parent companies, grandparents, etc.
     *
     * @param \App\Entity\Tenant $tenant The tenant to find trainings for
     * @param \App\Entity\Tenant|null $parentTenant DEPRECATED: Use tenant's getAllAncestors() instead
     * @return Training[] Array of Training entities (own + inherited from all ancestors)
     */
    public function findByTenantIncludingParent($tenant, $parentTenant = null): array
    {
        // Get all ancestors (parent, grandparent, great-grandparent, etc.)
        $ancestors = $tenant->getAllAncestors();

        $qb = $this->createQueryBuilder('t')
            ->where('t.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        // Include trainings from all ancestors in the hierarchy
        if (!empty($ancestors)) {
            $qb->orWhere('t.tenant IN (:ancestors)')
               ->setParameter('ancestors', $ancestors);
        }

        return $qb
            ->orderBy('t.scheduledDate', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
