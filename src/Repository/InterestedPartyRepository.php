<?php

namespace App\Repository;

use App\Entity\InterestedParty;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InterestedParty>
 */
class InterestedPartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InterestedParty::class);
    }

    /**
     * Find parties with overdue communications
     */
    public function findOverdueCommunications(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.nextCommunication < :now')
            ->setParameter('now', new \DateTime())
            ->orderBy('p.importance', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find critical/high importance parties
     */
    public function findHighImportance(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.importance IN (:importance)')
            ->setParameter('importance', ['critical', 'high'])
            ->orderBy('p.importance', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find parties by type
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.partyType = :type')
            ->setParameter('type', $type)
            ->orderBy('p.importance', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get interested party statistics
     */
    public function getStatistics(): array
    {
        $total = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $highImportance = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.importance IN (:importance)')
            ->setParameter('importance', ['critical', 'high'])
            ->getQuery()
            ->getSingleScalarResult();

        $overdueCommunications = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.nextCommunication < :now')
            ->setParameter('now', new \DateTime())
            ->getQuery()
            ->getSingleScalarResult();

        $avgEngagement = $this->createQueryBuilder('p')
            ->select('AVG(p.satisfactionLevel)')
            ->where('p.satisfactionLevel IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'high_importance' => $highImportance,
            'overdue_communications' => $overdueCommunications,
            'avg_satisfaction' => round($avgEngagement ?? 0, 2)
        ];
    }
}
