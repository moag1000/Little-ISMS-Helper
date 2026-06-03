<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComplianceRequirementFulfillment;
use Deprecated;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceFramework;
use App\Entity\Document;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Compliance Requirement Repository
 *
 * Repository for querying ComplianceRequirement entities with gap analysis and fulfillment tracking.
 *
 * @extends ServiceEntityRepository<ComplianceRequirement>
 *
 * @method ComplianceRequirement|null find($id, $lockMode = null, $lockVersion = null)
 * @method ComplianceRequirement|null findOneBy(array $criteria, array $orderBy = null)
 * @method ComplianceRequirement[]    findAll()
 * @method ComplianceRequirement[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ComplianceRequirementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceRequirement::class);
    }

    /**
     * Find all ComplianceRequirements that have the given Document in their
     * evidenceDocuments collection. Used by DocumentController::show() to
     * build the reverse "linked requirements" panel.
     *
     * @return ComplianceRequirement[]
     */
    public function findByEvidenceDocument(Document $document): array
    {
        return $this->createQueryBuilder('cr')
            ->innerJoin('cr.evidenceDocuments', 'd')
            ->where('d = :document')
            ->setParameter('document', $document)
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find the tenant's assessed TISAX requirements for a framework, for the
     * audit-freeze snapshot (B2). Scopes to rows that belong to the tenant
     * (either tenant-uploaded via uploadTenant, or global system rows the tenant
     * shares) and that carry an assessment (maturityCurrent / assessmentStateDp /
     * maturityReviewedAt set). Sorted by requirementId for deterministic hashing.
     *
     * @return ComplianceRequirement[]
     */
    public function findTisaxAssessedByFrameworkAndTenant(
        ComplianceFramework $complianceFramework,
        Tenant $tenant,
    ): array {
        return $this->createQueryBuilder('cr')
            ->where('cr.framework = :framework')
            ->andWhere('(cr.uploadTenant = :tenant OR cr.uploadTenant IS NULL)')
            ->andWhere(
                // Parenthesise the OR-group: Doctrine does NOT auto-wrap a single
                // andWhere() argument, and SQL AND binds tighter than OR — without
                // these parens the DP/reviewedAt branches escape the framework AND
                // tenant scope and leak rows from other frameworks/tenants into the
                // signed freeze snapshot (tenant-isolation defect).
                '(cr.maturityCurrent IS NOT NULL '
                . 'OR cr.assessmentStateDp IS NOT NULL '
                . 'OR cr.maturityReviewedAt IS NOT NULL)'
            )
            ->setParameter('framework', $complianceFramework)
            ->setParameter('tenant', $tenant)
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all requirements for a specific compliance framework.
     *
     * @param ComplianceFramework $complianceFramework Compliance framework entity
     * @return ComplianceRequirement[] Array of requirements sorted by requirement ID
     */
    public function findByFramework(ComplianceFramework $complianceFramework): array
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.framework = :framework')
            ->setParameter('framework', $complianceFramework)
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find only the TOP-LEVEL requirements for a framework — i.e. the requirements
     * that constitute the framework's coverage/compliance denominator.
     *
     * Sub-requirements (`requirementType='sub_requirement'`, imported by the
     * EU-mapping decomposition) roll up via their parent and must NOT be counted
     * as separate denominator entries. A requirement is top-level when it has no
     * parent (`parentRequirement IS NULL`); the `requirementType IN ('core','detailed')`
     * predicate is added for belt-and-braces against any stray sub-requirement
     * that lost its parent link via the `ON DELETE SET NULL` join column.
     *
     * Use this in EVERY coverage / compliance-% computation and in requirement
     * LISTS. Detail / hierarchy views that legitimately show sub-requirements
     * keep using {@see findByFramework()}.
     *
     * @return ComplianceRequirement[] Array of top-level requirements sorted by requirement ID
     */
    public function findTopLevelByFramework(ComplianceFramework $complianceFramework): array
    {
        return $this->topLevelQueryBuilder($complianceFramework)
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * In-scope requirements for the requirement LIST of a single framework.
     *
     * Mirrors CoverageCheckService::getCatalogueCoverage scoping so the list and
     * the coverage % agree on what "in scope" means:
     *  - BYO / tenant-uploaded framework (TISAX VDA-ISA): the in-scope rows are
     *    the tenant's OWN uploaded controls, returned REGARDLESS of nesting —
     *    imported VDA-ISA controls (1.1.1 …) hang as children under the shared
     *    section skeleton (1.1 …), so the top-level filter would hide them.
     *  - Shared catalogue framework (ISO/NIS2/…): no per-tenant upload exists, so
     *    fall back to the framework's own top-level assessable requirements.
     * Both exclude non-catalogue rows ('section' headers, 'legacy_unmapped'
     * parked ad-hoc ids) — those are not requirements the user assesses and
     * previously polluted the list (the "TISAX 1.1" stubs + ACC-/INF- remnants).
     *
     * @return ComplianceRequirement[]
     */
    public function findInScopeForFrameworkListing(ComplianceFramework $complianceFramework, ?Tenant $tenant): array
    {
        $notJunk = "(cr.category IS NULL OR cr.category NOT IN ('section', 'legacy_unmapped'))";

        if ($tenant instanceof Tenant) {
            $uploaded = $this->createQueryBuilder('cr')
                ->where('cr.framework = :framework')
                ->andWhere('cr.uploadTenant = :tenant')
                ->andWhere($notJunk)
                ->setParameter('framework', $complianceFramework)
                ->setParameter('tenant', $tenant)
                ->orderBy('cr.requirementId', 'ASC')
                ->getQuery()
                ->getResult();
            if ($uploaded !== []) {
                return $uploaded;
            }
        }

        return $this->topLevelQueryBuilder($complianceFramework)
            ->andWhere($notJunk)
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find ALL top-level requirements across every framework (for the global
     * requirement index). Excludes imported sub-requirements which appear nested
     * under their parent on the detail view.
     *
     * @return ComplianceRequirement[]
     */
    public function findAllTopLevel(): array
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.parentRequirement IS NULL')
            ->andWhere("cr.requirementType IN ('core', 'detailed')")
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count the top-level requirements for a framework (coverage/compliance denominator).
     *
     * @see findTopLevelByFramework()
     */
    public function countTopLevelByFramework(ComplianceFramework $complianceFramework): int
    {
        return (int) $this->topLevelQueryBuilder($complianceFramework)
            ->select('COUNT(cr.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Shared predicate for the top-level filter — keeps the
     * `parentRequirement IS NULL` + `requirementType IN ('core','detailed')`
     * rule in exactly one place.
     */
    private function topLevelQueryBuilder(ComplianceFramework $complianceFramework): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.framework = :framework')
            ->andWhere('cr.parentRequirement IS NULL')
            ->andWhere("cr.requirementType IN ('core', 'detailed')")
            ->setParameter('framework', $complianceFramework);
    }

    /**
     * Find only applicable requirements for a framework (exclusions filtered out).
     *
     * @param ComplianceFramework $complianceFramework Compliance framework entity
     * @return ComplianceRequirement[] Array of applicable requirements sorted by requirement ID
     */
    public function findApplicableByFramework(ComplianceFramework $complianceFramework): array
    {
        // Note: 'applicable' is tracked in ComplianceRequirementFulfillment, not ComplianceRequirement
        // This method returns all requirements - applicability is tenant-specific
        return $this->createQueryBuilder('cr')
            ->where('cr.framework = :framework')
            ->setParameter('framework', $complianceFramework)
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find compliance gaps (low fulfillment percentage) for gap analysis reporting.
     *
     * @param ComplianceFramework $complianceFramework Compliance framework entity
     * @param int $maxFulfillment Maximum fulfillment percentage to consider as gap (default: 75%)
     * @return ComplianceRequirement[] Array of requirements sorted by priority then fulfillment
     */
    public function findGapsByFramework(ComplianceFramework $complianceFramework, int $maxFulfillment = 75, ?Tenant $tenant = null): array
    {
        $qb = $this->createQueryBuilder('cr')
            ->where('cr.framework = :framework')
            // Top-level gaps only — a sub-requirement gap is a detail row under
            // its parent gap, not a standalone gap-list entry / denominator.
            ->andWhere('cr.parentRequirement IS NULL')
            ->andWhere("cr.requirementType IN ('core', 'detailed')")
            ->setParameter('framework', $complianceFramework);

        if ($tenant !== null) {
            // LEFT JOIN: include requirements with no fulfillment record (= 0% fulfilled)
            $qb->leftJoin(
                    'App\Entity\ComplianceRequirementFulfillment',
                    'crf',
                    'ON',
                    'crf.requirement = cr AND crf.tenant = :tenant'
                )
                ->setParameter('tenant', $tenant)
                ->andWhere('crf.fulfillmentPercentage IS NULL OR crf.fulfillmentPercentage <= :maxFulfillment')
                ->setParameter('maxFulfillment', $maxFulfillment);
        }

        return $qb->orderBy('cr.priority', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find applicable requirements by framework and priority level.
     *
     * @param ComplianceFramework $complianceFramework Compliance framework entity
     * @param string $priority Priority level ('critical', 'high', 'medium', 'low')
     * @return ComplianceRequirement[] Array of requirements sorted by fulfillment (lowest first)
     */
    public function findByFrameworkAndPriority(ComplianceFramework $complianceFramework, string $priority): array
    {
        // Note: fulfillment is tenant-specific (ComplianceRequirementFulfillment)
        // Returning top-level requirements by framework and priority — used for
        // critical-gap counts/lists, which must not double-count sub-requirements.
        return $this->createQueryBuilder('cr')
            ->where('cr.framework = :framework')
            ->andWhere('cr.priority = :priority')
            ->andWhere('cr.parentRequirement IS NULL')
            ->andWhere("cr.requirementType IN ('core', 'detailed')")
            ->setParameter('framework', $complianceFramework)
            ->setParameter('priority', $priority)
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all requirements mapped to a specific ISO 27001 control.
     *
     * @param int $controlId Control identifier
     * @return ComplianceRequirement[] Array of mapped requirements
     */
    public function findByControl(int $controlId): array
    {
        return $this->createQueryBuilder('cr')
            ->join('cr.mappedControls', 'c')
            ->where('c.id = :controlId')
            ->setParameter('controlId', $controlId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get comprehensive statistics for a compliance framework (tenant-specific)
     *
     * Architecture: Tenant-aware statistics using ComplianceRequirementFulfillment
     * - JOINs to compliance_requirement_fulfillment table with tenant context
     * - Returns tenant-specific counts based on fulfillment data
     *
     * @param ComplianceFramework $complianceFramework The framework to get statistics for
     * @param Tenant $tenant The tenant to get statistics for
     * @return array<string, int> Statistics array with total, applicable, fulfilled, critical_gaps
     */
    public function getFrameworkStatisticsForTenant(ComplianceFramework $complianceFramework, Tenant $tenant): array
    {
        // Cast to int — Doctrine getSingleScalarResult() returns string for COUNT()
        // on MySQL/PDO. PHP 8.5 strict mode errors on string→int|float coercion.
        //
        // All counts are restricted to TOP-LEVEL requirements
        // (parentRequirement IS NULL AND requirementType IN ('core','detailed')).
        // The imported sub_requirements roll up via their parent and must NOT
        // inflate the denominator (total/applicable) — that would dilute the
        // compliance % shown on the dashboard ~25×.
        $topLevel = "cr.parentRequirement IS NULL AND cr.requirementType IN ('core', 'detailed')";

        return [
            'total' => (int) $this->createQueryBuilder('cr')
                ->select('COUNT(cr.id)')
                ->where('cr.framework = :framework')
                ->andWhere($topLevel)
                ->setParameter('framework', $complianceFramework)
                ->getQuery()
                ->getSingleScalarResult(),

            'applicable' => (int) $this->createQueryBuilder('cr')
                ->select('COUNT(DISTINCT cr.id)')
                ->leftJoin(ComplianceRequirementFulfillment::class, 'crf', 'ON', 'crf.requirement = cr AND crf.tenant = :tenant')
                ->where('cr.framework = :framework')
                ->andWhere($topLevel)
                ->andWhere('crf.applicable = :applicable')
                ->setParameter('framework', $complianceFramework)
                ->setParameter('tenant', $tenant)
                ->setParameter('applicable', true)
                ->getQuery()
                ->getSingleScalarResult(),

            'fulfilled' => (int) $this->createQueryBuilder('cr')
                ->select('COUNT(DISTINCT cr.id)')
                ->leftJoin(ComplianceRequirementFulfillment::class, 'crf', 'ON', 'crf.requirement = cr AND crf.tenant = :tenant')
                ->where('cr.framework = :framework')
                ->andWhere($topLevel)
                ->andWhere('crf.applicable = :applicable')
                ->andWhere('crf.fulfillmentPercentage >= 100')
                ->setParameter('framework', $complianceFramework)
                ->setParameter('tenant', $tenant)
                ->setParameter('applicable', true)
                ->getQuery()
                ->getSingleScalarResult(),

            'critical_gaps' => (int) $this->createQueryBuilder('cr')
                ->select('COUNT(DISTINCT cr.id)')
                ->leftJoin(ComplianceRequirementFulfillment::class, 'crf', 'ON', 'crf.requirement = cr AND crf.tenant = :tenant')
                ->where('cr.framework = :framework')
                ->andWhere($topLevel)
                ->andWhere('crf.applicable = :applicable')
                ->andWhere('cr.priority = :priority')
                ->andWhere('crf.fulfillmentPercentage < 100')
                ->setParameter('framework', $complianceFramework)
                ->setParameter('tenant', $tenant)
                ->setParameter('applicable', true)
                ->setParameter('priority', 'critical')
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }

    /**
     * Get comprehensive compliance statistics for a framework (DEPRECATED - uses global data)
     *
     *
     * @param ComplianceFramework $complianceFramework Compliance framework entity
     * @return array<string, int> Statistics array containing:
     *   - total: Total requirements in framework
     *   - applicable: Count of applicable requirements
     *   - fulfilled: Count of fully fulfilled requirements (100%)
     *   - critical_gaps: Count of critical priority unfulfilled requirements
     */
    #[Deprecated(message: <<<'TXT'
    Use getFrameworkStatisticsForTenant() instead for tenant-specific statistics
     This method uses deprecated global ComplianceRequirement fields instead of tenant-specific ComplianceRequirementFulfillment
    TXT)]
    public function getFrameworkStatistics(ComplianceFramework $complianceFramework): array
    {
        $queryBuilder = $this->createQueryBuilder('cr');
        // Top-level only — sub-requirements roll up via their parent.
        $topLevel = "cr.parentRequirement IS NULL AND cr.requirementType IN ('core', 'detailed')";

        return [
            'total' => $queryBuilder->select('COUNT(cr.id)')
                ->where('cr.framework = :framework')
                ->andWhere($topLevel)
                ->setParameter('framework', $complianceFramework)
                ->getQuery()
                ->getSingleScalarResult(),

            // DEPRECATED: These counts don't reflect tenant-specific applicability
            // Returning total count for all metrics as fallback
            'applicable' => $queryBuilder->select('COUNT(cr.id)')
                ->where('cr.framework = :framework')
                ->andWhere($topLevel)
                ->setParameter('framework', $complianceFramework)
                ->getQuery()
                ->getSingleScalarResult(),

            'fulfilled' => 0, // Cannot determine without tenant-specific fulfillment data

            'critical_gaps' => $this->createQueryBuilder('cr')
                ->select('COUNT(cr.id)')
                ->where('cr.framework = :framework')
                ->andWhere($topLevel)
                ->andWhere('cr.priority = :priority')
                ->setParameter('framework', $complianceFramework)
                ->setParameter('priority', 'critical')
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }

    /**
     * Count compliant requirements across all frameworks
     *
     * Used by scheduled compliance reporting
     *
     * @param Tenant|null $tenant When provided, count is scoped to that tenant's fulfillments
     * @return int Number of requirements with 100% fulfillment
     */
    public function countCompliant(?Tenant $tenant = null): int
    {
        $qb = $this->createQueryBuilder('cr')
            ->select('COUNT(DISTINCT cr.id)')
            ->leftJoin(ComplianceRequirementFulfillment::class, 'crf', 'ON', 'crf.requirement = cr')
            ->where('crf.applicable = :applicable')
            ->andWhere('crf.fulfillmentPercentage >= 100')
            // Top-level only — sub-requirements roll up via their parent.
            ->andWhere('cr.parentRequirement IS NULL')
            ->andWhere("cr.requirementType IN ('core', 'detailed')")
            ->setParameter('applicable', true);

        if ($tenant !== null) {
            $qb->andWhere('crf.tenant = :tenant')
                ->setParameter('tenant', $tenant);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
