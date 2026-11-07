<?php

declare(strict_types=1);

namespace App\Service\Compliance;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceMappingRepository;
use App\Repository\MappingGapItemRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Aggregate mapping and gap statistics, limited to the mappings visible to the
 * current tenant (shared catalogue + own uploads). CLI/instance-wide callers
 * pass `$global = true`.
 */
class MappingStatistics
{
    private const array RESOLVED = ['resolved', 'wont_fix'];

    public function __construct(
        private readonly ComplianceMappingRepository $mappings,
        private readonly MappingGapItemRepository $gaps,
        private readonly MappingAccessGuard $guard,
    ) {
    }

    /** @return array{total_mappings: int, full_mappings: int, exceeds_mappings: int, partial_mappings: int, bidirectional_mappings: int} */
    public function getMappingStatistics(bool $global = false): array
    {
        $type = fn(string $t): int => $this->count('cm.mappingType = :t', ['t' => $t], $global);

        return [
            'total_mappings' => $this->count(null, [], $global),
            'full_mappings' => $type('full'),
            'exceeds_mappings' => $type('exceeds'),
            'partial_mappings' => $type('partial'),
            'bidirectional_mappings' => $this->count('cm.bidirectional = :b', ['b' => true], $global),
        ];
    }

    /** @return array<string, int|float> */
    public function getQualityStatistics(bool $global = false): array
    {
        $total = $this->count(null, [], $global);
        $analyzed = $this->count('cm.calculatedPercentage IS NOT NULL', [], $global);
        // "Scored" = heuristic percentage OR MQS quality score.
        $scored = $this->count('cm.calculatedPercentage IS NOT NULL OR cm.qualityScore IS NOT NULL', [], $global);

        $withGaps = $this->scope($this->mappings->createQueryBuilder('cm')
            ->select('COUNT(DISTINCT cm.id)')
            ->join('cm.gapItems', 'gi')
            ->where('gi.status NOT IN (:resolved)')
            ->setParameter('resolved', self::RESOLVED), $global)->getQuery()->getSingleScalarResult();
        $avg = fn(string $f): float => round((float) $this->scope($this->mappings->createQueryBuilder('cm')
            ->select('AVG(cm.' . $f . ')')->where('cm.' . $f . ' IS NOT NULL'), $global)
            ->getQuery()->getSingleScalarResult(), 1);

        return [
            'total_mappings' => $total,
            'analyzed_mappings' => $analyzed,
            'scored_mappings' => $scored,
            'unanalyzed_mappings' => $total - $scored,
            'analysis_coverage' => $total > 0 ? round(($scored / $total) * 100, 1) : 0,
            'high_confidence' => $this->count('cm.analysisConfidence >= 80', [], $global),
            'medium_confidence' => $this->count('cm.analysisConfidence >= 60 AND cm.analysisConfidence < 80', [], $global),
            'low_confidence' => $this->count('cm.analysisConfidence < 60', [], $global),
            'requires_review' => $this->count(
                'cm.requiresReview = :rr AND cm.reviewStatus = :st',
                ['rr' => true, 'st' => 'unreviewed'],
                $global,
            ),
            'with_unresolved_gaps' => (int) $withGaps,
            'avg_quality_score' => $avg('qualityScore'),
            'avg_confidence' => $avg('analysisConfidence'),
        ];
    }

    /** Mappings lacking both a calculatedPercentage and an MQS qualityScore. */
    public function countMetadataPoorUnscored(bool $global = false): int
    {
        return $this->count('cm.calculatedPercentage IS NULL AND cm.qualityScore IS NULL', [], $global);
    }

    /** @return list<array<string, mixed>> */
    public function getQualityDistribution(bool $global = false): array
    {
        return $this->scope($this->mappings->createQueryBuilder('cm')
            ->select("CASE WHEN cm.analysisConfidence >= 80 THEN 'high' WHEN cm.analysisConfidence >= 60 THEN 'medium' ELSE 'low' END as confidence_level, "
                . 'COUNT(cm.id) as count, AVG(cm.calculatedPercentage) as avg_percentage, AVG(cm.qualityScore) as avg_quality')
            ->where('cm.analysisConfidence IS NOT NULL')
            ->groupBy('confidence_level'), $global)->getQuery()->getResult();
    }

    /** @return list<array<string, mixed>> */
    public function getSimilarityDistribution(bool $global = false): array
    {
        return $this->scope($this->mappings->createQueryBuilder('cm')
            ->select("CASE WHEN cm.textualSimilarity >= 0.8 THEN 'very_high' WHEN cm.textualSimilarity >= 0.6 THEN 'high' "
                . "WHEN cm.textualSimilarity >= 0.4 THEN 'medium' WHEN cm.textualSimilarity >= 0.2 THEN 'low' ELSE 'very_low' END as similarity_level, "
                . 'COUNT(cm.id) as count')
            ->where('cm.textualSimilarity IS NOT NULL')
            ->groupBy('similarity_level'), $global)->getQuery()->getResult();
    }

    /** @return list<array<string, mixed>> */
    public function getFrameworkQualityComparison(bool $global = false): array
    {
        return $this->scope($this->mappings->createQueryBuilder('cm')
            ->select('sf.code as source_framework, tf.code as target_framework, COUNT(cm.id) as mapping_count, '
                . 'AVG(cm.calculatedPercentage) as avg_percentage, AVG(cm.qualityScore) as avg_quality, '
                . 'AVG(cm.analysisConfidence) as avg_confidence, SUM(CASE WHEN cm.requiresReview = 1 THEN 1 ELSE 0 END) as review_count')
            ->join('cm.sourceRequirement', 'sr')
            ->join('sr.framework', 'sf')
            ->join('cm.targetRequirement', 'tr')
            ->join('tr.framework', 'tf')
            ->where('cm.calculatedPercentage IS NOT NULL')
            ->groupBy('sf.code, tf.code')
            ->orderBy('avg_quality', \SortDirection::Descending), $global)->getQuery()->getResult();
    }

    /** @return list<array<string, mixed>> */
    public function getGapStatisticsByPriority(bool $global = false): array
    {
        return $this->gapQuery($global)
            ->select('g.priority, COUNT(g.id) as count, SUM(g.percentageImpact) as totalImpact, AVG(g.estimatedEffort) as avgEffort')
            ->groupBy('g.priority')->getQuery()->getResult();
    }

    /** @return array<string, mixed> */
    public function calculateTotalRemediationEffort(bool $global = false): array
    {
        $sum = fn(string $select): mixed => $this->gapQuery($global)->select($select)->getQuery()->getSingleScalarResult();
        $byPriority = $this->gapQuery($global)
            ->select('g.priority, COUNT(g.id) as count, SUM(g.estimatedEffort) as effort')
            ->groupBy('g.priority')->getQuery()->getResult();

        return [
            'total_gaps' => (int) $sum('COUNT(g.id)'),
            'total_effort' => (int) $sum('SUM(g.estimatedEffort)'),
            'by_priority' => $byPriority,
        ];
    }

    private function gapQuery(bool $global): QueryBuilder
    {
        $qb = $this->gaps->createQueryBuilder('g')
            ->where('g.status NOT IN (:resolved)')
            ->setParameter('resolved', self::RESOLVED);
        if (!$global) {
            $qb->join('g.mapping', 'gm');
            $this->guard->scopeQuery($qb, 'gm');
        }

        return $qb;
    }

    /** @param array<string, mixed> $params */
    private function count(?string $where, array $params, bool $global): int
    {
        $qb = $this->mappings->createQueryBuilder('cm')->select('COUNT(cm.id)');
        if ($where !== null) {
            $qb->where($where);
            foreach ($params as $k => $v) {
                $qb->setParameter($k, $v);
            }
        }

        return (int) $this->scope($qb, $global)->getQuery()->getSingleScalarResult();
    }

    /** Scoping is applied last: where() above would otherwise overwrite it. */
    private function scope(QueryBuilder $qb, bool $global): QueryBuilder
    {
        return $global ? $qb : $this->guard->scopeQuery($qb);
    }
}
