<?php

namespace App\Repository;

use DateTime;
use DateTimeInterface;
use App\Entity\DataBreach;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DataBreach>
 *
 * Repository for DataBreach entity (Art. 33/34 GDPR)
 * Provides specialized queries for data breach management and compliance tracking
 */
class DataBreachRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DataBreach::class);
    }

    /**
     * Find all data breaches for a tenant
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('db.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find data breaches by status
     */
    public function findByStatus(Tenant $tenant, string $status): array
    {
        return $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->andWhere('db.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('status', $status)
            ->orderBy('db.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find data breaches by risk level
     */
    public function findByRiskLevel(Tenant $tenant, string $riskLevel): array
    {
        return $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->andWhere('db.riskLevel = :riskLevel')
            ->setParameter('tenant', $tenant)
            ->setParameter('riskLevel', $riskLevel)
            ->orderBy('db.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find high and critical risk data breaches
     */
    public function findHighRisk(Tenant $tenant): array
    {
        return $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->andWhere('db.riskLevel IN (:riskLevels)')
            ->setParameter('tenant', $tenant)
            ->setParameter('riskLevels', ['high', 'critical'])
            ->orderBy('db.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find data breaches requiring supervisory authority notification
     */
    public function findRequiringAuthorityNotification(Tenant $tenant): array
    {
        return $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->andWhere('db.requiresAuthorityNotification = :required')
            ->andWhere('db.supervisoryAuthorityNotifiedAt IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('required', true)
            ->orderBy('db.createdAt', 'ASC') // Oldest first (most urgent)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find data breaches where authority notification is overdue (>72h)
     * CRITICAL for GDPR Art. 33 compliance!
     */
    public function findAuthorityNotificationOverdue(Tenant $tenant): array
    {
        $deadline = new DateTime('-72 hours');

        return $this->createQueryBuilder('db')
            ->innerJoin('db.incident', 'i')
            ->where('db.tenant = :tenant')
            ->andWhere('db.requiresAuthorityNotification = :required')
            ->andWhere('db.supervisoryAuthorityNotifiedAt IS NULL')
            ->andWhere('i.detectedAt < :deadline')
            ->setParameter('tenant', $tenant)
            ->setParameter('required', true)
            ->setParameter('deadline', $deadline)
            ->orderBy('i.detectedAt', 'ASC') // Oldest first (most overdue)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find data breaches requiring data subject notification
     */
    public function findRequiringSubjectNotification(Tenant $tenant): array
    {
        return $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->andWhere('db.requiresSubjectNotification = :required')
            ->andWhere('db.dataSubjectsNotifiedAt IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('required', true)
            ->orderBy('db.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find data breaches affecting special categories of data (Art. 9 GDPR)
     */
    public function findWithSpecialCategories(Tenant $tenant): array
    {
        return $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->andWhere('db.specialCategoriesAffected = :affected')
            ->setParameter('tenant', $tenant)
            ->setParameter('affected', true)
            ->orderBy('db.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find data breaches affecting criminal conviction data (Art. 10 GDPR)
     */
    public function findWithCriminalData(Tenant $tenant): array
    {
        return $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->andWhere('db.criminalDataAffected = :affected')
            ->setParameter('tenant', $tenant)
            ->setParameter('affected', true)
            ->orderBy('db.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find incomplete data breaches
     */
    public function findIncomplete(Tenant $tenant): array
    {
        $allBreaches = $this->findByTenant($tenant);

        return array_filter($allBreaches, fn(DataBreach $dataBreach): bool => !$dataBreach->isComplete());
    }

    /**
     * Find data breaches linked to a specific processing activity
     */
    public function findByProcessingActivity(Tenant $tenant, int $processingActivityId): array
    {
        return $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->andWhere('db.processingActivity = :processingActivity')
            ->setParameter('tenant', $tenant)
            ->setParameter('processingActivity', $processingActivityId)
            ->orderBy('db.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find data breaches within a date range
     */
    public function findByDateRange(Tenant $tenant, DateTimeInterface $start, DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('db')
            ->innerJoin('db.incident', 'i')
            ->where('db.tenant = :tenant')
            ->andWhere('i.detectedAt BETWEEN :start AND :end')
            ->setParameter('tenant', $tenant)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('i.detectedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get dashboard statistics for data breach management
     */
    public function getDashboardStatistics(Tenant $tenant): array
    {
        $queryBuilder = $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->setParameter('tenant', $tenant);

        $total = (clone $queryBuilder)->select('COUNT(db.id)')->getQuery()->getSingleScalarResult();

        $byStatus = $this->createQueryBuilder('db')
            ->select('db.status, COUNT(db.id) as count')
            ->where('db.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('db.status')
            ->getQuery()
            ->getResult();

        $statusCounts = [
            'draft' => 0,
            'under_assessment' => 0,
            'authority_notified' => 0,
            'subjects_notified' => 0,
            'closed' => 0,
        ];

        foreach ($byStatus as $stat) {
            $statusCounts[$stat['status']] = (int) $stat['count'];
        }

        $byRiskLevel = $this->createQueryBuilder('db')
            ->select('db.riskLevel, COUNT(db.id) as count')
            ->where('db.tenant = :tenant')
            ->andWhere('db.riskLevel IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->groupBy('db.riskLevel')
            ->getQuery()
            ->getResult();

        $riskCounts = [
            'low' => 0,
            'medium' => 0,
            'high' => 0,
            'critical' => 0,
        ];

        foreach ($byRiskLevel as $stat) {
            $riskCounts[$stat['riskLevel']] = (int) $stat['count'];
        }

        $requiresAuthority = $this->createQueryBuilder('db')
            ->select('COUNT(db.id)')
            ->where('db.tenant = :tenant')
            ->andWhere('db.requiresAuthorityNotification = :required')
            ->setParameter('tenant', $tenant)
            ->setParameter('required', true)
            ->getQuery()
            ->getSingleScalarResult();

        $authorityNotified = $this->createQueryBuilder('db')
            ->select('COUNT(db.id)')
            ->where('db.tenant = :tenant')
            ->andWhere('db.supervisoryAuthorityNotifiedAt IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        $requiresSubjects = $this->createQueryBuilder('db')
            ->select('COUNT(db.id)')
            ->where('db.tenant = :tenant')
            ->andWhere('db.requiresSubjectNotification = :required')
            ->setParameter('tenant', $tenant)
            ->setParameter('required', true)
            ->getQuery()
            ->getSingleScalarResult();

        $subjectsNotified = $this->createQueryBuilder('db')
            ->select('COUNT(db.id)')
            ->where('db.tenant = :tenant')
            ->andWhere('db.dataSubjectsNotifiedAt IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        $specialCategories = $this->createQueryBuilder('db')
            ->select('COUNT(db.id)')
            ->where('db.tenant = :tenant')
            ->andWhere('db.specialCategoriesAffected = :affected')
            ->setParameter('tenant', $tenant)
            ->setParameter('affected', true)
            ->getQuery()
            ->getSingleScalarResult();

        // Calculate completeness
        $allBreaches = $this->findByTenant($tenant);
        $completenessSum = 0;
        foreach ($allBreaches as $allBreach) {
            $completenessSum += $allBreach->getCompletenessPercentage();
        }
        $completenessRate = $total > 0 ? (int) round($completenessSum / $total) : 0;

        return [
            'total' => (int) $total,
            'draft' => $statusCounts['draft'],
            'under_assessment' => $statusCounts['under_assessment'],
            'closed' => $statusCounts['closed'],
            'low_risk' => $riskCounts['low'],
            'medium_risk' => $riskCounts['medium'],
            'high_risk' => $riskCounts['high'],
            'critical_risk' => $riskCounts['critical'],
            'requires_authority_notification' => (int) $requiresAuthority,
            'authority_notified' => (int) $authorityNotified,
            'requires_subject_notification' => (int) $requiresSubjects,
            'subjects_notified' => (int) $subjectsNotified,
            'special_categories_affected' => (int) $specialCategories,
            'completeness_rate' => $completenessRate,
        ];
    }

    /**
     * Generate next reference number for data breach
     * Format: BREACH-YYYY-XXX
     */
    public function getNextReferenceNumber(Tenant $tenant): string
    {
        $year = date('Y');
        $prefix = sprintf('BREACH-%s-', $year);

        $lastBreach = $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->andWhere('db.referenceNumber LIKE :prefix')
            ->setParameter('tenant', $tenant)
            ->setParameter('prefix', $prefix . '%')
            ->orderBy('db.referenceNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$lastBreach) {
            return $prefix . '001';
        }

        $lastNumber = (int) substr((string) $lastBreach->getReferenceNumber(), -3);
        $nextNumber = $lastNumber + 1;

        return $prefix . str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Count total affected data subjects across all breaches
     */
    public function getTotalAffectedDataSubjects(Tenant $tenant): int
    {
        $result = $this->createQueryBuilder('db')
            ->select('SUM(db.affectedDataSubjects)')
            ->where('db.tenant = :tenant')
            ->andWhere('db.affectedDataSubjects IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) ($result ?? 0);
    }

    /**
     * Find recent data breaches (last 30 days)
     */
    public function findRecent(Tenant $tenant, int $days = 30): array
    {
        $since = new DateTime(sprintf('-%d days', $days));

        return $this->createQueryBuilder('db')
            ->where('db.tenant = :tenant')
            ->andWhere('db.createdAt >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('since', $since)
            ->orderBy('db.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
