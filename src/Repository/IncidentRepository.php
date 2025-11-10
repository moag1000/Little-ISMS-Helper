<?php

namespace App\Repository;

use App\Entity\Incident;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Incident Repository
 *
 * Repository for querying security Incident entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Incident>
 *
 * @method Incident|null find($id, $lockMode = null, $lockVersion = null)
 * @method Incident|null findOneBy(array $criteria, array $orderBy = null)
 * @method Incident[]    findAll()
 * @method Incident[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class IncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

    /**
     * Find all open/active security incidents ordered by severity and detection date.
     *
     * @return Incident[] Array of open Incident entities
     */
    public function findOpenIncidents(): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.status IN (:statuses)')
            ->setParameter('statuses', ['open', 'investigating', 'in_progress'])
            ->orderBy('i.severity', 'DESC')
            ->addOrderBy('i.detectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count incidents grouped by category.
     *
     * @return array<array{category: string, count: int}> Array of counts per category
     */
    public function countByCategory(): array
    {
        return $this->createQueryBuilder('i')
            ->select('i.category, COUNT(i.id) as count')
            ->groupBy('i.category')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count incidents grouped by severity level.
     *
     * @return array<array{severity: string, count: int}> Array of counts per severity
     */
    public function countBySeverity(): array
    {
        return $this->createQueryBuilder('i')
            ->select('i.severity, COUNT(i.id) as count')
            ->groupBy('i.severity')
            ->getQuery()
            ->getResult();
    }
}
