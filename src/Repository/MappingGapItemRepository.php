<?php

namespace App\Repository;

use App\Entity\MappingGapItem;
use App\Entity\ComplianceMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MappingGapItem>
 *
 * @method MappingGapItem|null find($id, $lockMode = null, $lockVersion = null)
 * @method MappingGapItem|null findOneBy(array $criteria, array $orderBy = null)
 * @method MappingGapItem[]    findAll()
 * @method MappingGapItem[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MappingGapItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MappingGapItem::class);
    }

    /**
     * Find all gap items for a specific mapping
     *
     * @return MappingGapItem[]
     */
    public function findByMapping(ComplianceMapping $complianceMapping): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.mapping = :mapping')
            ->setParameter('mapping', $complianceMapping)
            ->orderBy('g.priority', 'ASC')
            ->addOrderBy('g.percentageImpact', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find critical/high priority gaps
     *
     * @return MappingGapItem[]
     */
    public function findHighPriorityGaps(): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.priority IN (:priorities)')
            ->andWhere('g.status NOT IN (:resolvedStatuses)')
            ->setParameter('priorities', ['critical', 'high'])
            ->setParameter('resolvedStatuses', ['resolved', 'wont_fix'])
            ->orderBy('g.priority', 'ASC')
            ->addOrderBy('g.percentageImpact', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get gap statistics by type
     */
    public function getGapStatisticsByType(): array
    {
        $queryBuilder = $this->createQueryBuilder('g');

        return $queryBuilder
            ->select('g.gapType, COUNT(g.id) as count, SUM(g.percentageImpact) as totalImpact')
            ->groupBy('g.gapType')
            ->orderBy('count', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get gap statistics by priority
     */
    public function getGapStatisticsByPriority(): array
    {
        $queryBuilder = $this->createQueryBuilder('g');

        return $queryBuilder
            ->select('g.priority, COUNT(g.id) as count, SUM(g.percentageImpact) as totalImpact, AVG(g.estimatedEffort) as avgEffort')
            ->where('g.status NOT IN (:resolvedStatuses)')
            ->setParameter('resolvedStatuses', ['resolved', 'wont_fix'])
            ->groupBy('g.priority')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find gaps with low confidence that need manual review
     *
     * @return MappingGapItem[]
     */
    public function findLowConfidenceGaps(int $confidenceThreshold = 60): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.confidence < :threshold')
            ->andWhere('g.status = :status')
            ->setParameter('threshold', $confidenceThreshold)
            ->setParameter('status', 'identified')
            ->orderBy('g.confidence', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculate total effort required to close all gaps
     *
     * @return array{total_gaps: int, total_effort: int, by_priority: array}
     */
    public function calculateTotalRemediationEffort(): array
    {
        $queryBuilder = $this->createQueryBuilder('g');

        $totalGaps = $queryBuilder
            ->select('COUNT(g.id)')
            ->where('g.status NOT IN (:resolvedStatuses)')
            ->setParameter('resolvedStatuses', ['resolved', 'wont_fix'])
            ->getQuery()
            ->getSingleScalarResult();

        $totalEffort = $this->createQueryBuilder('g')
            ->select('SUM(g.estimatedEffort)')
            ->where('g.status NOT IN (:resolvedStatuses)')
            ->setParameter('resolvedStatuses', ['resolved', 'wont_fix'])
            ->getQuery()
            ->getSingleScalarResult();

        $byPriority = $this->createQueryBuilder('g')
            ->select('g.priority, COUNT(g.id) as count, SUM(g.estimatedEffort) as effort')
            ->where('g.status NOT IN (:resolvedStatuses)')
            ->setParameter('resolvedStatuses', ['resolved', 'wont_fix'])
            ->groupBy('g.priority')
            ->getQuery()
            ->getResult();

        return [
            'total_gaps' => (int) $totalGaps,
            'total_effort' => (int) ($totalEffort ?? 0),
            'by_priority' => $byPriority,
        ];
    }
}
