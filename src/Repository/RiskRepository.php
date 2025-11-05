<?php

namespace App\Repository;

use App\Entity\Risk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RiskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Risk::class);
    }

    public function findHighRisks(int $threshold = 12): array
    {
        return $this->createQueryBuilder('r')
            ->where('(r.probability * r.impact) >= :threshold')
            ->setParameter('threshold', $threshold)
            ->orderBy('r.probability', 'DESC')
            ->addOrderBy('r.impact', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countByTreatmentStrategy(): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.treatmentStrategy, COUNT(r.id) as count')
            ->groupBy('r.treatmentStrategy')
            ->getQuery()
            ->getResult();
    }
}
