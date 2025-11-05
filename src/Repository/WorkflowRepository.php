<?php

namespace App\Repository;

use App\Entity\Workflow;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Workflow>
 */
class WorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workflow::class);
    }

    /**
     * Find active workflows for a specific entity type
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
     * Find all active workflows
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
