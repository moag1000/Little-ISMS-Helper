<?php

namespace App\Repository;

use App\Entity\Risk;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Risk Repository
 *
 * Repository for querying Risk entities with custom business logic queries.
 *
 * @extends ServiceEntityRepository<Risk>
 *
 * @method Risk|null find($id, $lockMode = null, $lockVersion = null)
 * @method Risk|null findOneBy(array $criteria, array $orderBy = null)
 * @method Risk[]    findAll()
 * @method Risk[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class RiskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Risk::class);
    }

    /**
     * Find risks with high risk scores (probability × impact >= threshold).
     *
     * @param int $threshold Minimum risk score to consider as high risk (default: 12)
     * @return Risk[] Array of Risk entities sorted by severity
     */
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

    /**
     * Count risks grouped by treatment strategy.
     *
     * @return array<array{treatmentStrategy: string, count: int}> Array of counts per strategy
     */
    public function countByTreatmentStrategy(): array
    {
        return $this->createQueryBuilder('r')
            ->select('r.treatmentStrategy, COUNT(r.id) as count')
            ->groupBy('r.treatmentStrategy')
            ->getQuery()
            ->getResult();
    }
}
