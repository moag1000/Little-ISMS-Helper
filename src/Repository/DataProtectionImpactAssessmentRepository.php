<?php

namespace App\Repository;

use DateTime;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataProtectionImpactAssessment>
 */
class DataProtectionImpactAssessmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataProtectionImpactAssessment::class);
    }

    /**
     * Find all DPIAs for a tenant
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('dpia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find DPIAs by status
     */
    public function findByStatus(Tenant $tenant, string $status): array
    {
        return $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->andWhere('dpia.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', $status)
            ->orderBy('dpia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find draft DPIAs
     */
    public function findDrafts(Tenant $tenant): array
    {
        return $this->findByStatus($tenant, 'draft');
    }

    /**
     * Find DPIAs in review
     */
    public function findInReview(Tenant $tenant): array
    {
        return $this->findByStatus($tenant, 'in_review');
    }

    /**
     * Find approved DPIAs
     */
    public function findApproved(Tenant $tenant): array
    {
        return $this->findByStatus($tenant, 'approved');
    }

    /**
     * Find DPIAs requiring revision
     */
    public function findRequiringRevision(Tenant $tenant): array
    {
        return $this->findByStatus($tenant, 'requires_revision');
    }

    /**
     * Find DPIAs by risk level
     */
    public function findByRiskLevel(Tenant $tenant, string $riskLevel): array
    {
        return $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->andWhere('dpia.riskLevel = :riskLevel')
            ->setParameter('tenant', $tenant)
            ->setParameter('riskLevel', $riskLevel)
            ->orderBy('dpia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find high-risk DPIAs (high or critical)
     */
    public function findHighRisk(Tenant $tenant): array
    {
        return $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->andWhere('dpia.riskLevel IN (:riskLevels)')
            ->setParameter('tenant', $tenant)
            ->setParameter('riskLevels', ['high', 'critical'])
            ->orderBy('dpia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find DPIAs with unacceptable residual risk
     */
    public function findWithUnacceptableResidualRisk(Tenant $tenant): array
    {
        return $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->andWhere('dpia.residualRiskLevel IN (:riskLevels)')
            ->setParameter('tenant', $tenant)
            ->setParameter('riskLevels', ['high', 'critical'])
            ->orderBy('dpia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find DPIAs requiring supervisory authority consultation (Art. 36)
     */
    public function findRequiringSupervisoryConsultation(Tenant $tenant): array
    {
        return $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->andWhere('dpia.requiresSupervisoryConsultation = :required')
            ->andWhere('dpia.supervisoryConsultationDate IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('required', true)
            ->orderBy('dpia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find DPIAs due for review (Art. 35(11))
     */
    public function findDueForReview(Tenant $tenant): array
    {
        $today = new DateTime();

        return $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->andWhere('dpia.nextReviewDate IS NOT NULL')
            ->andWhere('dpia.nextReviewDate <= :today')
            ->setParameter('tenant', $tenant)
            ->setParameter('today', $today)
            ->orderBy('dpia.nextReviewDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find incomplete DPIAs (missing mandatory fields)
     */
    public function findIncomplete(Tenant $tenant): array
    {
        $all = $this->findByTenant($tenant);

        return array_filter($all, fn(DataProtectionImpactAssessment $dataProtectionImpactAssessment): bool => !$dataProtectionImpactAssessment->isComplete());
    }

    /**
     * Find DPIAs awaiting DPO consultation (Art. 35(4))
     */
    public function findAwaitingDPOConsultation(Tenant $tenant): array
    {
        return $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->andWhere('dpia.status IN (:statuses)')
            ->andWhere('dpia.dpoConsultationDate IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['draft', 'in_review'])
            ->orderBy('dpia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find DPIA by processing activity
     */
    public function findByProcessingActivity(ProcessingActivity $processingActivity): ?DataProtectionImpactAssessment
    {
        return $this->createQueryBuilder('dpia')
            ->where('dpia.processingActivity = :pa')
            ->setParameter('pa', $processingActivity)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find DPIAs by reference number pattern
     */
    public function findByReferencePattern(Tenant $tenant, string $pattern): array
    {
        return $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->andWhere('dpia.referenceNumber LIKE :pattern')
            ->setParameter('tenant', $tenant)
            ->setParameter('pattern', '%' . $pattern . '%')
            ->orderBy('dpia.referenceNumber', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search DPIAs by title or reference number
     */
    public function search(Tenant $tenant, string $query): array
    {
        return $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->andWhere('dpia.title LIKE :query OR dpia.referenceNumber LIKE :query')
            ->setParameter('tenant', $tenant)
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('dpia.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics for dashboard
     */
    public function getStatistics(Tenant $tenant): array
    {
        $queryBuilder = $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $total = (clone $queryBuilder)->select('COUNT(dpia.id)')->getQuery()->getSingleScalarResult();

        $approved = (clone $queryBuilder)
            ->andWhere('dpia.status = :status')
            ->setParameter('status', 'approved')
            ->select('COUNT(dpia.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $inReview = (clone $queryBuilder)
            ->andWhere('dpia.status = :status')
            ->setParameter('status', 'in_review')
            ->select('COUNT(dpia.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $draft = (clone $queryBuilder)
            ->andWhere('dpia.status = :status')
            ->setParameter('status', 'draft')
            ->select('COUNT(dpia.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $highRisk = (clone $queryBuilder)
            ->andWhere('dpia.riskLevel IN (:riskLevels)')
            ->setParameter('riskLevels', ['high', 'critical'])
            ->select('COUNT(dpia.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $requiresSupervisory = (clone $queryBuilder)
            ->andWhere('dpia.requiresSupervisoryConsultation = :required')
            ->andWhere('dpia.supervisoryConsultationDate IS NULL')
            ->setParameter('required', true)
            ->select('COUNT(dpia.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $awaitingDPO = (clone $queryBuilder)
            ->andWhere('dpia.status IN (:statuses)')
            ->andWhere('dpia.dpoConsultationDate IS NULL')
            ->setParameter('statuses', ['draft', 'in_review'])
            ->select('COUNT(dpia.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Calculate completeness rate
        $allDPIAs = $this->findByTenant($tenant);
        $completeDPIAs = array_filter($allDPIAs, fn($dpia) => $dpia->isComplete());
        $completenessRate = $total > 0 ? (int) ((count($completeDPIAs) / $total) * 100) : 0;

        // Approval rate
        $approvalRate = $total > 0 ? (int) (($approved / $total) * 100) : 0;

        // Risk level distribution
        $riskDistribution = [
            'low' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0,
        ];

        foreach ($allDPIAs as $dpia) {
            $level = $dpia->getRiskLevel();
            if ($level && isset($riskDistribution[$level])) {
                $riskDistribution[$level]++;
            }
        }

        // Residual risk distribution
        $residualRiskDistribution = [
            'low' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0,
        ];

        foreach ($allDPIAs as $allDPIA) {
            $level = $allDPIA->getResidualRiskLevel();
            if ($level && isset($residualRiskDistribution[$level])) {
                $residualRiskDistribution[$level]++;
            }
        }

        return [
            'total' => (int) $total,
            'approved' => (int) $approved,
            'in_review' => (int) $inReview,
            'draft' => (int) $draft,
            'high_risk' => (int) $highRisk,
            'requires_supervisory_consultation' => (int) $requiresSupervisory,
            'awaiting_dpo_consultation' => (int) $awaitingDPO,
            'completeness_rate' => $completenessRate,
            'approval_rate' => $approvalRate,
            'risk_distribution' => $riskDistribution,
            'residual_risk_distribution' => $residualRiskDistribution,
        ];
    }

    /**
     * Get next available reference number for tenant
     * Format: DPIA-YYYY-XXX
     */
    public function getNextReferenceNumber(Tenant $tenant): string
    {
        $year = date('Y');
        $prefix = sprintf('DPIA-%s-', $year);

        $lastDpia = $this->createQueryBuilder('dpia')
            ->where('dpia.tenant = :tenant')
            ->andWhere('dpia.referenceNumber LIKE :prefix')
            ->setParameter('tenant', $tenant)
            ->setParameter('prefix', $prefix . '%')
            ->orderBy('dpia.referenceNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$lastDpia) {
            return $prefix . '001';
        }

        // Extract number from last reference (DPIA-2024-001 -> 001)
        $lastRef = $lastDpia->getReferenceNumber();
        $lastNumber = (int) substr((string) $lastRef, -3);
        $nextNumber = $lastNumber + 1;

        return $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Count DPIAs requiring action (incomplete, due for review, awaiting approval)
     */
    public function countRequiringAction(Tenant $tenant): int
    {
        $incomplete = count($this->findIncomplete($tenant));
        $dueForReview = count($this->findDueForReview($tenant));
        $inReview = count($this->findInReview($tenant));
        $requiresSupervisory = count($this->findRequiringSupervisoryConsultation($tenant));

        return $incomplete + $dueForReview + $inReview + $requiresSupervisory;
    }
}
