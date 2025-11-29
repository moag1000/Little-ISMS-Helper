<?php

namespace App\Repository;

use App\Entity\ComplianceRequirementFulfillment;
use Deprecated;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceFramework;
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
     * Find all requirements for a specific compliance framework.
     *
     * @param ComplianceFramework $complianceFramework Compliance framework entity
     * @return ComplianceRequirement[] Array of requirements sorted by requirement ID
     */
    public function findByFramework(ComplianceFramework $complianceFramework): array
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.complianceFramework = :framework')
            ->setParameter('framework', $complianceFramework)
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
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
            ->where('cr.complianceFramework = :framework')
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
    public function findGapsByFramework(ComplianceFramework $complianceFramework, int $maxFulfillment = 75): array
    {
        // Note: fulfillment data is in ComplianceRequirementFulfillment (tenant-specific)
        // This query should be refactored to join with fulfillment table
        // For now, return all requirements sorted by priority
        return $this->createQueryBuilder('cr')
            ->where('cr.complianceFramework = :framework')
            ->setParameter('framework', $complianceFramework)
            ->orderBy('cr.priority', 'ASC')
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
        // Returning all requirements by framework and priority
        return $this->createQueryBuilder('cr')
            ->where('cr.complianceFramework = :framework')
            ->andWhere('cr.priority = :priority')
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
        $queryBuilder = $this->createQueryBuilder('cr');

        return [
            'total' => $queryBuilder->select('COUNT(cr.id)')
                ->where('cr.complianceFramework = :framework')
                ->setParameter('framework', $complianceFramework)
                ->getQuery()
                ->getSingleScalarResult(),

            'applicable' => $this->createQueryBuilder('cr')
                ->select('COUNT(DISTINCT cr.id)')
                ->leftJoin(ComplianceRequirementFulfillment::class, 'crf', 'WITH', 'crf.complianceRequirement = cr AND crf.tenant = :tenant')
                ->where('cr.complianceFramework = :framework')
                ->andWhere('crf.applicable = :applicable')
                ->setParameter('framework', $complianceFramework)
                ->setParameter('tenant', $tenant)
                ->setParameter('applicable', true)
                ->getQuery()
                ->getSingleScalarResult(),

            'fulfilled' => $this->createQueryBuilder('cr')
                ->select('COUNT(DISTINCT cr.id)')
                ->leftJoin(ComplianceRequirementFulfillment::class, 'crf', 'WITH', 'crf.complianceRequirement = cr AND crf.tenant = :tenant')
                ->where('cr.complianceFramework = :framework')
                ->andWhere('crf.applicable = :applicable')
                ->andWhere('crf.fulfillmentPercentage >= 100')
                ->setParameter('framework', $complianceFramework)
                ->setParameter('tenant', $tenant)
                ->setParameter('applicable', true)
                ->getQuery()
                ->getSingleScalarResult(),

            'critical_gaps' => $this->createQueryBuilder('cr')
                ->select('COUNT(DISTINCT cr.id)')
                ->leftJoin(ComplianceRequirementFulfillment::class, 'crf', 'WITH', 'crf.complianceRequirement = cr AND crf.tenant = :tenant')
                ->where('cr.complianceFramework = :framework')
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

        return [
            'total' => $queryBuilder->select('COUNT(cr.id)')
                ->where('cr.complianceFramework = :framework')
                ->setParameter('framework', $complianceFramework)
                ->getQuery()
                ->getSingleScalarResult(),

            // DEPRECATED: These counts don't reflect tenant-specific applicability
            // Returning total count for all metrics as fallback
            'applicable' => $queryBuilder->select('COUNT(cr.id)')
                ->where('cr.complianceFramework = :framework')
                ->setParameter('framework', $complianceFramework)
                ->getQuery()
                ->getSingleScalarResult(),

            'fulfilled' => 0, // Cannot determine without tenant-specific fulfillment data

            'critical_gaps' => $this->createQueryBuilder('cr')
                ->select('COUNT(cr.id)')
                ->where('cr.complianceFramework = :framework')
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
     * @return int Number of requirements with 100% fulfillment
     */
    public function countCompliant(): int
    {
        return (int) $this->createQueryBuilder('cr')
            ->select('COUNT(DISTINCT cr.id)')
            ->leftJoin(ComplianceRequirementFulfillment::class, 'crf', 'WITH', 'crf.complianceRequirement = cr')
            ->where('crf.applicable = :applicable')
            ->andWhere('crf.fulfillmentPercentage >= 100')
            ->setParameter('applicable', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
