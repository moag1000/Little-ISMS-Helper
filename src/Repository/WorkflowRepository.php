<?php

namespace App\Repository;

use App\Entity\Workflow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Workflow Repository
 *
 * Repository for querying Workflow entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Workflow>
 *
 * @method Workflow|null find($id, $lockMode = null, $lockVersion = null)
 * @method Workflow|null findOneBy(array $criteria, array $orderBy = null)
 * @method Workflow[]    findAll()
 * @method Workflow[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workflow::class);
    }

    /**
     * Find active workflows for a specific entity type.
     *
     * @param string $entityType Entity class name (e.g., 'Risk', 'Control', 'Asset')
     * @return Workflow[] Array of active Workflow entities sorted by name
     */
    public function findActiveByEntityType(string $entityType): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.entityType = :entityType')
            ->andWhere('w.isActive = true')
            ->setParameter('entityType', $entityType)
            ->orderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active workflows across all entity types.
     *
     * @return Workflow[] Array of active Workflow entities sorted by name
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.isActive = true')
            ->orderBy('w.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
