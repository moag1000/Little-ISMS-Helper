<?php

namespace App\Repository;

use DateTime;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProcessingActivity>
 */
class ProcessingActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProcessingActivity::class);
    }

    /**
     * Find all processing activities for a tenant
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('pa')
            ->where('pa.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('pa.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active processing activities for a tenant
     */
    public function findActiveByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('pa')
            ->where('pa.tenant = :tenant')
            ->andWhere('pa.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'active')
            ->orderBy('pa.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find processing activities that require DPIA
     */
    public function findRequiringDPIA(Tenant $tenant): array
    {
        return $this->createQueryBuilder('pa')
            ->where('pa.tenant = :tenant')
            ->andWhere('pa.isHighRisk = :highRisk OR pa.processesSpecialCategories = :special OR pa.hasAutomatedDecisionMaking = :automated')
            ->andWhere('pa.dpiaCompleted = :dpiaCompleted')
            ->setParameter('tenant', $tenant)
            ->setParameter('highRisk', true)
            ->setParameter('special', true)
            ->setParameter('automated', true)
            ->setParameter('dpiaCompleted', false)
            ->orderBy('pa.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find processing activities with third country transfers
     */
    public function findWithThirdCountryTransfers(Tenant $tenant): array
    {
        return $this->createQueryBuilder('pa')
            ->where('pa.tenant = :tenant')
            ->andWhere('pa.hasThirdCountryTransfer = :hasTransfer')
            ->setParameter('tenant', $tenant)
            ->setParameter('hasTransfer', true)
            ->orderBy('pa.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find processing activities processing special categories
     */
    public function findProcessingSpecialCategories(Tenant $tenant): array
    {
        return $this->createQueryBuilder('pa')
            ->where('pa.tenant = :tenant')
            ->andWhere('pa.processesSpecialCategories = :processesSpecial')
            ->setParameter('tenant', $tenant)
            ->setParameter('processesSpecial', true)
            ->orderBy('pa.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find incomplete processing activities (missing required fields)
     */
    public function findIncomplete(Tenant $tenant): array
    {
        $all = $this->findByTenant($tenant);

        return array_filter($all, fn(ProcessingActivity $processingActivity): bool => !$processingActivity->isComplete());
    }

    /**
     * Find processing activities due for review
     */
    public function findDueForReview(Tenant $tenant): array
    {
        $today = new DateTime();

        return $this->createQueryBuilder('pa')
            ->where('pa.tenant = :tenant')
            ->andWhere('pa.nextReviewDate IS NOT NULL')
            ->andWhere('pa.nextReviewDate <= :today')
            ->setParameter('tenant', $tenant)
            ->setParameter('today', $today)
            ->orderBy('pa.nextReviewDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics for tenant dashboard
     */
    public function getStatistics(Tenant $tenant): array
    {
        $queryBuilder = $this->createQueryBuilder('pa')
            ->where('pa.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $total = (clone $queryBuilder)->select('COUNT(pa.id)')->getQuery()->getSingleScalarResult();

        $active = (clone $queryBuilder)
            ->andWhere('pa.status = :status')
            ->setParameter('status', 'active')
            ->select('COUNT(pa.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $highRisk = (clone $queryBuilder)
            ->andWhere('pa.isHighRisk = :highRisk')
            ->setParameter('highRisk', true)
            ->select('COUNT(pa.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $specialCategories = (clone $queryBuilder)
            ->andWhere('pa.processesSpecialCategories = :special')
            ->setParameter('special', true)
            ->select('COUNT(pa.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $thirdCountryTransfers = (clone $queryBuilder)
            ->andWhere('pa.hasThirdCountryTransfer = :hasTransfer')
            ->setParameter('hasTransfer', true)
            ->select('COUNT(pa.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $missingDPIA = (clone $queryBuilder)
            ->andWhere('(pa.isHighRisk = :highRisk OR pa.processesSpecialCategories = :special OR pa.hasAutomatedDecisionMaking = :automated)')
            ->andWhere('pa.dpiaCompleted = :dpiaCompleted')
            ->setParameter('highRisk', true)
            ->setParameter('special', true)
            ->setParameter('automated', true)
            ->setParameter('dpiaCompleted', false)
            ->select('COUNT(pa.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'active' => $active,
            'high_risk' => $highRisk,
            'special_categories' => $specialCategories,
            'third_country_transfers' => $thirdCountryTransfers,
            'missing_dpia' => $missingDPIA,
        ];
    }

    /**
     * Search processing activities by name or description
     */
    public function search(Tenant $tenant, string $query): array
    {
        return $this->createQueryBuilder('pa')
            ->where('pa.tenant = :tenant')
            ->andWhere('pa.name LIKE :query OR pa.description LIKE :query')
            ->setParameter('tenant', $tenant)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('pa.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count by legal basis
     */
    public function countByLegalBasis(Tenant $tenant): array
    {
        $results = $this->createQueryBuilder('pa')
            ->select('pa.legalBasis, COUNT(pa.id) as count')
            ->where('pa.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('pa.legalBasis')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($results as $result) {
            $stats[$result['legalBasis'] ?? 'unknown'] = (int) $result['count'];
        }

        return $stats;
    }

    /**
     * Count by risk level
     */
    public function countByRiskLevel(Tenant $tenant): array
    {
        $results = $this->createQueryBuilder('pa')
            ->select('pa.riskLevel, COUNT(pa.id) as count')
            ->where('pa.tenant = :tenant')
            ->andWhere('pa.riskLevel IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->groupBy('pa.riskLevel')
            ->getQuery()
            ->getResult();

        $stats = [];
        foreach ($results as $result) {
            $stats[$result['riskLevel']] = (int) $result['count'];
        }

        return $stats;
    }
}
