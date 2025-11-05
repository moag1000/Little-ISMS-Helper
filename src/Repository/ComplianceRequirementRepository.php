<?php

namespace App\Repository;

use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceFramework;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComplianceRequirement>
 */
class ComplianceRequirementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComplianceRequirement::class);
    }

    /**
     * Find requirements by framework
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
     * Find applicable requirements by framework
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
     * Find gaps (low fulfillment) for a framework
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
     * Find requirements by priority
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
     * Find requirements mapped to a specific control
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
     * Get statistics for a framework
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
