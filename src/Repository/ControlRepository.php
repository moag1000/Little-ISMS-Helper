<?php

namespace App\Repository;

use App\Entity\Control;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Control Repository
 *
 * Repository for querying ISO 27001 Control entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Control>
 *
 * @method Control|null find($id, $lockMode = null, $lockVersion = null)
 * @method Control|null findOneBy(array $criteria, array $orderBy = null)
 * @method Control[]    findAll()
 * @method Control[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ControlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Control::class);
    }

    /**
     * Find all applicable ISO 27001 controls ordered by control ID.
     *
     * @return Control[] Array of applicable Control entities
     */
    public function findApplicableControls(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.applicable = :applicable')
            ->setParameter('applicable', true)
            ->orderBy('c.controlId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count controls grouped by ISO 27001 Annex A category.
     *
     * @return array<array{category: string, total: int, applicable: int}> Array with total and applicable counts per category
     */
    public function countByCategory(): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.category, COUNT(c.id) as total, SUM(CASE WHEN c.applicable = true THEN 1 ELSE 0 END) as applicable')
            ->groupBy('c.category')
            ->orderBy('c.category', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get control implementation statistics grouped by status.
     *
     * @return array<array{implementationStatus: string, count: int}> Array of counts per implementation status
     */
    public function getImplementationStats(): array
    {
        return $this->createQueryBuilder('c')
            ->select('c.implementationStatus, COUNT(c.id) as count')
            ->where('c.applicable = :applicable')
            ->setParameter('applicable', true)
            ->groupBy('c.implementationStatus')
            ->getQuery()
            ->getResult();
    }
}
