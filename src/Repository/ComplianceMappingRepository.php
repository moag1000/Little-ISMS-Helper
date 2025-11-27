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
     * @param ComplianceRequirement $complianceRequirement Source requirement entity
     * @return ComplianceMapping[] Array of mappings sorted by strength (strongest first)
     */
    public function findMappingsFromRequirement(ComplianceRequirement $complianceRequirement): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.sourceRequirement = :requirement')
            ->setParameter('requirement', $complianceRequirement)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all mappings where the given requirement is the target (inbound mappings).
     *
     * @param ComplianceRequirement $complianceRequirement Target requirement entity
     * @return ComplianceMapping[] Array of mappings sorted by strength (strongest first)
     */
    public function findMappingsToRequirement(ComplianceRequirement $complianceRequirement): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.targetRequirement = :requirement')
            ->setParameter('requirement', $complianceRequirement)
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

        foreach ($coveredRequirements as $coveredRequirement) {
            $totalCoveragePercentage += min(100, $coveredRequirement); // Cap at 100% per requirement
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
            'strong_mappings' => count(array_filter($coveredRequirements, fn(int $c): bool => $c >= 100)),
            'partial_mappings' => count(array_filter($coveredRequirements, fn(int $c): bool => $c >= 50 && $c < 100)),
            'weak_mappings' => count(array_filter($coveredRequirements, fn(int $c): bool => $c < 50)),
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

        foreach ($targetRequirementsHelped as $targetRequirementHelped) {
            $totalBenefit += $targetRequirementHelped;
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

        foreach ($sourceFramework->getRequirements() as $requirement) {
            $category = $requirement->getCategory() ?? 'Uncategorized';

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
            $mapping = $this->findMappingBetweenRequirementAndFramework($requirement, $targetFramework);

            if ($mapping instanceof ComplianceMapping) {
                $categoryStats[$category]['mapped']++;
                $categoryStats[$category]['quality_sum'] += $mapping->getMappingPercentage();
            } else {
                $categoryStats[$category]['unmapped_requirements'][] = [
                    'id' => $requirement->getRequirementId(),
                    'title' => $requirement->getTitle(),
                    'priority' => $requirement->getPriority(),
                ];
            }
        }

        // Calculate percentages and averages
        foreach ($categoryStats as &$categoryStat) {
            $categoryStat['coverage'] = $categoryStat['total'] > 0
                ? round(($categoryStat['mapped'] / $categoryStat['total']) * 100, 1)
                : 0;

            $categoryStat['avg_quality'] = $categoryStat['mapped'] > 0
                ? round($categoryStat['quality_sum'] / $categoryStat['mapped'], 1)
                : 0;

            // Remove quality_sum as it's just for calculation
            unset($categoryStat['quality_sum']);
        }

        // Sort by coverage (lowest first to highlight problem areas)
        uasort($categoryStats, fn($a, $b): int => $a['coverage'] <=> $b['coverage']);

        return $categoryStats;
    }

    /**
     * Find the best mapping between a requirement and any requirement in the target framework.
     *
     * @param ComplianceRequirement $complianceRequirement Source requirement
     * @param ComplianceFramework $complianceFramework Target framework
     * @return ComplianceMapping|null Best mapping found, or null if no mapping exists
     */
    private function findMappingBetweenRequirementAndFramework(
        ComplianceRequirement $complianceRequirement,
        ComplianceFramework $complianceFramework
    ): ?ComplianceMapping {
        // Check outbound mappings (where requirement is source)
        $outboundMappings = $this->createQueryBuilder('cm')
            ->join('cm.targetRequirement', 'tr')
            ->where('cm.sourceRequirement = :requirement')
            ->andWhere('tr.framework = :targetFramework')
            ->setParameter('requirement', $complianceRequirement)
            ->setParameter('targetFramework', $complianceFramework)
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
            ->setParameter('requirement', $complianceRequirement)
            ->setParameter('targetFramework', $complianceFramework)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        return empty($inboundMappings) ? null : $inboundMappings[0];
    }

    /**
     * Calculate priority-weighted gap analysis for a framework.
     *
     * Weights gaps by requirement priority to focus on high-impact compliance issues.
     * Priority weights: critical=4.0, high=2.0, medium=1.0, low=0.5
     *
     * @param ComplianceFramework $complianceFramework Framework to analyze
     * @param array $gaps Array of gap requirements (unfulfilled requirements)
     * @return array{weighted_gap_score: float, total_weight: float, risk_score: int, uncovered_critical: array, uncovered_high: array, priority_distribution: array, recommendations: array} Priority-weighted gap analysis
     */
    public function calculatePriorityWeightedGaps(
        ComplianceFramework $complianceFramework,
        array $gaps
    ): array {
        $priorityWeights = [
            'critical' => 4.0,
            'high' => 2.0,
            'medium' => 1.0,
            'low' => 0.5,
        ];

        $weightedGapScore = 0;
        $totalWeight = 0;
        $uncoveredCritical = [];
        $uncoveredHigh = [];
        $priorityDistribution = [
            'critical' => 0,
            'high' => 0,
            'medium' => 0,
            'low' => 0,
        ];

        foreach ($gaps as $gap) {
            $priority = $gap->getPriority() ?? 'medium';
            $weight = $priorityWeights[$priority] ?? 1.0;

            $totalWeight += $weight;
            $weightedGapScore += $weight;

            $priorityDistribution[$priority]++;

            if ($priority === 'critical') {
                $uncoveredCritical[] = [
                    'id' => $gap->getRequirementId(),
                    'title' => $gap->getTitle(),
                    'category' => $gap->getCategory(),
                    'description' => $gap->getDescription(),
                    'fulfillment' => $gap->getFulfillmentPercentage() ?? 0,
                ];
            } elseif ($priority === 'high') {
                $uncoveredHigh[] = [
                    'id' => $gap->getRequirementId(),
                    'title' => $gap->getTitle(),
                    'category' => $gap->getCategory(),
                    'fulfillment' => $gap->getFulfillmentPercentage() ?? 0,
                ];
            }
        }

        // Calculate risk score (critical gaps * 10 + high gaps * 5)
        $riskScore = (count($uncoveredCritical) * 10) + (count($uncoveredHigh) * 5);

        // Generate recommendations based on risk analysis
        $recommendations = [];

        if (count($uncoveredCritical) > 0) {
            $recommendations[] = [
                'priority' => 'CRITICAL',
                'action' => 'Sofortmaßnahmen für ' . count($uncoveredCritical) . ' kritische Gaps erforderlich',
                'timeline' => '0-30 Tage',
                'risk_reduction' => count($uncoveredCritical) * 10,
            ];
        }

        if (count($uncoveredHigh) > 0) {
            $recommendations[] = [
                'priority' => 'HIGH',
                'action' => count($uncoveredHigh) . ' hohe Gaps innerhalb 90 Tagen schließen',
                'timeline' => '30-90 Tage',
                'risk_reduction' => count($uncoveredHigh) * 5,
            ];
        }

        if ($priorityDistribution['medium'] > 0) {
            $recommendations[] = [
                'priority' => 'MEDIUM',
                'action' => 'Mittelfristige Roadmap für ' . $priorityDistribution['medium'] . ' mittlere Gaps',
                'timeline' => '3-6 Monate',
                'risk_reduction' => $priorityDistribution['medium'] * 2,
            ];
        }

        return [
            'weighted_gap_score' => round($weightedGapScore, 2),
            'total_weight' => round($totalWeight, 2),
            'risk_score' => $riskScore,
            'uncovered_critical' => $uncoveredCritical,
            'uncovered_high' => $uncoveredHigh,
            'priority_distribution' => $priorityDistribution,
            'recommendations' => $recommendations,
        ];
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
        $queryBuilder = $this->createQueryBuilder('cm');

        return [
            'total_mappings' => $queryBuilder->select('COUNT(cm.id)')
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

    /**
     * Calculate impact score for a framework relationship.
     *
     * Impact score quantifies the business value of a framework relationship based on:
     * - Coverage percentage (how well frameworks overlap)
     * - Number of requirements (scope of potential reuse)
     * - Framework priority (critical frameworks weighted higher)
     *
     * Higher impact scores indicate more valuable compliance leverage opportunities.
     *
     * @param ComplianceFramework $sourceFramework Source framework
     * @param ComplianceFramework $targetFramework Target framework
     * @param float $coveragePercentage Coverage percentage between frameworks
     * @return array{impact_score: float, roi: float, is_quick_win: bool, effort_estimate: int, factors: array} Impact analysis
     */
    public function calculateFrameworkImpactScore(
        ComplianceFramework $sourceFramework,
        ComplianceFramework $targetFramework,
        float $coveragePercentage
    ): array {
        // Get requirement counts
        $sourceReqCount = count($sourceFramework->getRequirements());
        $targetReqCount = count($targetFramework->getRequirements());
        $avgReqCount = ($sourceReqCount + $targetReqCount) / 2;

        // Priority multiplier based on framework importance
        $priorityMultiplier = $this->getFrameworkPriorityMultiplier($sourceFramework);

        // Calculate base impact score
        // Formula: Coverage × Avg_Requirements × Priority_Multiplier / 100
        $impactScore = ($coveragePercentage * $avgReqCount * $priorityMultiplier) / 100;

        // Estimate effort to improve/maintain mappings
        $effortEstimate = $this->estimateMappingEffort($sourceFramework, $targetFramework, $coveragePercentage);

        // Calculate ROI (Return on Investment): Impact per unit effort
        $roi = $effortEstimate > 0 ? round($impactScore / $effortEstimate, 2) : 0;

        // Quick Win Detection:
        // - High ROI (>= 3.0 = high impact, low effort)
        // - Good coverage (>= 60%)
        $isQuickWin = $roi >= 3.0 && $coveragePercentage >= 60;

        return [
            'impact_score' => round($impactScore, 1),
            'roi' => $roi,
            'is_quick_win' => $isQuickWin,
            'effort_estimate' => $effortEstimate,
            'factors' => [
                'coverage' => $coveragePercentage,
                'avg_requirements' => round($avgReqCount, 0),
                'priority_multiplier' => $priorityMultiplier,
            ],
        ];
    }

    /**
     * Estimate effort required to improve/maintain framework mappings.
     *
     * Effort is estimated in person-hours based on:
     * - Number of requirements to map
     * - Current coverage level (lower coverage = more work)
     * - Complexity of the frameworks involved
     *
     * @param ComplianceFramework $sourceFramework Source framework
     * @param ComplianceFramework $targetFramework Target framework
     * @param float $currentCoverage Current coverage percentage
     * @return int Estimated effort in person-hours
     */
    private function estimateMappingEffort(
        ComplianceFramework $sourceFramework,
        ComplianceFramework $targetFramework,
        float $currentCoverage
    ): int {
        $sourceReqCount = count($sourceFramework->getRequirements());
        $targetReqCount = count($targetFramework->getRequirements());

        // Base effort: 0.5 hours per requirement for initial mapping
        $baseEffortPerReq = 0.5;

        // Calculate number of unmapped requirements
        $unmappedCount = (int) round(($sourceReqCount * (100 - $currentCoverage)) / 100);

        // Base effort for unmapped requirements
        $baseEffort = $unmappedCount * $baseEffortPerReq;

        // Complexity multiplier based on target framework size
        // Larger frameworks are harder to map to (more options to consider)
        if ($targetReqCount > 200) {
            $complexityMultiplier = 1.5; // Large framework (e.g., ISO 27001)
        } elseif ($targetReqCount > 100) {
            $complexityMultiplier = 1.2; // Medium framework
        } else {
            $complexityMultiplier = 1.0; // Small framework
        }

        // Add maintenance overhead (20% of base effort)
        $maintenanceOverhead = 0.2;

        $totalEffort = $baseEffort * $complexityMultiplier * (1 + $maintenanceOverhead);

        // Minimum 1 hour, maximum 200 hours (for sanity)
        return max(1, min(200, (int) round($totalEffort)));
    }

    /**
     * Get priority multiplier for a framework based on its importance.
     *
     * More critical/widely-used frameworks get higher multipliers to reflect
     * their higher business value.
     *
     * @param ComplianceFramework $complianceFramework The framework
     * @return float Priority multiplier (1.0 - 2.0)
     */
    private function getFrameworkPriorityMultiplier(ComplianceFramework $complianceFramework): float
    {
        $code = $complianceFramework->getCode();

        // Critical frameworks (legal requirements, industry standards)
        if (in_array($code, ['GDPR', 'ISO27001', 'SOC2', 'HIPAA', 'PCI-DSS'], true)) {
            return 2.0; // High priority
        }

        // Important frameworks (best practices, certifications)
        if (in_array($code, ['NIST', 'CIS', 'COBIT', 'ITIL'], true)) {
            return 1.5; // Medium-high priority
        }

        // Standard frameworks
        return 1.0; // Normal priority
    }

    /**
     * Analyze root causes of gaps to understand WHY compliance issues exist.
     *
     * Root cause analysis helps identify:
     * - Missing controls (not implemented)
     * - Missing evidence (control exists but not documented)
     * - Incomplete implementation (partially done)
     * - Resource constraints
     * - Dependency blockers
     *
     * This enables targeted remediation strategies.
     *
     * @param array $gaps Array of gap requirements (unfulfilled requirements)
     * @return array{root_causes: array, category_patterns: array, recommendations: array, dependency_blockers: array} Root cause analysis
     */
    public function analyzeGapRootCauses(array $gaps): array
    {
        $rootCauses = [
            'missing_control' => [],        // Control not implemented at all
            'missing_evidence' => [],       // Control exists but lacks documentation
            'incomplete_implementation' => [], // Partially implemented (>0% but <80%)
            'not_started' => [],            // Not begun (0% fulfillment)
            'low_priority' => [],           // Deprioritized (low priority + low fulfillment)
        ];

        $categoryPatterns = [];

        foreach ($gaps as $gap) {
            $fulfillment = $gap->getFulfillmentPercentage() ?? 0;
            $priority = $gap->getPriority() ?? 'medium';
            $category = $gap->getCategory() ?? 'Uncategorized';

            // Track category patterns
            if (!isset($categoryPatterns[$category])) {
                $categoryPatterns[$category] = [
                    'count' => 0,
                    'avg_fulfillment' => 0,
                    'total_fulfillment' => 0,
                    'dominant_root_cause' => null,
                    'gap_ids' => [],
                ];
            }
            $categoryPatterns[$category]['count']++;
            $categoryPatterns[$category]['total_fulfillment'] += $fulfillment;
            $categoryPatterns[$category]['gap_ids'][] = $gap->getRequirementId();

            $gapInfo = [
                'id' => $gap->getRequirementId(),
                'title' => $gap->getTitle(),
                'category' => $category,
                'priority' => $priority,
                'fulfillment' => $fulfillment,
            ];

            // Classify root cause based on fulfillment level
            if ($fulfillment === 0) {
                $rootCauses['not_started'][] = $gapInfo;
            } elseif ($fulfillment > 0 && $fulfillment < 30) {
                // Very low fulfillment suggests missing control
                $rootCauses['missing_control'][] = $gapInfo;
            } elseif ($fulfillment >= 30 && $fulfillment < 80) {
                // Medium fulfillment suggests incomplete implementation
                $rootCauses['incomplete_implementation'][] = $gapInfo;
            } elseif ($fulfillment >= 80 && $fulfillment < 100) {
                // High fulfillment but not complete suggests missing evidence
                $rootCauses['missing_evidence'][] = $gapInfo;
            }

            // Check for low-priority deprioritization
            if (in_array($priority, ['low', 'medium'], true) && $fulfillment < 50) {
                $rootCauses['low_priority'][] = $gapInfo;
            }
        }

        // Calculate category patterns
        foreach ($categoryPatterns as &$categoryPattern) {
            $categoryPattern['avg_fulfillment'] = $categoryPattern['count'] > 0
                ? round($categoryPattern['total_fulfillment'] / $categoryPattern['count'], 1)
                : 0;

            // Determine dominant root cause for category
            if ($categoryPattern['avg_fulfillment'] === 0) {
                $categoryPattern['dominant_root_cause'] = 'not_started';
            } elseif ($categoryPattern['avg_fulfillment'] < 30) {
                $categoryPattern['dominant_root_cause'] = 'missing_control';
            } elseif ($categoryPattern['avg_fulfillment'] < 80) {
                $categoryPattern['dominant_root_cause'] = 'incomplete_implementation';
            } else {
                $categoryPattern['dominant_root_cause'] = 'missing_evidence';
            }

            unset($categoryPattern['total_fulfillment']); // Remove helper field
        }

        // Sort categories by gap count (most problematic first)
        uasort($categoryPatterns, fn($a, $b): int => $b['count'] <=> $a['count']);

        // Generate targeted recommendations based on root causes
        $recommendations = $this->generateRootCauseRecommendations($rootCauses, $categoryPatterns);

        return [
            'root_causes' => $rootCauses,
            'category_patterns' => $categoryPatterns,
            'recommendations' => $recommendations,
            'summary' => [
                'not_started_count' => count($rootCauses['not_started']),
                'missing_control_count' => count($rootCauses['missing_control']),
                'incomplete_implementation_count' => count($rootCauses['incomplete_implementation']),
                'missing_evidence_count' => count($rootCauses['missing_evidence']),
                'low_priority_count' => count($rootCauses['low_priority']),
                'total_categories_affected' => count($categoryPatterns),
            ],
        ];
    }

    /**
     * Generate targeted recommendations based on root cause analysis.
     *
     * @param array $rootCauses Classified root causes
     * @param array $categoryPatterns Category-level patterns
     * @return array Actionable recommendations
     */
    private function generateRootCauseRecommendations(array $rootCauses, array $categoryPatterns): array
    {
        $recommendations = [];

        // Recommendation for not-started gaps
        if (count($rootCauses['not_started']) > 0) {
            $recommendations[] = [
                'root_cause' => 'not_started',
                'priority' => 'CRITICAL',
                'count' => count($rootCauses['not_started']),
                'title' => 'Kickstart-Initiative für nicht begonnene Anforderungen',
                'action' => sprintf(
                    '%d Anforderungen (0%% Erfüllung) wurden noch nicht begonnen. Sofortige Ressourcen-Allokation erforderlich.',
                    count($rootCauses['not_started'])
                ),
                'solution' => 'Projekt-Kickoff, Verantwortliche zuweisen, Initiale Assessments durchführen',
                'timeline' => '0-30 Tage',
                'effort' => 'Hoch',
            ];
        }

        // Recommendation for missing controls
        if (count($rootCauses['missing_control']) > 0) {
            $recommendations[] = [
                'root_cause' => 'missing_control',
                'priority' => 'HIGH',
                'count' => count($rootCauses['missing_control']),
                'title' => 'Kontroll-Implementierung priorisieren',
                'action' => sprintf(
                    '%d Anforderungen haben sehr niedrige Erfüllung (<30%%). Kontrollen fehlen oder sind unzureichend.',
                    count($rootCauses['missing_control'])
                ),
                'solution' => 'Control Design Workshop, Policy/Prozedur-Entwicklung, Technische Implementierung',
                'timeline' => '30-90 Tage',
                'effort' => 'Hoch',
            ];
        }

        // Recommendation for incomplete implementation
        if (count($rootCauses['incomplete_implementation']) > 0) {
            $recommendations[] = [
                'root_cause' => 'incomplete_implementation',
                'priority' => 'MEDIUM',
                'count' => count($rootCauses['incomplete_implementation']),
                'title' => 'Unvollständige Implementierungen abschließen',
                'action' => sprintf(
                    '%d Anforderungen sind teilweise umgesetzt (30-80%%). Lücken schließen und finalisieren.',
                    count($rootCauses['incomplete_implementation'])
                ),
                'solution' => 'Gap-Assessment pro Requirement, fehlende Komponenten identifizieren, Completion Roadmap',
                'timeline' => '60-120 Tage',
                'effort' => 'Mittel',
            ];
        }

        // Recommendation for missing evidence
        if (count($rootCauses['missing_evidence']) > 0) {
            $recommendations[] = [
                'root_cause' => 'missing_evidence',
                'priority' => 'MEDIUM',
                'count' => count($rootCauses['missing_evidence']),
                'title' => 'Evidenz-Sammlung & Dokumentation',
                'action' => sprintf(
                    '%d Anforderungen haben hohe Erfüllung (80-99%%) aber fehlende Dokumentation/Nachweise.',
                    count($rootCauses['missing_evidence'])
                ),
                'solution' => 'Dokumentations-Sprint, Evidenz-Sammlung, Audit-Trail vervollständigen',
                'timeline' => '30-60 Tage',
                'effort' => 'Niedrig',
            ];
        }

        // Category-specific recommendations (top 3 problematic categories)
        $topCategories = array_slice($categoryPatterns, 0, 3, true);
        foreach ($topCategories as $category => $pattern) {
            if ($pattern['count'] >= 3) { // Only if significant (3+ gaps)
                $recommendations[] = [
                    'root_cause' => 'category_cluster',
                    'priority' => 'HIGH',
                    'count' => $pattern['count'],
                    'title' => sprintf('Kategorie-spezifische Initiative: %s', $category),
                    'action' => sprintf(
                        'Kategorie "%s" hat %d Gaps (Ø %s%% Erfüllung). Systematisches Problem erkannt.',
                        $category,
                        $pattern['count'],
                        $pattern['avg_fulfillment']
                    ),
                    'solution' => sprintf(
                        'Dedicated Task Force für "%s", Root Cause: %s',
                        $category,
                        $this->translateRootCause($pattern['dominant_root_cause'])
                    ),
                    'timeline' => '30-90 Tage',
                    'effort' => 'Mittel-Hoch',
                ];
            }
        }

        return $recommendations;
    }

    /**
     * Translate root cause key to German description.
     *
     * @param string $rootCause Root cause key
     * @return string German description
     */
    private function translateRootCause(string $rootCause): string
    {
        $translations = [
            'not_started' => 'Noch nicht begonnen',
            'missing_control' => 'Fehlende Kontrolle',
            'incomplete_implementation' => 'Unvollständige Umsetzung',
            'missing_evidence' => 'Fehlende Evidenz',
            'low_priority' => 'Niedrige Priorität',
            'category_cluster' => 'Kategorie-Cluster',
        ];

        return $translations[$rootCause] ?? $rootCause;
    }

    /**
     * Find mappings that require manual review
     *
     * @return ComplianceMapping[]
     */
    public function findMappingsRequiringReview(): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.requiresReview = :requiresReview')
            ->andWhere('cm.reviewStatus = :status')
            ->setParameter('requiresReview', true)
            ->setParameter('status', 'unreviewed')
            ->orderBy('cm.analysisConfidence', 'ASC')
            ->addOrderBy('cm.qualityScore', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find mappings with low confidence
     *
     * @return ComplianceMapping[]
     */
    public function findLowConfidenceMappings(int $confidenceThreshold = 60): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.analysisConfidence < :threshold')
            ->setParameter('threshold', $confidenceThreshold)
            ->orderBy('cm.analysisConfidence', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find mappings with low quality score
     *
     * @return ComplianceMapping[]
     */
    public function findLowQualityMappings(int $qualityThreshold = 50): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.qualityScore < :threshold')
            ->setParameter('threshold', $qualityThreshold)
            ->orderBy('cm.qualityScore', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find mappings with manual overrides
     *
     * @return ComplianceMapping[]
     */
    public function findMappingsWithManualOverride(): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.manualPercentage IS NOT NULL')
            ->orderBy('cm.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get quality statistics for all mappings
     */
    public function getQualityStatistics(): array
    {
        $queryBuilder = $this->createQueryBuilder('cm');

        $totalMappings = $queryBuilder->select('COUNT(cm.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $analyzedMappings = $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->where('cm.calculatedPercentage IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $highConfidence = $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->where('cm.analysisConfidence >= 80')
            ->getQuery()
            ->getSingleScalarResult();

        $mediumConfidence = $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->where('cm.analysisConfidence >= 60 AND cm.analysisConfidence < 80')
            ->getQuery()
            ->getSingleScalarResult();

        $lowConfidence = $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->where('cm.analysisConfidence < 60')
            ->getQuery()
            ->getSingleScalarResult();

        $requiresReview = $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->where('cm.requiresReview = :requiresReview')
            ->andWhere('cm.reviewStatus = :status')
            ->setParameter('requiresReview', true)
            ->setParameter('status', 'unreviewed')
            ->getQuery()
            ->getSingleScalarResult();

        $withGaps = $this->createQueryBuilder('cm')
            ->select('COUNT(DISTINCT cm.id)')
            ->join('cm.gapItems', 'gi')
            ->where('gi.status NOT IN (:resolvedStatuses)')
            ->setParameter('resolvedStatuses', ['resolved', 'wont_fix'])
            ->getQuery()
            ->getSingleScalarResult();

        $avgQualityScore = $this->createQueryBuilder('cm')
            ->select('AVG(cm.qualityScore)')
            ->where('cm.qualityScore IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        $avgConfidence = $this->createQueryBuilder('cm')
            ->select('AVG(cm.analysisConfidence)')
            ->where('cm.analysisConfidence IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total_mappings' => (int) $totalMappings,
            'analyzed_mappings' => (int) $analyzedMappings,
            'unanalyzed_mappings' => (int) ($totalMappings - $analyzedMappings),
            'analysis_coverage' => $totalMappings > 0 ? round(($analyzedMappings / $totalMappings) * 100, 1) : 0,
            'high_confidence' => (int) $highConfidence,
            'medium_confidence' => (int) $mediumConfidence,
            'low_confidence' => (int) $lowConfidence,
            'requires_review' => (int) $requiresReview,
            'with_unresolved_gaps' => (int) $withGaps,
            'avg_quality_score' => round((float) $avgQualityScore, 1),
            'avg_confidence' => round((float) $avgConfidence, 1),
        ];
    }

    /**
     * Get quality distribution by confidence levels
     */
    public function getQualityDistribution(): array
    {
        return $this->createQueryBuilder('cm')
            ->select('
                CASE
                    WHEN cm.analysisConfidence >= 80 THEN \'high\'
                    WHEN cm.analysisConfidence >= 60 THEN \'medium\'
                    ELSE \'low\'
                END as confidence_level,
                COUNT(cm.id) as count,
                AVG(cm.calculatedPercentage) as avg_percentage,
                AVG(cm.qualityScore) as avg_quality
            ')
            ->where('cm.analysisConfidence IS NOT NULL')
            ->groupBy('confidence_level')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find mappings with significant discrepancies between calculated and original percentage
     *
     * @param int $discrepancyThreshold Minimum percentage point difference
     * @return ComplianceMapping[]
     */
    public function findMappingsWithDiscrepancies(int $discrepancyThreshold = 20): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.calculatedPercentage IS NOT NULL')
            ->andWhere('ABS(cm.calculatedPercentage - cm.mappingPercentage) >= :threshold')
            ->setParameter('threshold', $discrepancyThreshold)
            ->orderBy('ABS(cm.calculatedPercentage - cm.mappingPercentage)', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get top quality mappings for a framework
     *
     * @return ComplianceMapping[]
     */
    public function getTopQualityMappings(
        ComplianceFramework $complianceFramework,
        int $limit = 10
    ): array {
        return $this->createQueryBuilder('cm')
            ->join('cm.sourceRequirement', 'sr')
            ->where('sr.framework = :framework')
            ->andWhere('cm.qualityScore IS NOT NULL')
            ->setParameter('framework', $complianceFramework)
            ->orderBy('cm.qualityScore', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get framework quality comparison
     */
    public function getFrameworkQualityComparison(): array
    {
        return $this->createQueryBuilder('cm')
            ->select('
                sf.code as source_framework,
                tf.code as target_framework,
                COUNT(cm.id) as mapping_count,
                AVG(cm.calculatedPercentage) as avg_percentage,
                AVG(cm.qualityScore) as avg_quality,
                AVG(cm.analysisConfidence) as avg_confidence,
                SUM(CASE WHEN cm.requiresReview = 1 THEN 1 ELSE 0 END) as review_count
            ')
            ->join('cm.sourceRequirement', 'sr')
            ->join('sr.framework', 'sf')
            ->join('cm.targetRequirement', 'tr')
            ->join('tr.framework', 'tf')
            ->where('cm.calculatedPercentage IS NOT NULL')
            ->groupBy('sf.code, tf.code')
            ->orderBy('avg_quality', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get mappings by review status
     *
     * @return ComplianceMapping[]
     */
    public function findByReviewStatus(string $status): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.reviewStatus = :status')
            ->setParameter('status', $status)
            ->orderBy('cm.qualityScore', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get similarity distribution statistics
     */
    public function getSimilarityDistribution(): array
    {
        return $this->createQueryBuilder('cm')
            ->select('
                CASE
                    WHEN cm.textualSimilarity >= 0.8 THEN \'very_high\'
                    WHEN cm.textualSimilarity >= 0.6 THEN \'high\'
                    WHEN cm.textualSimilarity >= 0.4 THEN \'medium\'
                    WHEN cm.textualSimilarity >= 0.2 THEN \'low\'
                    ELSE \'very_low\'
                END as similarity_level,
                COUNT(cm.id) as count
            ')
            ->where('cm.textualSimilarity IS NOT NULL')
            ->groupBy('similarity_level')
            ->getQuery()
            ->getResult();
    }
}
