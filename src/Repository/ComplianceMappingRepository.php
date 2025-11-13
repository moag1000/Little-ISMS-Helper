<?php

namespace App\Repository;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceFramework;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Compliance Mapping Repository
 *
 * Repository for querying ComplianceMapping entities with cross-framework correlation and transitive compliance analysis.
 * Supports framework gap analysis, coverage calculation, and compliance reuse across different regulatory frameworks.
 *
 * @extends ServiceEntityRepository<ComplianceMapping>
 *
 * @method ComplianceMapping|null find($id, $lockMode = null, $lockVersion = null)
 * @method ComplianceMapping|null findOneBy(array $criteria, array $orderBy = null)
 * @method ComplianceMapping[]    findAll()
 * @method ComplianceMapping[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ComplianceMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceMapping::class);
    }

    /**
     * Find all mappings where the given requirement is the source (outbound mappings).
     *
     * @param ComplianceRequirement $requirement Source requirement entity
     * @return ComplianceMapping[] Array of mappings sorted by strength (strongest first)
     */
    public function findMappingsFromRequirement(ComplianceRequirement $requirement): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.sourceRequirement = :requirement')
            ->setParameter('requirement', $requirement)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all mappings where the given requirement is the target (inbound mappings).
     *
     * @param ComplianceRequirement $requirement Target requirement entity
     * @return ComplianceMapping[] Array of mappings sorted by strength (strongest first)
     */
    public function findMappingsToRequirement(ComplianceRequirement $requirement): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.targetRequirement = :requirement')
            ->setParameter('requirement', $requirement)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all cross-framework mappings between two compliance frameworks.
     *
     * @param ComplianceFramework $sourceFramework Source framework (e.g., ISO 27001)
     * @param ComplianceFramework $targetFramework Target framework (e.g., SOC 2, GDPR)
     * @return ComplianceMapping[] Array of mappings sorted by strength (strongest first)
     */
    public function findCrossFrameworkMappings(
        ComplianceFramework $sourceFramework,
        ComplianceFramework $targetFramework
    ): array {
        return $this->createQueryBuilder('cm')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->where('sr.framework = :sourceFramework')
            ->andWhere('tr.framework = :targetFramework')
            ->setParameter('sourceFramework', $sourceFramework)
            ->setParameter('targetFramework', $targetFramework)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find strong mappings (100%+ coverage) between frameworks for compliance reuse analysis.
     *
     * @param ComplianceFramework $sourceFramework Source framework
     * @param ComplianceFramework $targetFramework Target framework
     * @return ComplianceMapping[] Array of full/exceeds mappings sorted by strength
     */
    public function findStrongMappings(
        ComplianceFramework $sourceFramework,
        ComplianceFramework $targetFramework
    ): array {
        return $this->createQueryBuilder('cm')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->where('sr.framework = :sourceFramework')
            ->andWhere('tr.framework = :targetFramework')
            ->andWhere('cm.mappingPercentage >= 100')
            ->setParameter('sourceFramework', $sourceFramework)
            ->setParameter('targetFramework', $targetFramework)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calculate framework coverage: how comprehensively the source framework covers the target framework.
     *
     * Analyzes cross-framework mappings to determine:
     * - Which target requirements are covered
     * - Strength of coverage (full, partial, weak)
     * - Overall coverage percentage
     *
     * @param ComplianceFramework $sourceFramework Source framework providing coverage
     * @param ComplianceFramework $targetFramework Target framework being covered
     * @return array{source_framework: string, target_framework: string, total_target_requirements: int, covered_requirements: int, coverage_percentage: float, strong_mappings: int, partial_mappings: int, weak_mappings: int} Coverage analysis
     */
    public function calculateFrameworkCoverage(
        ComplianceFramework $sourceFramework,
        ComplianceFramework $targetFramework
    ): array {
        $mappings = $this->findCrossFrameworkMappings($sourceFramework, $targetFramework);
        $targetRequirements = $targetFramework->getRequirements()->count();

        $coveredRequirements = [];
        $totalCoveragePercentage = 0;

        foreach ($mappings as $mapping) {
            $targetReqId = $mapping->getTargetRequirement()->getId();

            // Track the best coverage for each target requirement
            if (!isset($coveredRequirements[$targetReqId])
                || $coveredRequirements[$targetReqId] < $mapping->getMappingPercentage()) {
                $coveredRequirements[$targetReqId] = $mapping->getMappingPercentage();
            }
        }

        foreach ($coveredRequirements as $coverage) {
            $totalCoveragePercentage += min(100, $coverage); // Cap at 100% per requirement
        }

        $averageCoverage = $targetRequirements > 0
            ? round($totalCoveragePercentage / $targetRequirements, 2)
            : 0;

        return [
            'source_framework' => $sourceFramework->getName(),
            'target_framework' => $targetFramework->getName(),
            'total_target_requirements' => $targetRequirements,
            'covered_requirements' => count($coveredRequirements),
            'coverage_percentage' => $averageCoverage,
            'strong_mappings' => count(array_filter($coveredRequirements, fn($c) => $c >= 100)),
            'partial_mappings' => count(array_filter($coveredRequirements, fn($c) => $c >= 50 && $c < 100)),
            'weak_mappings' => count(array_filter($coveredRequirements, fn($c) => $c < 50)),
        ];
    }

    /**
     * Calculate transitive compliance: how fulfilling source framework requirements helps target framework compliance.
     *
     * This calculates the "compliance transfer" effect where implementing controls for one framework
     * automatically contributes to compliance with another framework through requirement mappings.
     *
     * Example: If ISO 27001 A.9.2.1 is 80% fulfilled and maps 100% to GDPR Art. 32(1),
     * then there's a transitive contribution of 80% to the GDPR requirement.
     *
     * @param ComplianceFramework $sourceFramework Source framework with existing fulfillment
     * @param ComplianceFramework $targetFramework Target framework receiving transitive benefit
     * @return array{source_framework: string, target_framework: string, requirements_helped: int, average_transitive_benefit: float, total_benefit: float, contributions: array} Transitive compliance analysis
     */
    public function getTransitiveCompliance(
        ComplianceFramework $sourceFramework,
        ComplianceFramework $targetFramework
    ): array {
        $mappings = $this->findCrossFrameworkMappings($sourceFramework, $targetFramework);
        $transitiveContributions = [];

        foreach ($mappings as $mapping) {
            $transitiveFulfillment = $mapping->calculateTransitiveFulfillment();

            if ($transitiveFulfillment > 0) {
                $transitiveContributions[] = [
                    'source_req' => $mapping->getSourceRequirement()->getRequirementId(),
                    'target_req' => $mapping->getTargetRequirement()->getRequirementId(),
                    'mapping_strength' => $mapping->getMappingPercentage(),
                    'source_fulfillment' => $mapping->getSourceRequirement()->getFulfillmentPercentage(),
                    'transitive_contribution' => $transitiveFulfillment,
                ];
            }
        }

        // Calculate total transitive benefit
        $totalBenefit = 0;
        $targetRequirementsHelped = [];

        foreach ($transitiveContributions as $contribution) {
            $targetReqId = $contribution['target_req'];

            // Track best contribution for each target requirement
            if (!isset($targetRequirementsHelped[$targetReqId])
                || $targetRequirementsHelped[$targetReqId] < $contribution['transitive_contribution']) {
                $targetRequirementsHelped[$targetReqId] = $contribution['transitive_contribution'];
            }
        }

        foreach ($targetRequirementsHelped as $contribution) {
            $totalBenefit += $contribution;
        }

        $targetReqCount = $targetFramework->getRequirements()->count();
        $averageBenefit = $targetReqCount > 0 ? round($totalBenefit / $targetReqCount, 2) : 0;

        return [
            'source_framework' => $sourceFramework->getName(),
            'target_framework' => $targetFramework->getName(),
            'requirements_helped' => count($targetRequirementsHelped),
            'average_transitive_benefit' => $averageBenefit,
            'total_benefit' => round($totalBenefit, 2),
            'contributions' => $transitiveContributions,
        ];
    }

    /**
     * Calculate bidirectional framework coverage in both directions.
     *
     * Analyzes coverage from Framework1 → Framework2 AND Framework2 → Framework1
     * to provide comprehensive overlap analysis.
     *
     * @param ComplianceFramework $framework1 First framework
     * @param ComplianceFramework $framework2 Second framework
     * @return array{framework1_to_framework2: array, framework2_to_framework1: array, bidirectional_overlap: float, symmetric_coverage: float} Bidirectional coverage analysis
     */
    public function calculateBidirectionalCoverage(
        ComplianceFramework $framework1,
        ComplianceFramework $framework2
    ): array {
        // Coverage from Framework1 → Framework2
        $coverage1to2 = $this->calculateFrameworkCoverage($framework1, $framework2);

        // Coverage from Framework2 → Framework1 (reverse)
        $coverage2to1 = $this->calculateFrameworkCoverage($framework2, $framework1);

        // Calculate bidirectional overlap (weighted average)
        $bidirectionalOverlap = ($coverage1to2['coverage_percentage'] + $coverage2to1['coverage_percentage']) / 2;

        // Symmetric coverage (minimum of both directions = guaranteed mutual coverage)
        $symmetricCoverage = min($coverage1to2['coverage_percentage'], $coverage2to1['coverage_percentage']);

        return [
            'framework1_to_framework2' => $coverage1to2,
            'framework2_to_framework1' => $coverage2to1,
            'bidirectional_overlap' => round($bidirectionalOverlap, 2),
            'symmetric_coverage' => round($symmetricCoverage, 2),
        ];
    }

    /**
     * Calculate category-specific coverage between two frameworks.
     *
     * Analyzes coverage breakdown by requirement category (e.g., "Access Control", "Encryption")
     * to identify strong and weak areas in cross-framework mappings.
     *
     * @param ComplianceFramework $sourceFramework Source framework
     * @param ComplianceFramework $targetFramework Target framework
     * @return array<string, array{total: int, mapped: int, coverage: float, avg_quality: float, unmapped_requirements: array}> Category coverage statistics
     */
    public function calculateCategoryCoverage(
        ComplianceFramework $sourceFramework,
        ComplianceFramework $targetFramework
    ): array {
        $categoryStats = [];

        foreach ($sourceFramework->getRequirements() as $req) {
            $category = $req->getCategory() ?? 'Uncategorized';

            if (!isset($categoryStats[$category])) {
                $categoryStats[$category] = [
                    'total' => 0,
                    'mapped' => 0,
                    'coverage' => 0,
                    'avg_quality' => 0,
                    'quality_sum' => 0,
                    'unmapped_requirements' => [],
                ];
            }

            $categoryStats[$category]['total']++;

            // Find mapping to target framework
            $mapping = $this->findMappingBetweenRequirementAndFramework($req, $targetFramework);

            if ($mapping) {
                $categoryStats[$category]['mapped']++;
                $categoryStats[$category]['quality_sum'] += $mapping->getMappingPercentage();
            } else {
                $categoryStats[$category]['unmapped_requirements'][] = [
                    'id' => $req->getRequirementId(),
                    'title' => $req->getTitle(),
                    'priority' => $req->getPriority(),
                ];
            }
        }

        // Calculate percentages and averages
        foreach ($categoryStats as $cat => &$stats) {
            $stats['coverage'] = $stats['total'] > 0
                ? round(($stats['mapped'] / $stats['total']) * 100, 1)
                : 0;

            $stats['avg_quality'] = $stats['mapped'] > 0
                ? round($stats['quality_sum'] / $stats['mapped'], 1)
                : 0;

            // Remove quality_sum as it's just for calculation
            unset($stats['quality_sum']);
        }

        // Sort by coverage (lowest first to highlight problem areas)
        uasort($categoryStats, fn($a, $b) => $a['coverage'] <=> $b['coverage']);

        return $categoryStats;
    }

    /**
     * Find the best mapping between a requirement and any requirement in the target framework.
     *
     * @param ComplianceRequirement $requirement Source requirement
     * @param ComplianceFramework $targetFramework Target framework
     * @return ComplianceMapping|null Best mapping found, or null if no mapping exists
     */
    private function findMappingBetweenRequirementAndFramework(
        ComplianceRequirement $requirement,
        ComplianceFramework $targetFramework
    ): ?ComplianceMapping {
        // Check outbound mappings (where requirement is source)
        $outboundMappings = $this->createQueryBuilder('cm')
            ->join('cm.targetRequirement', 'tr')
            ->where('cm.sourceRequirement = :requirement')
            ->andWhere('tr.framework = :targetFramework')
            ->setParameter('requirement', $requirement)
            ->setParameter('targetFramework', $targetFramework)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (!empty($outboundMappings)) {
            return $outboundMappings[0];
        }

        // Check inbound mappings (where requirement is target)
        $inboundMappings = $this->createQueryBuilder('cm')
            ->join('cm.sourceRequirement', 'sr')
            ->where('cm.targetRequirement = :requirement')
            ->andWhere('sr.framework = :targetFramework')
            ->setParameter('requirement', $requirement)
            ->setParameter('targetFramework', $targetFramework)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return !empty($inboundMappings) ? $inboundMappings[0] : null;
    }

    /**
     * Find bidirectional mappings where requirements mutually satisfy each other.
     *
     * Bidirectional mappings indicate strong equivalence between requirements across frameworks,
     * enabling dual compliance with single implementation.
     *
     * @return ComplianceMapping[] Array of bidirectional mappings
     */
    public function findBidirectionalMappings(): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.bidirectional = :bidirectional')
            ->setParameter('bidirectional', true)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get comprehensive mapping statistics for system overview.
     *
     * @return array{total_mappings: int, full_mappings: int, exceeds_mappings: int, partial_mappings: int, bidirectional_mappings: int} Mapping statistics
     */
    public function getMappingStatistics(): array
    {
        $qb = $this->createQueryBuilder('cm');

        return [
            'total_mappings' => $qb->select('COUNT(cm.id)')
                ->getQuery()
                ->getSingleScalarResult(),

            'full_mappings' => $this->createQueryBuilder('cm')
                ->select('COUNT(cm.id)')
                ->where('cm.mappingType = :type')
                ->setParameter('type', 'full')
                ->getQuery()
                ->getSingleScalarResult(),

            'exceeds_mappings' => $this->createQueryBuilder('cm')
                ->select('COUNT(cm.id)')
                ->where('cm.mappingType = :type')
                ->setParameter('type', 'exceeds')
                ->getQuery()
                ->getSingleScalarResult(),

            'partial_mappings' => $this->createQueryBuilder('cm')
                ->select('COUNT(cm.id)')
                ->where('cm.mappingType = :type')
                ->setParameter('type', 'partial')
                ->getQuery()
                ->getSingleScalarResult(),

            'bidirectional_mappings' => $this->createQueryBuilder('cm')
                ->select('COUNT(cm.id)')
                ->where('cm.bidirectional = :bidirectional')
                ->setParameter('bidirectional', true)
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }
}
