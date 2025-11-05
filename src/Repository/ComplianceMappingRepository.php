<?php

namespace App\Repository;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceFramework;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComplianceMapping>
 */
class ComplianceMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceMapping::class);
    }

    /**
     * Find all mappings where the given requirement is the source
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
     * Find all mappings where the given requirement is the target
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
     * Find cross-framework mappings between two frameworks
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
     * Find strong mappings (full or exceeds) between frameworks
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
     * Calculate coverage: how much of target framework is covered by source framework
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
     * Get transitive compliance - how fulfilling source framework helps target framework
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
     * Find bidirectional mappings (requirements that mutually satisfy each other)
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
     * Get mapping statistics
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
