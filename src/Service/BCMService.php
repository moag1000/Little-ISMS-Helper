<?php

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Business Continuity Management Service
 *
 * Centralizes BCM analytics and reporting logic.
 * Provides BIA analysis, plan readiness, and exercise schedule aggregation.
 *
 * Follows the same patterns as DashboardStatisticsService for consistency.
 */
class BCMService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly BCExerciseRepository $bcExerciseRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Aggregate BIA (Business Impact Analysis) data for reporting
     *
     * @param Tenant|null $tenant Tenant to scope to; uses TenantContext if null
     * @return array{
     *     total_processes: int,
     *     critical: int,
     *     high: int,
     *     medium: int,
     *     low: int,
     *     avg_rto_hours: float,
     *     processes_without_plan: int,
     *     rto_violations: int
     * }
     */
    public function getBiaAnalysis(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenantContext->getCurrentTenant();

        $processes = $tenant
            ? $this->businessProcessRepository->findByTenant($tenant)
            : $this->businessProcessRepository->findAll();

        $critical = 0;
        $high = 0;
        $medium = 0;
        $low = 0;
        $totalRto = 0;
        $rtoCount = 0;
        $rtoViolations = 0;

        foreach ($processes as $process) {
            match ($process->getCriticality()) {
                'critical' => $critical++,
                'high' => $high++,
                'medium' => $medium++,
                'low' => $low++,
                default => null,
            };

            if ($process->getRto() !== null) {
                $totalRto += $process->getRto();
                $rtoCount++;
            }

            if ($process->hasRTOViolations()) {
                $rtoViolations++;
            }
        }

        // Find processes without a linked BC plan
        $plans = $tenant
            ? $this->bcPlanRepository->findBy(['tenant' => $tenant])
            : $this->bcPlanRepository->findAll();

        $coveredProcessIds = [];
        foreach ($plans as $plan) {
            $bp = $plan->getBusinessProcess();
            if ($bp !== null) {
                $coveredProcessIds[$bp->getId()] = true;
            }
        }

        $processesWithoutPlan = 0;
        foreach ($processes as $process) {
            if (!isset($coveredProcessIds[$process->getId()])) {
                $processesWithoutPlan++;
            }
        }

        return [
            'total_processes' => count($processes),
            'critical' => $critical,
            'high' => $high,
            'medium' => $medium,
            'low' => $low,
            'avg_rto_hours' => $rtoCount > 0 ? round($totalRto / $rtoCount, 1) : 0.0,
            'processes_without_plan' => $processesWithoutPlan,
            'rto_violations' => $rtoViolations,
        ];
    }

    /**
     * Aggregate BC plan readiness and status data
     *
     * @param Tenant|null $tenant Tenant to scope to; uses TenantContext if null
     * @return array{
     *     total_plans: int,
     *     active: int,
     *     draft: int,
     *     under_review: int,
     *     overdue_reviews: int,
     *     overdue_tests: int,
     *     avg_readiness_score: float
     * }
     */
    public function getPlanReadiness(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenantContext->getCurrentTenant();

        $plans = $tenant
            ? $this->bcPlanRepository->findBy(['tenant' => $tenant])
            : $this->bcPlanRepository->findAll();

        $active = 0;
        $draft = 0;
        $underReview = 0;
        $overdueReviews = 0;
        $overdueTests = 0;
        $totalReadiness = 0;

        foreach ($plans as $plan) {
            match ($plan->getStatus()) {
                'active' => $active++,
                'draft' => $draft++,
                'under_review' => $underReview++,
                default => null,
            };

            if ($plan->isReviewOverdue()) {
                $overdueReviews++;
            }

            if ($plan->isTestOverdue()) {
                $overdueTests++;
            }

            $totalReadiness += $plan->getReadinessScore();
        }

        $totalPlans = count($plans);

        return [
            'total_plans' => $totalPlans,
            'active' => $active,
            'draft' => $draft,
            'under_review' => $underReview,
            'overdue_reviews' => $overdueReviews,
            'overdue_tests' => $overdueTests,
            'avg_readiness_score' => $totalPlans > 0 ? round($totalReadiness / $totalPlans, 1) : 0.0,
        ];
    }

    /**
     * Get exercise schedule: upcoming, overdue, and year-to-date completions
     *
     * @param Tenant|null $tenant Tenant to scope to; uses TenantContext if null
     * @return array{
     *     upcoming: array,
     *     overdue: array,
     *     completed_this_year: int
     * }
     */
    public function getExerciseSchedule(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenantContext->getCurrentTenant();

        $allExercises = $tenant
            ? $this->bcExerciseRepository->findBy(['tenant' => $tenant])
            : $this->bcExerciseRepository->findAll();

        $now = new \DateTime();
        $startOfYear = new \DateTime('first day of January ' . $now->format('Y'));

        $upcoming = [];
        $overdue = [];
        $completedThisYear = 0;

        foreach ($allExercises as $exercise) {
            $exerciseDate = $exercise->getExerciseDate();

            // Completed this year
            if (
                $exercise->getStatus() === 'completed'
                && $exerciseDate !== null
                && $exerciseDate >= $startOfYear
                && $exerciseDate <= $now
            ) {
                $completedThisYear++;
            }

            // Upcoming (future date, still planned or in progress)
            if (
                $exerciseDate !== null
                && $exerciseDate >= $now
                && in_array($exercise->getStatus(), ['planned', 'in_progress'], true)
            ) {
                $upcoming[] = $exercise;
            }

            // Overdue (past date, still planned — never started or completed)
            if (
                $exerciseDate !== null
                && $exerciseDate < $now
                && $exercise->getStatus() === 'planned'
            ) {
                $overdue[] = $exercise;
            }
        }

        // Sort upcoming by date ascending
        usort($upcoming, fn($a, $b) => $a->getExerciseDate() <=> $b->getExerciseDate());

        // Sort overdue by date ascending (oldest first)
        usort($overdue, fn($a, $b) => $a->getExerciseDate() <=> $b->getExerciseDate());

        return [
            'upcoming' => $upcoming,
            'overdue' => $overdue,
            'completed_this_year' => $completedThisYear,
        ];
    }
}
