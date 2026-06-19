<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Compliance Mapping Repository
 *
 * Repository for querying ComplianceMapping entities with cross-framework correlation and transitive compliance analysis.
 * Supports framework gap analysis, coverage calculation, and compliance reuse across different regulatory frameworks.
 *
 * TENANT-ISOLATION NOTE
 * ─────────────────────
 * ComplianceMapping has no direct tenant_id column. Tenant scoping is achieved via
 * the FK chain:  mapping → sourceRequirement.uploadTenant / targetRequirement.uploadTenant
 *
 * • ComplianceRequirement rows with uploadTenant = NULL are *global system rows*
 *   (shipped with the application). They are shared infrastructure and are visible
 *   to all tenants.
 * • ComplianceRequirement rows with uploadTenant set are *tenant-uploaded rows*
 *   (e.g. customer VDA-ISA workbooks). A mapping that touches such a row MUST only
 *   be visible to the owning tenant.
 *
 * Therefore:
 *   - Tenant UI listings: use findAllForTenant() / findRecentForTenant()
 *   - Admin / seed / quality operations: use findAllGlobal() and document why
 *   - NEVER call the inherited findAll() in production code — it leaks cross-tenant
 *     rows and is blocked by the override below.
 *
 * @extends ServiceEntityRepository<ComplianceMapping>
 *
 * @method ComplianceMapping|null find($id, $lockMode = null, $lockVersion = null)
 * @method ComplianceMapping|null findOneBy(array $criteria, array $orderBy = null)
 * @method ComplianceMapping[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ComplianceMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceMapping::class);
    }

    /**
     * BLOCKED — use findAllForTenant() or findAllGlobal() instead.
     *
     * This override intentionally throws to catch future misuse early. The
     * inherited ServiceEntityRepository::findAll() returns every row in the
     * table across all tenants; calling it from tenant-UI code is a data-leak.
     *
     * @throws \LogicException always
     * @return never
     */
    public function findAll(): array
    {
        throw new \LogicException(
            'ComplianceMappingRepository::findAll() is blocked — it leaks rows across tenants. '
            . 'For tenant UI listings use findAllForTenant($tenant). '
            . 'For admin/seed/quality operations use findAllGlobal() and document the reason.'
        );
    }

    /**
     * Return all mappings visible to the given tenant:
     *   (a) mappings where BOTH requirements are global (uploadTenant IS NULL), OR
     *   (b) mappings where at least one requirement belongs to this tenant.
     *
     * This is the correct default for tenant-facing controller actions.
     *
     * @return ComplianceMapping[]
     */
    public function findAllForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('cm')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->where(
                '(sr.uploadTenant IS NULL AND tr.uploadTenant IS NULL) '
                . 'OR sr.uploadTenant = :tenant '
                . 'OR tr.uploadTenant = :tenant'
            )
            ->setParameter('tenant', $tenant)
            ->orderBy('cm.updatedAt', 'DESC')
            ->addOrderBy('cm.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Return the N most-recently updated mappings visible to the given tenant.
     * Used by the Mapping-Hub "Last 5" KPI strip.
     *
     * @return ComplianceMapping[]
     */
    public function findRecentForTenant(Tenant $tenant, int $limit = 5): array
    {
        return $this->createQueryBuilder('cm')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->where(
                '(sr.uploadTenant IS NULL AND tr.uploadTenant IS NULL) '
                . 'OR sr.uploadTenant = :tenant '
                . 'OR tr.uploadTenant = :tenant'
            )
            ->setParameter('tenant', $tenant)
            ->orderBy('cm.updatedAt', 'DESC')
            ->addOrderBy('cm.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Return ALL mappings without tenant filtering.
     *
     * ONLY for admin / seed / quality-pipeline operations that legitimately
     * operate on the full dataset (e.g. duplicate-pair detection during
     * automated seeding, MQS backfill, sanity-check commands, review-queue
     * dashboards). MUST NOT be used in tenant-facing controller actions.
     *
     * @return ComplianceMapping[]
     */
    public function findAllGlobal(): array
    {
        return parent::findAll();
    }

    /**
     * Typed accessor for the ComplianceRequirement repository — used by the
     * coverage calculators below to read the top-level requirement count
     * (the coverage/compliance denominator). Wrapped so PHPStan resolves the
     * concrete repository methods rather than the generic EntityRepository.
     */
    private function requirementRepository(): ComplianceRequirementRepository
    {
        /** @var ComplianceRequirementRepository $repo */
        $repo = $this->getEntityManager()->getRepository(ComplianceRequirement::class);

        return $repo;
    }

    /**
     * Restrict a mapping query to OPERATIONAL lifecycle states
     * (approved + published — see {@see ComplianceMapping::OPERATIONAL_STATES}).
     *
     * Coverage % and inheritance suggestions must ONLY consider operational
     * mappings — unreviewed `draft`/`review` and retired `deprecated` rows
     * must NOT contribute. The ~7000 decomposition mappings land as `draft`
     * and would otherwise inflate coverage before any human review.
     *
     * Apply this to every coverage / inheritance query. Do NOT apply it to
     * admin / listing / quality views (mapping-quality dashboard, review
     * queue) which legitimately surface drafts.
     */
    public function applyOperationalStateFilter(QueryBuilder $qb, string $alias = 'cm'): QueryBuilder
    {
        return $qb
            ->andWhere(sprintf('%s.lifecycleState IN (:operationalStates)', $alias))
            ->setParameter('operationalStates', ComplianceMapping::OPERATIONAL_STATES);
    }

    /**
     * Find all mappings for a specific source requirement.
     *
     * NOT operational-state-filtered: this powers the mapping MANAGEMENT index
     * (`app_compliance_mapping_index`) which legitimately lists drafts. For the
     * coverage/inheritance outbound walk use {@see findMappingsFromRequirement()}.
     *
     * @param ComplianceRequirement $requirement Source requirement
     * @return ComplianceMapping[] Array of mappings
     */
    public function findBySourceRequirement(ComplianceRequirement $requirement): array
    {
        return $this->createQueryBuilder('cm')
            ->where('cm.sourceRequirement = :requirement')
            ->setParameter('requirement', $requirement)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all mappings where the given requirement is the source (outbound mappings).
     *
     * @param ComplianceRequirement $complianceRequirement Source requirement entity
     * @return ComplianceMapping[] Array of mappings sorted by strength (strongest first)
     */
    public function findMappingsFromRequirement(ComplianceRequirement $complianceRequirement): array
    {
        $qb = $this->createQueryBuilder('cm')
            ->where('cm.sourceRequirement = :requirement')
            ->setParameter('requirement', $complianceRequirement)
            ->orderBy('cm.mappingPercentage', 'DESC');
        $this->applyOperationalStateFilter($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Find all mappings where the given requirement is the target (inbound mappings).
     *
     * @param ComplianceRequirement $complianceRequirement Target requirement entity
     * @return ComplianceMapping[] Array of mappings sorted by strength (strongest first)
     */
    public function findMappingsToRequirement(ComplianceRequirement $complianceRequirement): array
    {
        $qb = $this->createQueryBuilder('cm')
            ->where('cm.targetRequirement = :requirement')
            ->setParameter('requirement', $complianceRequirement)
            ->orderBy('cm.mappingPercentage', 'DESC');
        $this->applyOperationalStateFilter($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Batch-load outbound mappings for a list of source requirements in a
     * single query. Eager-fetches the target requirement + its framework so
     * downstream code can read framework metadata without further round-trips.
     *
     * Returns rows grouped by source-requirement-id for O(1) lookup in the
     * caller's loop — kills the N+1 query risk in
     * {@see \App\Service\Audit\CrossFrameworkCoverageService} when an audit
     * has many findings with many linked requirements.
     *
     * @param list<ComplianceRequirement> $requirements
     * @return array<int, list<ComplianceMapping>> keyed by source requirement id
     */
    public function findMappingsBySourceRequirements(array $requirements): array
    {
        if ($requirements === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('cm')
            ->select('cm', 'tr', 'tf')
            ->leftJoin('cm.targetRequirement', 'tr')
            ->leftJoin('tr.framework', 'tf')
            ->where('cm.sourceRequirement IN (:requirements)')
            ->setParameter('requirements', $requirements)
            ->orderBy('cm.mappingPercentage', 'DESC');
        $this->applyOperationalStateFilter($qb);
        $mappings = $qb->getQuery()->getResult();

        $byId = [];
        foreach ($mappings as $mapping) {
            $source = $mapping->getSourceRequirement();
            if ($source === null) {
                continue;
            }
            $byId[(int) $source->getId()][] = $mapping;
        }
        return $byId;
    }

    /**
     * Batch counterpart of {@see findMappingsToRequirement()} — for the inbound
     * (bidirectional) edge walk inside the cross-framework coverage service.
     *
     * @param list<ComplianceRequirement> $requirements
     * @return array<int, list<ComplianceMapping>> keyed by target requirement id
     */
    public function findMappingsByTargetRequirements(array $requirements): array
    {
        if ($requirements === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('cm')
            ->select('cm', 'sr', 'sf')
            ->leftJoin('cm.sourceRequirement', 'sr')
            ->leftJoin('sr.framework', 'sf')
            ->where('cm.targetRequirement IN (:requirements)')
            ->setParameter('requirements', $requirements)
            ->orderBy('cm.mappingPercentage', 'DESC');
        $this->applyOperationalStateFilter($qb);
        $mappings = $qb->getQuery()->getResult();

        $byId = [];
        foreach ($mappings as $mapping) {
            $target = $mapping->getTargetRequirement();
            if ($target === null) {
                continue;
            }
            $byId[(int) $target->getId()][] = $mapping;
        }
        return $byId;
    }

    /**
     * Find all cross-framework mappings between two compliance frameworks.
     *
     * @param ComplianceFramework $sourceFramework Source framework (e.g., ISO 27001)
     * @param ComplianceFramework $targetFramework Target framework (e.g., SOC 2, GDPR)
     * @return ComplianceMapping[] Array of mappings sorted by strength (strongest first)
     */
    /**
     * List source frameworks that have at least one mapping pointing into the
     * given target framework. Used by the wizard-start UI to surface an
     * "import existing answers" hint without running a full inheritance pass.
     *
     * @return ComplianceFramework[] sorted by mapping count (most → least)
     */
    public function findSourceFrameworksMappingTo(ComplianceFramework $targetFramework): array
    {
        $qb = $this->createQueryBuilder('cm')
            ->select('IDENTITY(sr.framework) AS framework_id, COUNT(cm.id) AS mapping_count')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->where('tr.framework = :target')
            ->andWhere('sr.framework != :target')
            ->setParameter('target', $targetFramework)
            ->groupBy('sr.framework')
            ->orderBy('mapping_count', 'DESC');
        $this->applyOperationalStateFilter($qb);
        $rows = $qb->getQuery()->getResult();

        if ($rows === []) {
            return [];
        }

        $frameworkIds = array_map(static fn(array $r): int => (int) $r['framework_id'], $rows);
        $frameworks = $this->getEntityManager()
            ->getRepository(ComplianceFramework::class)
            ->createQueryBuilder('f')
            ->where('f.id IN (:ids)')
            ->andWhere('f.active = :active')
            ->setParameter('ids', $frameworkIds)
            ->setParameter('active', true)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($frameworks as $framework) {
            $byId[$framework->getId()] = $framework;
        }

        $ordered = [];
        foreach ($frameworkIds as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function findCrossFrameworkMappings(
        ComplianceFramework $sourceFramework,
        ComplianceFramework $targetFramework
    ): array {
        $qb = $this->createQueryBuilder('cm')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->where('sr.framework = :sourceFramework')
            ->andWhere('tr.framework = :targetFramework')
            ->setParameter('sourceFramework', $sourceFramework)
            ->setParameter('targetFramework', $targetFramework)
            ->orderBy('cm.mappingPercentage', 'DESC');
        $this->applyOperationalStateFilter($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Bulk-load ALL cross-framework mappings for a given set of frameworks in ONE query.
     *
     * Returns a two-level map: [sourceFrameworkId][targetFrameworkId] => ComplianceMapping[].
     * This eliminates the N×N per-pair queries that make the transitive-compliance
     * page take >10 s with many active frameworks.
     *
     * Usage:
     *   $allMappings = $repo->findAllCrossFrameworkMappingsBulk($frameworks);
     *   $pairMappings = $allMappings[$source->getId()][$target->getId()] ?? [];
     *
     * @param ComplianceFramework[] $frameworks
     * @return array<int, array<int, ComplianceMapping[]>>
     */
    public function findAllCrossFrameworkMappingsBulk(array $frameworks): array
    {
        if ($frameworks === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('cm')
            ->addSelect('sr', 'tr', 'sf', 'tf')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->join('sr.framework', 'sf')
            ->join('tr.framework', 'tf')
            ->where('sf IN (:frameworks)')
            ->andWhere('tf IN (:frameworks)')
            ->andWhere('sf != tf')
            ->setParameter('frameworks', $frameworks)
            ->orderBy('cm.mappingPercentage', 'DESC');
        $this->applyOperationalStateFilter($qb);
        $mappings = $qb->getQuery()->getResult();

        $result = [];
        foreach ($mappings as $mapping) {
            $sfId = $mapping->getSourceRequirement()->getFramework()->getId();
            $tfId = $mapping->getTargetRequirement()->getFramework()->getId();
            $result[$sfId][$tfId][] = $mapping;
        }

        return $result;
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
        $qb = $this->createQueryBuilder('cm')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->where('sr.framework = :sourceFramework')
            ->andWhere('tr.framework = :targetFramework')
            ->andWhere('cm.mappingPercentage >= 100')
            ->setParameter('sourceFramework', $sourceFramework)
            ->setParameter('targetFramework', $targetFramework)
            ->orderBy('cm.mappingPercentage', 'DESC');
        $this->applyOperationalStateFilter($qb);

        return $qb->getQuery()->getResult();
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
        // Denominator = top-level requirements only. Sub-requirements roll up
        // via their parent and must NOT dilute the coverage %.
        $targetRequirements = $this->requirementRepository()->countTopLevelByFramework($targetFramework);

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

        // Denominator = top-level requirements only (sub-requirements roll up).
        $targetReqCount = $this->requirementRepository()->countTopLevelByFramework($targetFramework);
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

        // Iterate top-level requirements only — sub-requirements roll up via
        // their parent and must not be counted as separate category entries.
        $sourceRequirements = $this->requirementRepository()->findTopLevelByFramework($sourceFramework);

        foreach ($sourceRequirements as $requirement) {
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
            $categoryStat['coverage'] = (int) $categoryStat['total'] > 0
                ? round(((int) $categoryStat['mapped'] / (int) $categoryStat['total']) * 100, 1)
                : 0;

            $categoryStat['avg_quality'] = (int) $categoryStat['mapped'] > 0
                ? round((float) $categoryStat['quality_sum'] / (int) $categoryStat['mapped'], 1)
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
        $qbOut = $this->createQueryBuilder('cm')
            ->join('cm.targetRequirement', 'tr')
            ->where('cm.sourceRequirement = :requirement')
            ->andWhere('tr.framework = :targetFramework')
            ->setParameter('requirement', $complianceRequirement)
            ->setParameter('targetFramework', $complianceFramework)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->setMaxResults(1);
        $this->applyOperationalStateFilter($qbOut);
        $outboundMappings = $qbOut->getQuery()->getResult();

        if (!empty($outboundMappings)) {
            return $outboundMappings[0];
        }

        // Check inbound mappings (where requirement is target)
        $qbIn = $this->createQueryBuilder('cm')
            ->join('cm.sourceRequirement', 'sr')
            ->where('cm.targetRequirement = :requirement')
            ->andWhere('sr.framework = :targetFramework')
            ->setParameter('requirement', $complianceRequirement)
            ->setParameter('targetFramework', $complianceFramework)
            ->orderBy('cm.mappingPercentage', 'DESC')
            ->setMaxResults(1);
        $this->applyOperationalStateFilter($qbIn);
        $inboundMappings = $qbIn->getQuery()->getResult();

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
     * Find all mappings where $requirement appears as either source or target.
     *
     * Outbound (source) rows: always returned.
     * Inbound (target) rows: only returned when the mapping is flagged bidirectional=true.
     *
     * Deduplication by mapping ID prevents double-counting self-referencing rows.
     *
     * @param string|null $otherFrameworkCode Optional filter — only return rows where the
     *                                        "other" requirement belongs to this framework.
     * @return ComplianceMapping[]
     */
    public function findByEitherSourceOrTarget(
        ComplianceRequirement $requirement,
        ?string $otherFrameworkCode = null,
    ): array {
        // Outbound: $requirement is source
        $qbOut = $this->createQueryBuilder('cm')
            ->innerJoin('cm.targetRequirement', 'tr')
            ->innerJoin('tr.framework', 'tf')
            ->where('cm.sourceRequirement = :req')
            ->setParameter('req', $requirement);
        $this->applyOperationalStateFilter($qbOut);

        if ($otherFrameworkCode !== null) {
            $qbOut->andWhere('tf.code = :fwCode')
                  ->setParameter('fwCode', $otherFrameworkCode);
        }

        // Inbound: $requirement is target, mapping must be bidirectional
        $qbIn = $this->createQueryBuilder('cm2')
            ->innerJoin('cm2.sourceRequirement', 'sr')
            ->innerJoin('sr.framework', 'sf')
            ->where('cm2.targetRequirement = :req2')
            ->andWhere('cm2.bidirectional = :bi')
            ->setParameter('req2', $requirement)
            ->setParameter('bi', true);
        $this->applyOperationalStateFilter($qbIn, 'cm2');

        if ($otherFrameworkCode !== null) {
            $qbIn->andWhere('sf.code = :fwCode2')
                 ->setParameter('fwCode2', $otherFrameworkCode);
        }

        /** @var ComplianceMapping[] $outbound */
        $outbound = $qbOut->getQuery()->getResult();
        /** @var ComplianceMapping[] $inbound */
        $inbound = $qbIn->getQuery()->getResult();

        // Deduplicate by mapping ID
        $seen   = [];
        $result = [];
        foreach (array_merge($outbound, $inbound) as $mapping) {
            $id = $mapping->getId();
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $result[]  = $mapping;
            }
        }

        return $result;
    }

    /**
     * Find bidirectional mappings where requirements mutually satisfy each other.
     *
     * Bidirectional mappings indicate strong equivalence between requirements across frameworks,
     * enabling dual compliance with single implementation.
     *
     * CROSS-TENANT — for admin/quality use only. See class docblock for guidance.
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
     * CROSS-TENANT aggregate — acceptable for hub KPI strip (counts are global
     * infrastructure, not tenant-private data). See class docblock.
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
        // Get requirement counts (top-level only — sub-requirements roll up)
        $reqRepo = $this->requirementRepository();
        $sourceReqCount = $reqRepo->countTopLevelByFramework($sourceFramework);
        $targetReqCount = $reqRepo->countTopLevelByFramework($targetFramework);
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
        $reqRepo = $this->requirementRepository();
        $sourceReqCount = $reqRepo->countTopLevelByFramework($sourceFramework);
        $targetReqCount = $reqRepo->countTopLevelByFramework($targetFramework);

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
            $categoryPattern['avg_fulfillment'] = (int) $categoryPattern['count'] > 0
                ? round((float) $categoryPattern['total_fulfillment'] / (int) $categoryPattern['count'], 1)
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
     * Find mappings that require manual review — CROSS-TENANT, quality/admin use only.
     *
     * Returns rows across all tenants without filtering. Call only from quality
     * dashboards (MappingQualityController) or CLI commands. Do NOT call from
     * tenant-facing controllers.
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
     * Find mappings with low confidence — CROSS-TENANT, quality/admin use only.
     * See class docblock for tenant-isolation guidance.
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
     * Find mappings with low quality score — CROSS-TENANT, quality/admin use only.
     * See class docblock for tenant-isolation guidance.
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
     * Find mappings with manual overrides — CROSS-TENANT, quality/admin use only.
     * See class docblock for tenant-isolation guidance.
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
     * Get quality statistics for all mappings.
     *
     * CROSS-TENANT aggregate — for quality dashboards and admin use only.
     * See class docblock for tenant-isolation guidance.
     */
    public function getQualityStatistics(): array
    {
        $queryBuilder = $this->createQueryBuilder('cm');

        $totalMappings = $queryBuilder->select('COUNT(cm.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // "Analyzed" via the text-similarity heuristic (legacy column).
        $analyzedMappings = $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->where('cm.calculatedPercentage IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();

        // "Scored" = has EITHER a heuristic percentage OR an MQS quality score.
        // Metadata-rich decomposition mappings get an authoritative MQS at
        // import/backfill time and must NOT be treated as "waiting for analysis"
        // just because the slow text-similarity column is still NULL.
        $scoredMappings = $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->where('cm.calculatedPercentage IS NOT NULL OR cm.qualityScore IS NOT NULL')
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
            'scored_mappings' => (int) $scoredMappings,
            // "Waiting for analysis" = genuinely metadata-poor: lacks BOTH the
            // heuristic percentage AND an MQS quality score.
            'unanalyzed_mappings' => (int) ($totalMappings - $scoredMappings),
            'analysis_coverage' => (int) $totalMappings > 0 ? round(((int) $scoredMappings / (int) $totalMappings) * 100, 1) : 0,
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
     * Add the "genuinely metadata-poor" filter to a query builder: mappings
     * that have NEITHER a heuristic calculatedPercentage NOR an MQS qualityScore.
     * These are the only rows the slow text-similarity batch should target.
     */
    public function applyMetadataPoorFilter(QueryBuilder $qb, string $alias = 'cm'): QueryBuilder
    {
        return $qb->andWhere(sprintf('%s.calculatedPercentage IS NULL AND %s.qualityScore IS NULL', $alias, $alias));
    }

    /**
     * Count mappings that still require the text-similarity heuristic, i.e. they
     * lack BOTH a calculatedPercentage and an MQS qualityScore.
     */
    public function countMetadataPoorUnscored(): int
    {
        $qb = $this->createQueryBuilder('cm')->select('COUNT(cm.id)');
        $this->applyMetadataPoorFilter($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Find mappings eligible for MQS backfill: authoritative metadata present
     * (provenanceUrl + an explicit confidence) but no MQS qualityScore yet.
     *
     * @return list<ComplianceMapping>
     */
    public function findMqsBackfillCandidates(?int $limit = null, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('cm')
            ->where('cm.qualityScore IS NULL')
            ->andWhere('cm.provenanceUrl IS NOT NULL')
            ->andWhere("cm.provenanceUrl <> ''")
            ->andWhere("cm.confidence IN ('high', 'medium', 'low')")
            ->orderBy('cm.id', 'ASC')
            ->setFirstResult($offset);

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        /** @var list<ComplianceMapping> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Count MQS-backfill candidates (see findMqsBackfillCandidates()).
     */
    public function countMqsBackfillCandidates(): int
    {
        return (int) $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->where('cm.qualityScore IS NULL')
            ->andWhere('cm.provenanceUrl IS NOT NULL')
            ->andWhere("cm.provenanceUrl <> ''")
            ->andWhere("cm.confidence IN ('high', 'medium', 'low')")
            ->getQuery()
            ->getSingleScalarResult();
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
    /**
     * Coverage zwischen zwei Frameworks: wieviele Source-Items haben ≥1 Mapping
     * zum Target-Framework?
     *
     * @return array{source_total: int, source_with_mapping: int, target_total: int, target_with_mapping: int}
     */
    public function coverageBetweenFrameworks(ComplianceFramework $source, ComplianceFramework $target): array
    {
        $em = $this->getEntityManager();

        $sourceTotal = (int) $em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ComplianceRequirement::class, 'r')
            ->where('r.framework = :fw')
            ->setParameter('fw', $source)
            ->getQuery()->getSingleScalarResult();

        $targetTotal = (int) $em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ComplianceRequirement::class, 'r')
            ->where('r.framework = :fw')
            ->setParameter('fw', $target)
            ->getQuery()->getSingleScalarResult();

        $sourceWithMapping = (int) $this->createQueryBuilder('cm')
            ->select('COUNT(DISTINCT s.id)')
            ->join('cm.sourceRequirement', 's')
            ->join('cm.targetRequirement', 't')
            ->where('s.framework = :sFw AND t.framework = :tFw')
            ->andWhere("cm.lifecycleState != 'deprecated'")
            ->setParameter('sFw', $source)
            ->setParameter('tFw', $target)
            ->getQuery()->getSingleScalarResult();

        $targetWithMapping = (int) $this->createQueryBuilder('cm')
            ->select('COUNT(DISTINCT t.id)')
            ->join('cm.sourceRequirement', 's')
            ->join('cm.targetRequirement', 't')
            ->where('s.framework = :sFw AND t.framework = :tFw')
            ->andWhere("cm.lifecycleState != 'deprecated'")
            ->setParameter('sFw', $source)
            ->setParameter('tFw', $target)
            ->getQuery()->getSingleScalarResult();

        return [
            'source_total' => $sourceTotal,
            'source_with_mapping' => $sourceWithMapping,
            'target_total' => $targetTotal,
            'target_with_mapping' => $targetWithMapping,
        ];
    }

    /**
     * Reciprocity-Coherence — Anteil der Mappings A→B die ein passendes
     * Pendant B→A haben (target→source). Wert 0..1, 1 = perfekt reziprok.
     */
    public function reciprocityCoherence(ComplianceFramework $source, ComplianceFramework $target): float
    {
        $forward = $this->createQueryBuilder('cm')
            ->select('IDENTITY(cm.sourceRequirement) AS s_id, IDENTITY(cm.targetRequirement) AS t_id')
            ->join('cm.sourceRequirement', 's')
            ->join('cm.targetRequirement', 't')
            ->where('s.framework = :sFw AND t.framework = :tFw')
            ->andWhere("cm.lifecycleState != 'deprecated'")
            ->setParameter('sFw', $source)
            ->setParameter('tFw', $target)
            ->getQuery()->getArrayResult();

        if (empty($forward)) {
            return 0.0;
        }

        $reverse = $this->createQueryBuilder('cm')
            ->select('IDENTITY(cm.sourceRequirement) AS s_id, IDENTITY(cm.targetRequirement) AS t_id')
            ->join('cm.sourceRequirement', 's')
            ->join('cm.targetRequirement', 't')
            ->where('s.framework = :tFw AND t.framework = :sFw')
            ->andWhere("cm.lifecycleState != 'deprecated'")
            ->setParameter('sFw', $source)
            ->setParameter('tFw', $target)
            ->getQuery()->getArrayResult();

        $reverseSet = [];
        foreach ($reverse as $row) {
            $reverseSet[$row['t_id'] . '_' . $row['s_id']] = true;  // (orig source, orig target) reversed
        }

        $matched = 0;
        foreach ($forward as $row) {
            if (isset($reverseSet[$row['s_id'] . '_' . $row['t_id']])) {
                $matched++;
            }
        }
        return $matched / count($forward);
    }

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

    /**
     * Count mappings visible to the given tenant.
     *
     * Mirrors the visibility rule from {@see findAllForTenant()}:
     *   (a) both requirements are global (uploadTenant IS NULL), OR
     *   (b) at least one requirement belongs to this tenant.
     *
     * Used by the mapping-onboarding workflow to snapshot a baseline count at
     * workflow start so a "new load during the workflow" can be detected as a
     * count increase.
     */
    public function countByTenant(Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('cm')
            ->select('COUNT(cm.id)')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->where(
                '(sr.uploadTenant IS NULL AND tr.uploadTenant IS NULL) '
                . 'OR sr.uploadTenant = :tenant '
                . 'OR tr.uploadTenant = :tenant'
            )
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function latestReviewedAtForTenant(Tenant $tenant): ?\DateTimeInterface
    {
        $v = $this->createQueryBuilder('cm')
            ->select('MAX(cm.reviewedAt)')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->where('(sr.uploadTenant IS NULL AND tr.uploadTenant IS NULL) OR sr.uploadTenant = :tenant OR tr.uploadTenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()->getSingleScalarResult();

        return $v ? new \DateTimeImmutable((string) $v) : null;
    }

    public function latestCreatedAtForTenant(Tenant $tenant): ?\DateTimeInterface
    {
        $v = $this->createQueryBuilder('cm')
            ->select('MAX(cm.createdAt)')
            ->join('cm.sourceRequirement', 'sr')
            ->join('cm.targetRequirement', 'tr')
            ->where('(sr.uploadTenant IS NULL AND tr.uploadTenant IS NULL) OR sr.uploadTenant = :tenant OR tr.uploadTenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()->getSingleScalarResult();

        return $v ? new \DateTimeImmutable((string) $v) : null;
    }
}
