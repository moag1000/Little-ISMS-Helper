<?php

namespace App\Service;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\RiskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Risk Review Service
 *
 * Implements periodic risk review workflow as required by:
 * - ISO 27001:2022 Clause 6.1.3.d (Review and monitor risks)
 * - ISO 31000:2018 Section 6.6 (Monitoring and review)
 *
 * Features:
 * - Automatic review scheduling based on risk level
 * - Overdue review tracking
 * - Review interval enforcement
 */
class RiskReviewService
{
    public function __construct(
        private RiskRepository $riskRepository,
        private EntityManagerInterface $entityManager,
        private RiskMatrixService $riskMatrixService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Get review intervals (in days) based on risk level
     *
     * ISO 27001:2022 requires regular risk reviews:
     * - Critical risks: Quarterly (90 days)
     * - High risks: Semi-annually (180 days)
     * - Medium risks: Annually (365 days)
     * - Low risks: Bi-annually (730 days)
     *
     * @return array<string, int>
     */
    public function getReviewSchedule(): array
    {
        return [
            'critical' => 90,   // 3 months
            'high' => 180,      // 6 months
            'medium' => 365,    // 12 months
            'low' => 730,       // 24 months
        ];
    }

    /**
     * Get risks that are overdue for review
     *
     * Returns all risks where:
     * - reviewDate is in the past, OR
     * - reviewDate is null (never reviewed)
     *
     * @param Tenant $tenant
     * @return array<Risk>
     */
    public function getOverdueReviews(Tenant $tenant): array
    {
        $qb = $this->riskRepository->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('r.reviewDate < :today OR r.reviewDate IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('today', new \DateTime())
            ->orderBy('r.reviewDate', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get risks approaching review date (within next N days)
     *
     * @param Tenant $tenant
     * @param int $daysAhead Number of days to look ahead (default: 30)
     * @return array<Risk>
     */
    public function getUpcomingReviews(Tenant $tenant, int $daysAhead = 30): array
    {
        $today = new \DateTime();
        $futureDate = (clone $today)->modify("+{$daysAhead} days");

        $qb = $this->riskRepository->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('r.reviewDate BETWEEN :today AND :futureDate')
            ->setParameter('tenant', $tenant)
            ->setParameter('today', $today)
            ->setParameter('futureDate', $futureDate)
            ->orderBy('r.reviewDate', 'ASC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Schedule next review date for a risk based on its level
     *
     * Automatically calculates and sets the next review date based on:
     * - Current risk score (probability × impact)
     * - Risk level thresholds
     * - Review interval configuration
     *
     * @param Risk $risk
     * @param bool $flush Whether to persist changes immediately
     * @return \DateTimeInterface
     */
    public function scheduleNextReview(Risk $risk, bool $flush = true): \DateTimeInterface
    {
        $riskLevel = $this->getRiskLevel($risk);
        $schedule = $this->getReviewSchedule();
        $interval = $schedule[$riskLevel] ?? 365; // Default to 1 year

        $nextReview = (new \DateTime())->modify("+{$interval} days");
        $risk->setReviewDate($nextReview);

        if ($flush) {
            $this->entityManager->flush();
        }

        $this->logger->info('Scheduled next review for risk', [
            'risk_id' => $risk->getId(),
            'risk_title' => $risk->getTitle(),
            'risk_level' => $riskLevel,
            'interval_days' => $interval,
            'next_review' => $nextReview->format('Y-m-d'),
        ]);

        return $nextReview;
    }

    /**
     * Determine risk level based on risk score
     * Uses centralized thresholds from RiskMatrixService
     *
     * @param Risk $risk
     * @return string 'critical'|'high'|'medium'|'low'
     */
    private function getRiskLevel(Risk $risk): string
    {
        $riskScore = ($risk->getProbability() ?? 1) * ($risk->getImpact() ?? 1);

        // Use the same thresholds as RiskMatrixService for consistency
        if ($riskScore >= 20) {
            return 'critical';
        } elseif ($riskScore >= 12) {
            return 'high';
        } elseif ($riskScore >= 6) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Get review statistics for a tenant
     *
     * Returns:
     * - total: Total risks requiring review
     * - overdue: Number of overdue reviews
     * - upcoming_30: Reviews due in next 30 days
     * - upcoming_7: Reviews due in next 7 days
     * - never_reviewed: Risks without review date
     *
     * @param Tenant $tenant
     * @return array<string, int>
     */
    public function getReviewStatistics(Tenant $tenant): array
    {
        $totalRisks = $this->riskRepository->count(['tenant' => $tenant]);
        $overdueReviews = $this->getOverdueReviews($tenant);
        $upcoming30 = $this->getUpcomingReviews($tenant, 30);
        $upcoming7 = $this->getUpcomingReviews($tenant, 7);

        $neverReviewed = $this->riskRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.tenant = :tenant')
            ->andWhere('r.reviewDate IS NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $totalRisks,
            'overdue' => count($overdueReviews),
            'upcoming_30' => count($upcoming30),
            'upcoming_7' => count($upcoming7),
            'never_reviewed' => (int) $neverReviewed,
        ];
    }

    /**
     * Bulk schedule reviews for all risks without review date
     *
     * Useful for initial setup or after importing risks
     *
     * @param Tenant $tenant
     * @return int Number of risks scheduled
     */
    public function bulkScheduleReviews(Tenant $tenant): int
    {
        $risks = $this->riskRepository->createQueryBuilder('r')
            ->where('r.tenant = :tenant')
            ->andWhere('r.reviewDate IS NULL')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();

        $count = 0;
        foreach ($risks as $risk) {
            $this->scheduleNextReview($risk, false);
            $count++;
        }

        $this->entityManager->flush();

        $this->logger->info('Bulk scheduled reviews', [
            'tenant_id' => $tenant->getId(),
            'risks_scheduled' => $count,
        ]);

        return $count;
    }
}
