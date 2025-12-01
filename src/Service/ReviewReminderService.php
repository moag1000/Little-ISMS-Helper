<?php

namespace App\Service;

use App\Entity\BusinessContinuityPlan;
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Entity\Risk;
use App\Entity\User;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\UserRepository;
use DateTime;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Review Reminder Service
 *
 * Centralized service for tracking and notifying about overdue reviews across ISMS entities.
 * Supports: Risks, BC Plans, Processing Activities, DPIAs, Data Breaches (72h deadline)
 *
 * This service is designed to be called by:
 * 1. A scheduled command (cron job) for automated notifications
 * 2. Dashboard widgets for real-time overdue counts
 * 3. API endpoints for frontend integration
 */
class ReviewReminderService
{
    public function __construct(
        private readonly RiskRepository $riskRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly DataProtectionImpactAssessmentRepository $dpiaRepository,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly UserRepository $userRepository,
        private readonly EmailNotificationService $emailNotificationService,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Get all overdue reviews across all entity types
     *
     * @return array{risks: array, bc_plans: array, processing_activities: array, dpias: array, data_breaches: array, summary: array}
     */
    public function getAllOverdueReviews(): array
    {
        $overdueRisks = $this->getOverdueRiskReviews();
        $overdueBcPlans = $this->getOverdueBcPlanReviews();
        $overdueProcessingActivities = $this->getOverdueProcessingActivityReviews();
        $overdueDpias = $this->getOverdueDpiaReviews();
        $urgentDataBreaches = $this->getUrgentDataBreaches();

        return [
            'risks' => $overdueRisks,
            'bc_plans' => $overdueBcPlans,
            'processing_activities' => $overdueProcessingActivities,
            'dpias' => $overdueDpias,
            'data_breaches' => $urgentDataBreaches,
            'summary' => [
                'total_overdue' => count($overdueRisks) + count($overdueBcPlans) + count($overdueProcessingActivities) + count($overdueDpias),
                'urgent_breaches' => count($urgentDataBreaches),
                'risks_count' => count($overdueRisks),
                'bc_plans_count' => count($overdueBcPlans),
                'processing_activities_count' => count($overdueProcessingActivities),
                'dpias_count' => count($overdueDpias),
            ],
        ];
    }

    /**
     * Get risks with overdue review dates
     *
     * @return Risk[]
     */
    public function getOverdueRiskReviews(): array
    {
        $now = new DateTime();
        $risks = $this->riskRepository->findAll();

        return array_filter($risks, function (Risk $risk) use ($now): bool {
            $reviewDate = $risk->getReviewDate();
            if (!$reviewDate instanceof DateTimeInterface) {
                return false;
            }
            // Only check non-closed risks
            return $reviewDate < $now && !in_array($risk->getStatus(), ['closed', 'accepted'], true);
        });
    }

    /**
     * Get BC Plans with overdue reviews or tests
     *
     * @return BusinessContinuityPlan[]
     */
    public function getOverdueBcPlanReviews(): array
    {
        $bcPlans = $this->bcPlanRepository->findBy(['status' => 'active']);

        return array_filter($bcPlans, function (BusinessContinuityPlan $plan): bool {
            return $plan->isReviewOverdue() || $plan->isTestOverdue();
        });
    }

    /**
     * Get Processing Activities with overdue reviews
     *
     * @return ProcessingActivity[]
     */
    public function getOverdueProcessingActivityReviews(): array
    {
        $now = new DateTime();
        $activities = $this->processingActivityRepository->findBy(['status' => 'active']);

        return array_filter($activities, function (ProcessingActivity $activity) use ($now): bool {
            $nextReview = $activity->getNextReviewDate();
            return $nextReview instanceof DateTimeInterface && $nextReview < $now;
        });
    }

    /**
     * Get DPIAs with overdue reviews
     *
     * @return DataProtectionImpactAssessment[]
     */
    public function getOverdueDpiaReviews(): array
    {
        $now = new DateTime();
        $dpias = $this->dpiaRepository->findBy(['status' => 'approved']);

        return array_filter($dpias, function (DataProtectionImpactAssessment $dpia) use ($now): bool {
            $nextReview = $dpia->getNextReviewDate();
            return $nextReview instanceof DateTimeInterface && $nextReview < $now;
        });
    }

    /**
     * Get Data Breaches approaching or past 72h notification deadline
     *
     * @param int $hoursThreshold Hours before deadline to consider urgent (default: 24)
     * @return DataBreach[]
     */
    public function getUrgentDataBreaches(int $hoursThreshold = 24): array
    {
        $breaches = $this->dataBreachRepository->findBy([
            'requiresAuthorityNotification' => true,
        ]);

        return array_filter($breaches, function (DataBreach $breach) use ($hoursThreshold): bool {
            // Skip already notified breaches
            if ($breach->getSupervisoryAuthorityNotifiedAt() !== null) {
                return false;
            }

            $hoursRemaining = $breach->getHoursUntilAuthorityDeadline();
            if ($hoursRemaining === null) {
                return false;
            }

            // Include if overdue OR within threshold
            return $hoursRemaining <= $hoursThreshold;
        });
    }

    /**
     * Get items due for review within the next N days (upcoming, not overdue)
     *
     * @param int $days Number of days to look ahead
     * @return array{risks: array, bc_plans: array, processing_activities: array, dpias: array}
     * @throws \DateMalformedStringException
     */
    public function getUpcomingReviews(int $days = 14): array
    {
        $now = new DateTime();
        $threshold = (clone $now)->modify("+{$days} days");

        return [
            'risks' => $this->getUpcomingRiskReviews($now, $threshold),
            'bc_plans' => $this->getUpcomingBcPlanReviews($now, $threshold),
            'processing_activities' => $this->getUpcomingProcessingActivityReviews($now, $threshold),
            'dpias' => $this->getUpcomingDpiaReviews($now, $threshold),
        ];
    }

    /**
     * Send reminder notifications to responsible users
     *
     * @param bool $includeUpcoming Also send reminders for upcoming (not yet overdue) reviews
     * @return array{sent: int, failed: int, details: array}
     */
    public function sendReminderNotifications(bool $includeUpcoming = false): array
    {
        $sent = 0;
        $failed = 0;
        $details = [];

        // Process overdue risks
        foreach ($this->getOverdueRiskReviews() as $risk) {
            $owner = $risk->getRiskOwner();
            if ($owner instanceof User) {
                try {
                    $this->emailNotificationService->sendGenericNotification(
                        '[ISMS Reminder] Overdue Risk Review: ' . $risk->getTitle(),
                        'emails/review_reminder.html.twig',
                        [
                            'entity_type' => 'Risk',
                            'entity_name' => $risk->getTitle(),
                            'review_date' => $risk->getReviewDate(),
                            'entity_id' => $risk->getId(),
                            'route_name' => 'app_risk_show',
                        ],
                        [$owner]
                    );
                    $sent++;
                    $details[] = ['type' => 'risk', 'id' => $risk->getId(), 'status' => 'sent'];
                } catch (Throwable $e) {
                    $failed++;
                    $details[] = ['type' => 'risk', 'id' => $risk->getId(), 'status' => 'failed', 'error' => $e->getMessage()];
                    $this->logger?->error('Failed to send risk review reminder', [
                        'risk_id' => $risk->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Process urgent data breaches (72h deadline)
        foreach ($this->getUrgentDataBreaches() as $breach) {
            $dpo = $breach->getDataProtectionOfficer();
            $assessor = $breach->getAssessor();
            $recipients = array_filter([$dpo, $assessor]);

            if ($recipients === []) {
                // Fallback to all admins
                $recipients = $this->userRepository->findByRole('ROLE_ADMIN');
            }

            foreach ($recipients as $recipient) {
                try {
                    $hoursRemaining = $breach->getHoursUntilAuthorityDeadline();
                    $urgencyLevel = $hoursRemaining < 0 ? 'OVERDUE' : ($hoursRemaining < 12 ? 'CRITICAL' : 'WARNING');

                    $this->emailNotificationService->sendGenericNotification(
                        "[{$urgencyLevel}] Data Breach 72h Deadline: " . $breach->getTitle(),
                        'emails/data_breach_deadline_reminder.html.twig',
                        [
                            'breach' => $breach,
                            'hours_remaining' => $hoursRemaining,
                            'deadline' => $breach->getAuthorityNotificationDeadline(),
                            'urgency_level' => $urgencyLevel,
                        ],
                        [$recipient]
                    );
                    $sent++;
                    $details[] = ['type' => 'data_breach', 'id' => $breach->getId(), 'status' => 'sent'];
                } catch (Throwable $e) {
                    $failed++;
                    $details[] = ['type' => 'data_breach', 'id' => $breach->getId(), 'status' => 'failed', 'error' => $e->getMessage()];
                    $this->logger?->error('Failed to send data breach deadline reminder', [
                        'breach_id' => $breach->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Process overdue BC Plans
        foreach ($this->getOverdueBcPlanReviews() as $plan) {
            // BC Plans use planOwner as string, would need to resolve to User
            // For now, notify admins
            $admins = $this->userRepository->findByRole('ROLE_ADMIN');
            foreach ($admins as $admin) {
                try {
                    $this->emailNotificationService->sendGenericNotification(
                        '[ISMS Reminder] Overdue BC Plan Review: ' . $plan->getName(),
                        'emails/review_reminder.html.twig',
                        [
                            'entity_type' => 'Business Continuity Plan',
                            'entity_name' => $plan->getName(),
                            'review_date' => $plan->getNextReviewDate(),
                            'test_date' => $plan->getNextTestDate(),
                            'entity_id' => $plan->getId(),
                            'route_name' => 'app_business_continuity_plan_show',
                            'is_test_overdue' => $plan->isTestOverdue(),
                            'is_review_overdue' => $plan->isReviewOverdue(),
                        ],
                        [$admin]
                    );
                    $sent++;
                    $details[] = ['type' => 'bc_plan', 'id' => $plan->getId(), 'status' => 'sent'];
                    break; // Only send once per plan (to first admin)
                } catch (Throwable $e) {
                    $failed++;
                    $details[] = ['type' => 'bc_plan', 'id' => $plan->getId(), 'status' => 'failed', 'error' => $e->getMessage()];
                }
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'details' => $details,
        ];
    }

    /**
     * Get dashboard statistics for overdue items
     *
     * @return array{total: int, critical: int, by_type: array, by_days_overdue: array}
     */
    public function getDashboardStatistics(): array
    {
        $allOverdue = $this->getAllOverdueReviews();
        $now = new DateTime();

        $criticalCount = 0;
        $byDaysOverdue = ['0-7' => 0, '8-30' => 0, '31-90' => 0, '90+' => 0];

        // Count critical items (data breaches + high risks)
        $criticalCount += count($allOverdue['data_breaches']);

        // Analyze overdue days for risks
        foreach ($allOverdue['risks'] as $risk) {
            $reviewDate = $risk->getReviewDate();
            if ($reviewDate instanceof DateTimeInterface) {
                $daysOverdue = $now->diff($reviewDate)->days;
                $this->categorizeByDays($daysOverdue, $byDaysOverdue);
                if ($risk->isHighRisk()) {
                    $criticalCount++;
                }
            }
        }

        return [
            'total' => $allOverdue['summary']['total_overdue'],
            'critical' => $criticalCount,
            'urgent_breaches' => $allOverdue['summary']['urgent_breaches'],
            'by_type' => [
                'risks' => $allOverdue['summary']['risks_count'],
                'bc_plans' => $allOverdue['summary']['bc_plans_count'],
                'processing_activities' => $allOverdue['summary']['processing_activities_count'],
                'dpias' => $allOverdue['summary']['dpias_count'],
            ],
            'by_days_overdue' => $byDaysOverdue,
        ];
    }

    // ============================================================================
    // Private Helper Methods
    // ============================================================================

    private function getUpcomingRiskReviews(DateTime $now, DateTime $threshold): array
    {
        $risks = $this->riskRepository->findAll();

        return array_filter($risks, function (Risk $risk) use ($now, $threshold): bool {
            $reviewDate = $risk->getReviewDate();
            if (!$reviewDate instanceof DateTimeInterface) {
                return false;
            }
            return $reviewDate >= $now && $reviewDate <= $threshold && !in_array($risk->getStatus(), ['closed', 'accepted'], true);
        });
    }

    private function getUpcomingBcPlanReviews(DateTime $now, DateTime $threshold): array
    {
        $plans = $this->bcPlanRepository->findBy(['status' => 'active']);

        return array_filter($plans, function (BusinessContinuityPlan $plan) use ($now, $threshold): bool {
            $nextReview = $plan->getNextReviewDate();
            $nextTest = $plan->getNextTestDate();

            $reviewUpcoming = $nextReview instanceof DateTimeInterface && $nextReview >= $now && $nextReview <= $threshold;
            $testUpcoming = $nextTest instanceof DateTimeInterface && $nextTest >= $now && $nextTest <= $threshold;

            return $reviewUpcoming || $testUpcoming;
        });
    }

    private function getUpcomingProcessingActivityReviews(DateTime $now, DateTime $threshold): array
    {
        $activities = $this->processingActivityRepository->findBy(['status' => 'active']);

        return array_filter($activities, function (ProcessingActivity $activity) use ($now, $threshold): bool {
            $nextReview = $activity->getNextReviewDate();
            return $nextReview instanceof DateTimeInterface && $nextReview >= $now && $nextReview <= $threshold;
        });
    }

    private function getUpcomingDpiaReviews(DateTime $now, DateTime $threshold): array
    {
        $dpias = $this->dpiaRepository->findBy(['status' => 'approved']);

        return array_filter($dpias, function (DataProtectionImpactAssessment $dpia) use ($now, $threshold): bool {
            $nextReview = $dpia->getNextReviewDate();
            return $nextReview instanceof DateTimeInterface && $nextReview >= $now && $nextReview <= $threshold;
        });
    }

    private function categorizeByDays(int $daysOverdue, array &$byDaysOverdue): void
    {
        if ($daysOverdue <= 7) {
            $byDaysOverdue['0-7']++;
        } elseif ($daysOverdue <= 30) {
            $byDaysOverdue['8-30']++;
        } elseif ($daysOverdue <= 90) {
            $byDaysOverdue['31-90']++;
        } else {
            $byDaysOverdue['90+']++;
        }
    }
}
