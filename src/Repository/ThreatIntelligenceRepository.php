<?php

namespace App\Repository;

use App\Entity\ThreatIntelligence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ThreatIntelligence>
 */
class ThreatIntelligenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThreatIntelligence::class);
    }

    /**
     * Find active threats (not closed)
     */
    public function findActiveThreats(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.status != :status')
            ->setParameter('status', 'closed')
            ->orderBy('t.severity', 'ASC')
            ->addOrderBy('t.detectionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find threats affecting the organization
     */
    public function findAffectingOrganization(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.affectsOrganization = :affects')
            ->setParameter('affects', true)
            ->orderBy('t.severity', 'ASC')
            ->addOrderBy('t.detectionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find critical and high severity threats
     */
    public function findHighSeverityThreats(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.severity IN (:severities)')
            ->setParameter('severities', ['critical', 'high'])
            ->andWhere('t.status != :status')
            ->setParameter('status', 'closed')
            ->orderBy('t.detectionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get threat statistics
     */
    public function getStatistics(): array
    {
        return [
            'total' => count($this->findAll()),
            'active' => count($this->findActiveThreats()),
            'affecting_org' => count($this->findAffectingOrganization()),
            'high_severity' => count($this->findHighSeverityThreats()),
            'by_type' => $this->getCountByType(),
            'by_severity' => $this->getCountBySeverity(),
        ];
    }

    private function getCountByType(): array
    {
        $results = $this->createQueryBuilder('t')
            ->select('t.threatType as type, COUNT(t.id) as count')
            ->groupBy('t.threatType')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['type']] = (int) $result['count'];
        }

        return $counts;
    }

    private function getCountBySeverity(): array
    {
        $results = $this->createQueryBuilder('t')
            ->select('t.severity, COUNT(t.id) as count')
            ->groupBy('t.severity')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($results as $result) {
            $counts[$result['severity']] = (int) $result['count'];
        }

        return $counts;
    }
}
