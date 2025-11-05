<?php

namespace App\Repository;

use App\Entity\Incident;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class IncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Incident::class);
    }

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

    public function countByCategory(): array
    {
        return $this->createQueryBuilder('i')
            ->select('i.category, COUNT(i.id) as count')
            ->groupBy('i.category')
            ->getQuery()
            ->getResult();
    }

    public function countBySeverity(): array
    {
        return $this->createQueryBuilder('i')
            ->select('i.severity, COUNT(i.id) as count')
            ->groupBy('i.severity')
            ->getQuery()
            ->getResult();
    }
}
