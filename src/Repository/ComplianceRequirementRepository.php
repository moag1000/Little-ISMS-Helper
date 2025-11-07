<?php

namespace App\Repository;

use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceFramework;
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
     * @param ComplianceFramework $framework Compliance framework entity
     * @return ComplianceRequirement[] Array of requirements sorted by requirement ID
     */
    public function findByFramework(ComplianceFramework $framework): array
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.framework = :framework')
            ->setParameter('framework', $framework)
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find only applicable requirements for a framework (exclusions filtered out).
     *
     * @param ComplianceFramework $framework Compliance framework entity
     * @return ComplianceRequirement[] Array of applicable requirements sorted by requirement ID
     */
    public function findApplicableByFramework(ComplianceFramework $framework): array
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.framework = :framework')
            ->andWhere('cr.applicable = :applicable')
            ->setParameter('framework', $framework)
            ->setParameter('applicable', true)
            ->orderBy('cr.requirementId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find compliance gaps (low fulfillment percentage) for gap analysis reporting.
     *
     * @param ComplianceFramework $framework Compliance framework entity
     * @param int $maxFulfillment Maximum fulfillment percentage to consider as gap (default: 75%)
     * @return ComplianceRequirement[] Array of requirements sorted by priority then fulfillment
     */
    public function findGapsByFramework(ComplianceFramework $framework, int $maxFulfillment = 75): array
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.framework = :framework')
            ->andWhere('cr.applicable = :applicable')
            ->andWhere('cr.fulfillmentPercentage < :maxFulfillment')
            ->setParameter('framework', $framework)
            ->setParameter('applicable', true)
            ->setParameter('maxFulfillment', $maxFulfillment)
            ->orderBy('cr.priority', 'ASC')
            ->addOrderBy('cr.fulfillmentPercentage', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find applicable requirements by framework and priority level.
     *
     * @param ComplianceFramework $framework Compliance framework entity
     * @param string $priority Priority level ('critical', 'high', 'medium', 'low')
     * @return ComplianceRequirement[] Array of requirements sorted by fulfillment (lowest first)
     */
    public function findByFrameworkAndPriority(ComplianceFramework $framework, string $priority): array
    {
        return $this->createQueryBuilder('cr')
            ->where('cr.framework = :framework')
            ->andWhere('cr.priority = :priority')
            ->andWhere('cr.applicable = :applicable')
            ->setParameter('framework', $framework)
            ->setParameter('priority', $priority)
            ->setParameter('applicable', true)
            ->orderBy('cr.fulfillmentPercentage', 'ASC')
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
     * Get comprehensive compliance statistics for a framework.
     *
     * @param ComplianceFramework $framework Compliance framework entity
     * @return array<string, int> Statistics array containing:
     *   - total: Total requirements in framework
     *   - applicable: Count of applicable requirements
     *   - fulfilled: Count of fully fulfilled requirements (100%)
     *   - critical_gaps: Count of critical priority unfulfilled requirements
     */
    public function getFrameworkStatistics(ComplianceFramework $framework): array
    {
        $qb = $this->createQueryBuilder('cr');

        return [
            'total' => $qb->select('COUNT(cr.id)')
                ->where('cr.framework = :framework')
                ->setParameter('framework', $framework)
                ->getQuery()
                ->getSingleScalarResult(),

            'applicable' => $this->createQueryBuilder('cr')
                ->select('COUNT(cr.id)')
                ->where('cr.framework = :framework')
                ->andWhere('cr.applicable = :applicable')
                ->setParameter('framework', $framework)
                ->setParameter('applicable', true)
                ->getQuery()
                ->getSingleScalarResult(),

            'fulfilled' => $this->createQueryBuilder('cr')
                ->select('COUNT(cr.id)')
                ->where('cr.framework = :framework')
                ->andWhere('cr.applicable = :applicable')
                ->andWhere('cr.fulfillmentPercentage >= 100')
                ->setParameter('framework', $framework)
                ->setParameter('applicable', true)
                ->getQuery()
                ->getSingleScalarResult(),

            'critical_gaps' => $this->createQueryBuilder('cr')
                ->select('COUNT(cr.id)')
                ->where('cr.framework = :framework')
                ->andWhere('cr.applicable = :applicable')
                ->andWhere('cr.priority = :priority')
                ->andWhere('cr.fulfillmentPercentage < 100')
                ->setParameter('framework', $framework)
                ->setParameter('applicable', true)
                ->setParameter('priority', 'critical')
                ->getQuery()
                ->getSingleScalarResult(),
        ];
    }
}
