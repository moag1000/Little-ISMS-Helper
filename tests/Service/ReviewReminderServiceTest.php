<?php

namespace App\Tests\Service;

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
use App\Service\EmailNotificationService;
use App\Service\ReviewReminderService;
use DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReviewReminderServiceTest extends TestCase
{
    private MockObject $riskRepository;
    private MockObject $bcPlanRepository;
    private MockObject $processingActivityRepository;
    private MockObject $dpiaRepository;
    private MockObject $dataBreachRepository;
    private MockObject $userRepository;
    private MockObject $emailNotificationService;
    private MockObject $logger;
    private ReviewReminderService $service;

    protected function setUp(): void
    {
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->bcPlanRepository = $this->createMock(BusinessContinuityPlanRepository::class);
        $this->processingActivityRepository = $this->createMock(ProcessingActivityRepository::class);
        $this->dpiaRepository = $this->createMock(DataProtectionImpactAssessmentRepository::class);
        $this->dataBreachRepository = $this->createMock(DataBreachRepository::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->emailNotificationService = $this->createMock(EmailNotificationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ReviewReminderService(
            $this->riskRepository,
            $this->bcPlanRepository,
            $this->processingActivityRepository,
            $this->dpiaRepository,
            $this->dataBreachRepository,
            $this->userRepository,
            $this->emailNotificationService,
            $this->logger
        );
    }

    // ========== getAllOverdueReviews TESTS ==========

    public function testGetAllOverdueReviewsReturnsAllCategories(): void
    {
        $this->setupEmptyRepositories();

        $result = $this->service->getAllOverdueReviews();

        $this->assertArrayHasKey('risks', $result);
        $this->assertArrayHasKey('bc_plans', $result);
        $this->assertArrayHasKey('processing_activities', $result);
        $this->assertArrayHasKey('dpias', $result);
        $this->assertArrayHasKey('data_breaches', $result);
        $this->assertArrayHasKey('summary', $result);
    }

    public function testGetAllOverdueReviewsSummaryCalculation(): void
    {
        // Set up 2 overdue risks
        $overdueRisk1 = $this->createOverdueRisk();
        $overdueRisk2 = $this->createOverdueRisk();
        $this->riskRepository->method('findAll')->willReturn([$overdueRisk1, $overdueRisk2]);

        // Set up 1 overdue BC plan
        $overdueBcPlan = $this->createOverdueBcPlan();
        $this->bcPlanRepository->method('findBy')->willReturn([$overdueBcPlan]);

        $this->processingActivityRepository->method('findBy')->willReturn([]);
        $this->dpiaRepository->method('findBy')->willReturn([]);
        $this->dataBreachRepository->method('findBy')->willReturn([]);

        $result = $this->service->getAllOverdueReviews();

        $this->assertSame(3, $result['summary']['total_overdue']);
        $this->assertSame(2, $result['summary']['risks_count']);
        $this->assertSame(1, $result['summary']['bc_plans_count']);
    }

    // ========== getOverdueRiskReviews TESTS ==========

    public function testGetOverdueRiskReviewsFiltersCorrectly(): void
    {
        $overdueRisk = $this->createOverdueRisk();
        $futureRisk = $this->createFutureRisk();
        $closedRisk = $this->createClosedRisk();
        $noDateRisk = $this->createRiskWithoutDate();

        $this->riskRepository->method('findAll')
            ->willReturn([$overdueRisk, $futureRisk, $closedRisk, $noDateRisk]);

        $result = $this->service->getOverdueRiskReviews();

        $this->assertCount(1, $result);
        $this->assertContains($overdueRisk, $result);
    }

    public function testGetOverdueRiskReviewsExcludesAcceptedRisks(): void
    {
        $acceptedRisk = $this->createMock(Risk::class);
        $acceptedRisk->method('getReviewDate')->willReturn(new DateTime('-1 week'));
        $acceptedRisk->method('getStatus')->willReturn('accepted');

        $this->riskRepository->method('findAll')->willReturn([$acceptedRisk]);

        $result = $this->service->getOverdueRiskReviews();

        $this->assertCount(0, $result);
    }

    // ========== getOverdueBcPlanReviews TESTS ==========

    public function testGetOverdueBcPlanReviewsDetectsOverdue(): void
    {
        $overduePlan = $this->createOverdueBcPlan();
        $currentPlan = $this->createMock(BusinessContinuityPlan::class);
        $currentPlan->method('isReviewOverdue')->willReturn(false);
        $currentPlan->method('isTestOverdue')->willReturn(false);

        $this->bcPlanRepository->method('findBy')
            ->with(['status' => 'active'])
            ->willReturn([$overduePlan, $currentPlan]);

        $result = $this->service->getOverdueBcPlanReviews();

        $this->assertCount(1, $result);
        $this->assertContains($overduePlan, $result);
    }

    public function testGetOverdueBcPlanReviewsDetectsTestOverdue(): void
    {
        $testOverduePlan = $this->createMock(BusinessContinuityPlan::class);
        $testOverduePlan->method('isReviewOverdue')->willReturn(false);
        $testOverduePlan->method('isTestOverdue')->willReturn(true);

        $this->bcPlanRepository->method('findBy')->willReturn([$testOverduePlan]);

        $result = $this->service->getOverdueBcPlanReviews();

        $this->assertCount(1, $result);
    }

    // ========== getOverdueProcessingActivityReviews TESTS ==========

    public function testGetOverdueProcessingActivityReviewsFiltersCorrectly(): void
    {
        $overdueActivity = $this->createMock(ProcessingActivity::class);
        $overdueActivity->method('getNextReviewDate')->willReturn(new DateTime('-1 week'));

        $futureActivity = $this->createMock(ProcessingActivity::class);
        $futureActivity->method('getNextReviewDate')->willReturn(new DateTime('+1 month'));

        $this->processingActivityRepository->method('findBy')
            ->with(['status' => 'active'])
            ->willReturn([$overdueActivity, $futureActivity]);

        $result = $this->service->getOverdueProcessingActivityReviews();

        $this->assertCount(1, $result);
        $this->assertContains($overdueActivity, $result);
    }

    // ========== getOverdueDpiaReviews TESTS ==========

    public function testGetOverdueDpiaReviewsFiltersCorrectly(): void
    {
        $overdueDpia = $this->createMock(DataProtectionImpactAssessment::class);
        $overdueDpia->method('getNextReviewDate')->willReturn(new DateTime('-2 weeks'));

        $futureDpia = $this->createMock(DataProtectionImpactAssessment::class);
        $futureDpia->method('getNextReviewDate')->willReturn(new DateTime('+6 months'));

        $this->dpiaRepository->method('findBy')
            ->with(['status' => 'approved'])
            ->willReturn([$overdueDpia, $futureDpia]);

        $result = $this->service->getOverdueDpiaReviews();

        $this->assertCount(1, $result);
        $this->assertContains($overdueDpia, $result);
    }

    // ========== getUrgentDataBreaches TESTS ==========

    public function testGetUrgentDataBreachesFiltersNotified(): void
    {
        $notifiedBreach = $this->createMock(DataBreach::class);
        $notifiedBreach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(new \DateTimeImmutable());
        $notifiedBreach->method('getHoursUntilAuthorityDeadline')->willReturn(10);

        $this->dataBreachRepository->method('findBy')
            ->with(['requiresAuthorityNotification' => true])
            ->willReturn([$notifiedBreach]);

        $result = $this->service->getUrgentDataBreaches();

        $this->assertCount(0, $result);
    }

    public function testGetUrgentDataBreachesIncludesUrgent(): void
    {
        $urgentBreach = $this->createMock(DataBreach::class);
        $urgentBreach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(null);
        $urgentBreach->method('getHoursUntilAuthorityDeadline')->willReturn(12); // Within 24h threshold

        $this->dataBreachRepository->method('findBy')
            ->with(['requiresAuthorityNotification' => true])
            ->willReturn([$urgentBreach]);

        $result = $this->service->getUrgentDataBreaches();

        $this->assertCount(1, $result);
    }

    public function testGetUrgentDataBreachesIncludesOverdue(): void
    {
        $overdueBreach = $this->createMock(DataBreach::class);
        $overdueBreach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(null);
        $overdueBreach->method('getHoursUntilAuthorityDeadline')->willReturn(-5); // Overdue

        $this->dataBreachRepository->method('findBy')
            ->with(['requiresAuthorityNotification' => true])
            ->willReturn([$overdueBreach]);

        $result = $this->service->getUrgentDataBreaches();

        $this->assertCount(1, $result);
    }

    public function testGetUrgentDataBreachesExcludesNotUrgent(): void
    {
        $notUrgentBreach = $this->createMock(DataBreach::class);
        $notUrgentBreach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(null);
        $notUrgentBreach->method('getHoursUntilAuthorityDeadline')->willReturn(48); // Still has time

        $this->dataBreachRepository->method('findBy')
            ->with(['requiresAuthorityNotification' => true])
            ->willReturn([$notUrgentBreach]);

        $result = $this->service->getUrgentDataBreaches();

        $this->assertCount(0, $result);
    }

    public function testGetUrgentDataBreachesCustomThreshold(): void
    {
        $breach = $this->createMock(DataBreach::class);
        $breach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(null);
        $breach->method('getHoursUntilAuthorityDeadline')->willReturn(36);

        $this->dataBreachRepository->method('findBy')
            ->with(['requiresAuthorityNotification' => true])
            ->willReturn([$breach]);

        // Default threshold 24h - not urgent
        $result24 = $this->service->getUrgentDataBreaches(24);
        $this->assertCount(0, $result24);

        // Custom threshold 48h - urgent
        $result48 = $this->service->getUrgentDataBreaches(48);
        $this->assertCount(1, $result48);
    }

    // ========== getUpcomingReviews TESTS ==========

    public function testGetUpcomingReviewsReturnsAllCategories(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findBy')->willReturn([]);
        $this->processingActivityRepository->method('findBy')->willReturn([]);
        $this->dpiaRepository->method('findBy')->willReturn([]);

        $result = $this->service->getUpcomingReviews();

        $this->assertArrayHasKey('risks', $result);
        $this->assertArrayHasKey('bc_plans', $result);
        $this->assertArrayHasKey('processing_activities', $result);
        $this->assertArrayHasKey('dpias', $result);
    }

    public function testGetUpcomingReviewsFindsUpcomingRisks(): void
    {
        $upcomingRisk = $this->createMock(Risk::class);
        $upcomingRisk->method('getReviewDate')->willReturn(new DateTime('+7 days'));
        $upcomingRisk->method('getStatus')->willReturn('open');

        $farFutureRisk = $this->createMock(Risk::class);
        $farFutureRisk->method('getReviewDate')->willReturn(new DateTime('+60 days'));
        $farFutureRisk->method('getStatus')->willReturn('open');

        $this->riskRepository->method('findAll')->willReturn([$upcomingRisk, $farFutureRisk]);
        $this->bcPlanRepository->method('findBy')->willReturn([]);
        $this->processingActivityRepository->method('findBy')->willReturn([]);
        $this->dpiaRepository->method('findBy')->willReturn([]);

        $result = $this->service->getUpcomingReviews(14); // 14 days

        $this->assertCount(1, $result['risks']);
        $this->assertContains($upcomingRisk, $result['risks']);
    }

    // ========== getDashboardStatistics TESTS ==========

    public function testGetDashboardStatisticsReturnsAllKeys(): void
    {
        $this->setupEmptyRepositories();

        $result = $this->service->getDashboardStatistics();

        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('critical', $result);
        $this->assertArrayHasKey('urgent_breaches', $result);
        $this->assertArrayHasKey('by_type', $result);
        $this->assertArrayHasKey('by_days_overdue', $result);
    }

    public function testGetDashboardStatisticsCountsCriticalItems(): void
    {
        // 1 urgent breach
        $urgentBreach = $this->createMock(DataBreach::class);
        $urgentBreach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(null);
        $urgentBreach->method('getHoursUntilAuthorityDeadline')->willReturn(10);
        $this->dataBreachRepository->method('findBy')->willReturn([$urgentBreach]);

        // 1 high risk that is overdue
        $highRisk = $this->createMock(Risk::class);
        $highRisk->method('getReviewDate')->willReturn(new DateTime('-3 days'));
        $highRisk->method('getStatus')->willReturn('open');
        $highRisk->method('isHighRisk')->willReturn(true);
        $this->riskRepository->method('findAll')->willReturn([$highRisk]);

        $this->bcPlanRepository->method('findBy')->willReturn([]);
        $this->processingActivityRepository->method('findBy')->willReturn([]);
        $this->dpiaRepository->method('findBy')->willReturn([]);

        $result = $this->service->getDashboardStatistics();

        // 1 urgent breach + 1 high risk = 2 critical
        $this->assertSame(2, $result['critical']);
    }

    public function testGetDashboardStatisticsCategorizesByDaysOverdue(): void
    {
        $risk3Days = $this->createMock(Risk::class);
        $risk3Days->method('getReviewDate')->willReturn(new DateTime('-3 days'));
        $risk3Days->method('getStatus')->willReturn('open');
        $risk3Days->method('isHighRisk')->willReturn(false);

        $risk15Days = $this->createMock(Risk::class);
        $risk15Days->method('getReviewDate')->willReturn(new DateTime('-15 days'));
        $risk15Days->method('getStatus')->willReturn('open');
        $risk15Days->method('isHighRisk')->willReturn(false);

        $risk60Days = $this->createMock(Risk::class);
        $risk60Days->method('getReviewDate')->willReturn(new DateTime('-60 days'));
        $risk60Days->method('getStatus')->willReturn('open');
        $risk60Days->method('isHighRisk')->willReturn(false);

        $risk120Days = $this->createMock(Risk::class);
        $risk120Days->method('getReviewDate')->willReturn(new DateTime('-120 days'));
        $risk120Days->method('getStatus')->willReturn('open');
        $risk120Days->method('isHighRisk')->willReturn(false);

        $this->riskRepository->method('findAll')
            ->willReturn([$risk3Days, $risk15Days, $risk60Days, $risk120Days]);

        $this->bcPlanRepository->method('findBy')->willReturn([]);
        $this->processingActivityRepository->method('findBy')->willReturn([]);
        $this->dpiaRepository->method('findBy')->willReturn([]);
        $this->dataBreachRepository->method('findBy')->willReturn([]);

        $result = $this->service->getDashboardStatistics();

        $this->assertSame(1, $result['by_days_overdue']['0-7']);
        $this->assertSame(1, $result['by_days_overdue']['8-30']);
        $this->assertSame(1, $result['by_days_overdue']['31-90']);
        $this->assertSame(1, $result['by_days_overdue']['90+']);
    }

    // ========== Helper Methods ==========

    private function setupEmptyRepositories(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findBy')->willReturn([]);
        $this->processingActivityRepository->method('findBy')->willReturn([]);
        $this->dpiaRepository->method('findBy')->willReturn([]);
        $this->dataBreachRepository->method('findBy')->willReturn([]);
    }

    private function createOverdueRisk(): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getReviewDate')->willReturn(new DateTime('-1 week'));
        $risk->method('getStatus')->willReturn('open');
        return $risk;
    }

    private function createFutureRisk(): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getReviewDate')->willReturn(new DateTime('+1 month'));
        $risk->method('getStatus')->willReturn('open');
        return $risk;
    }

    private function createClosedRisk(): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getReviewDate')->willReturn(new DateTime('-1 week'));
        $risk->method('getStatus')->willReturn('closed');
        return $risk;
    }

    private function createRiskWithoutDate(): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getReviewDate')->willReturn(null);
        $risk->method('getStatus')->willReturn('open');
        return $risk;
    }

    private function createOverdueBcPlan(): MockObject
    {
        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('isReviewOverdue')->willReturn(true);
        $plan->method('isTestOverdue')->willReturn(false);
        return $plan;
    }
}
