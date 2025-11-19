<?php

namespace App\Tests\Service;

use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\ChangeRequest;
use App\Entity\InterestedParty;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\ChangeRequestRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Service\ISOComplianceIntelligenceService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ISOComplianceIntelligenceServiceTest extends TestCase
{
    private MockObject $supplierRepository;
    private MockObject $interestedPartyRepository;
    private MockObject $bcPlanRepository;
    private MockObject $bcExerciseRepository;
    private MockObject $changeRequestRepository;
    private MockObject $riskRepository;
    private ISOComplianceIntelligenceService $service;

    protected function setUp(): void
    {
        $this->supplierRepository = $this->createMock(SupplierRepository::class);
        $this->interestedPartyRepository = $this->createMock(InterestedPartyRepository::class);
        $this->bcPlanRepository = $this->createMock(BusinessContinuityPlanRepository::class);
        $this->bcExerciseRepository = $this->createMock(BCExerciseRepository::class);
        $this->changeRequestRepository = $this->createMock(ChangeRequestRepository::class);
        $this->riskRepository = $this->createMock(RiskRepository::class);

        $this->service = new ISOComplianceIntelligenceService(
            $this->supplierRepository,
            $this->interestedPartyRepository,
            $this->bcPlanRepository,
            $this->bcExerciseRepository,
            $this->changeRequestRepository,
            $this->riskRepository
        );
    }

    public function testGetComplianceDashboardReturnsCompleteStructure(): void
    {
        $this->setupDefaultMocks();

        $dashboard = $this->service->getComplianceDashboard();

        $this->assertIsArray($dashboard);
        $this->assertArrayHasKey('iso27001', $dashboard);
        $this->assertArrayHasKey('iso22301', $dashboard);
        $this->assertArrayHasKey('iso27005', $dashboard);
        $this->assertArrayHasKey('iso31000', $dashboard);
        $this->assertArrayHasKey('overall_score', $dashboard);
        $this->assertArrayHasKey('critical_actions', $dashboard);
        $this->assertArrayHasKey('recommendations', $dashboard);
    }

    public function testGetISO27001ComplianceWithNoInterestedParties(): void
    {
        $this->supplierRepository->method('getStatistics')
            ->willReturn(['total' => 0, 'overdue_assessments' => 0, 'non_compliant' => 0]);
        $this->interestedPartyRepository->method('findAll')->willReturn([]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([]);

        $result = $this->service->getISO27001Compliance();

        // Returns rounded float, use loose comparison
        $this->assertEquals(0, $result['chapter_4_2']);
        $this->assertSame(0, $result['interested_parties_count']);
        $this->assertSame('critical', $result['status']);
    }

    public function testGetISO27001ComplianceWithInterestedParties(): void
    {
        $this->supplierRepository->method('getStatistics')
            ->willReturn(['total' => 5, 'overdue_assessments' => 0, 'non_compliant' => 0]);

        $party = $this->createMock(InterestedParty::class);
        $this->interestedPartyRepository->method('findAll')->willReturn([$party, $party]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([]);

        $result = $this->service->getISO27001Compliance();

        // Returns rounded float
        $this->assertEquals(100, $result['chapter_4_2']);
        $this->assertSame(2, $result['interested_parties_count']);
        $this->assertGreaterThanOrEqual(80, $result['score']);
    }

    public function testGetISO27001ComplianceWithOverdueCommunications(): void
    {
        $this->supplierRepository->method('getStatistics')
            ->willReturn(['total' => 5, 'overdue_assessments' => 0, 'non_compliant' => 0]);

        $party = $this->createMock(InterestedParty::class);
        $this->interestedPartyRepository->method('findAll')->willReturn([$party]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([$party]);

        $result = $this->service->getISO27001Compliance();

        $this->assertLessThan(100, $result['chapter_4_2']);
        $this->assertSame(1, $result['overdue_communications']);
    }

    public function testGetISO27001ComplianceWithSupplierIssues(): void
    {
        $this->supplierRepository->method('getStatistics')
            ->willReturn(['total' => 10, 'overdue_assessments' => 2, 'non_compliant' => 1]);

        $party = $this->createMock(InterestedParty::class);
        $this->interestedPartyRepository->method('findAll')->willReturn([$party]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([]);

        $result = $this->service->getISO27001Compliance();

        $this->assertLessThan(100, $result['annex_a_15']);
        $this->assertLessThan(100, $result['score']);
    }

    public function testGetISO22301ComplianceWithNoBCPlans(): void
    {
        $this->bcPlanRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findActivePlans')->willReturn([]);
        $this->bcPlanRepository->method('findOverdueTests')->willReturn([]);
        $this->bcPlanRepository->method('findOverdueReviews')->willReturn([]);
        $this->bcExerciseRepository->method('getStatistics')
            ->willReturn(['total' => 0, 'completed' => 0]);

        $result = $this->service->getISO22301Compliance();

        // Returns rounded float
        $this->assertEquals(20, $result['score']);
        $this->assertSame(0, $result['bc_plans_total']);
        $this->assertSame('critical', $result['status']);
    }

    public function testGetISO22301ComplianceWithActivePlans(): void
    {
        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getReadinessScore')->willReturn(85);

        $this->bcPlanRepository->method('findAll')->willReturn([$plan]);
        $this->bcPlanRepository->method('findActivePlans')->willReturn([$plan]);
        $this->bcPlanRepository->method('findOverdueTests')->willReturn([]);
        $this->bcPlanRepository->method('findOverdueReviews')->willReturn([]);
        $this->bcExerciseRepository->method('getStatistics')
            ->willReturn(['total' => 2, 'completed' => 2]);

        $result = $this->service->getISO22301Compliance();

        $this->assertGreaterThanOrEqual(80, $result['score']);
        $this->assertSame(1, $result['active_plans']);
        $this->assertSame(85, $result['readiness_level']);
    }

    public function testGetISO22301ComplianceWithOverdueTests(): void
    {
        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getReadinessScore')->willReturn(75);

        $this->bcPlanRepository->method('findAll')->willReturn([$plan]);
        $this->bcPlanRepository->method('findActivePlans')->willReturn([$plan]);
        $this->bcPlanRepository->method('findOverdueTests')->willReturn([$plan]);
        $this->bcPlanRepository->method('findOverdueReviews')->willReturn([]);
        $this->bcExerciseRepository->method('getStatistics')
            ->willReturn(['total' => 1, 'completed' => 0]);

        $result = $this->service->getISO22301Compliance();

        // Overdue test penalty should reduce score
        $this->assertLessThanOrEqual(90, $result['score']);
        $this->assertSame(1, $result['overdue_tests']);
    }

    public function testGetISO27005ComplianceWithNoRisks(): void
    {
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->getISO27005Compliance();

        // Returns rounded float
        $this->assertEquals(30, $result['score']);
        $this->assertSame(0, $result['total_risks']);
        $this->assertSame('critical', $result['status']);
    }

    public function testGetISO27005ComplianceWithAcceptedRisks(): void
    {
        $risk1 = $this->createMock(Risk::class);
        $risk1->method('getTreatmentStrategy')->willReturn('accept');
        $risk1->method('isAcceptanceApprovalRequired')->willReturn(false);

        $risk2 = $this->createMock(Risk::class);
        $risk2->method('getTreatmentStrategy')->willReturn('mitigate');

        $this->riskRepository->method('findAll')->willReturn([$risk1, $risk2]);

        $result = $this->service->getISO27005Compliance();

        // Returns rounded float
        $this->assertEquals(100, $result['score']);
        $this->assertSame(2, $result['total_risks']);
        $this->assertSame(1, $result['accepted_risks']);
    }

    public function testGetISO27005ComplianceWithPendingApprovals(): void
    {
        $risk1 = $this->createMock(Risk::class);
        $risk1->method('getTreatmentStrategy')->willReturn('accept');
        $risk1->method('isAcceptanceApprovalRequired')->willReturn(true);

        $this->riskRepository->method('findAll')->willReturn([$risk1]);

        $result = $this->service->getISO27005Compliance();

        $this->assertLessThan(100, $result['score']);
        $this->assertSame(1, $result['pending_approval']);
    }

    public function testGetISO31000ComplianceWithNoData(): void
    {
        $this->changeRequestRepository->method('getStatistics')
            ->willReturn(['total' => 0, 'pending_approval' => 0, 'overdue' => 0]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $result = $this->service->getISO31000Compliance();

        $this->assertLessThan(100, $result['score']);
        $this->assertSame(0, $result['change_requests_total']);
    }

    public function testGetISO31000ComplianceWithChangeManagement(): void
    {
        $this->changeRequestRepository->method('getStatistics')
            ->willReturn(['total' => 10, 'pending_approval' => 2, 'overdue' => 0]);

        $risk = $this->createMock(Risk::class);
        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $result = $this->service->getISO31000Compliance();

        $this->assertGreaterThanOrEqual(70, $result['score']);
        $this->assertSame(10, $result['change_requests_total']);
    }

    public function testGetISO31000ComplianceWithOverdueChanges(): void
    {
        $this->changeRequestRepository->method('getStatistics')
            ->willReturn(['total' => 5, 'pending_approval' => 0, 'overdue' => 3]);

        $risk = $this->createMock(Risk::class);
        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $result = $this->service->getISO31000Compliance();

        $this->assertLessThan(100, $result['score']);
        $this->assertSame(3, $result['overdue_changes']);
    }

    public function testCalculateOverallComplianceScore(): void
    {
        $this->setupDefaultMocks();

        $score = $this->service->calculateOverallComplianceScore();

        $this->assertIsInt($score);
        $this->assertGreaterThanOrEqual(0, $score);
        $this->assertLessThanOrEqual(100, $score);
    }

    public function testGetCriticalActionsWithSupplierIssues(): void
    {
        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getName')->willReturn('Test Supplier');
        $supplier->method('getNextAssessmentDate')->willReturn(new \DateTime('-1 day'));
        $supplier->method('calculateRiskScore')->willReturn(50);

        $this->supplierRepository->method('findOverdueAssessments')->willReturn([$supplier]);
        $this->supplierRepository->method('findCriticalSuppliers')->willReturn([]);
        $this->bcPlanRepository->method('findOverdueTests')->willReturn([]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $actions = $this->service->getCriticalActions();

        $this->assertIsArray($actions);
        $this->assertNotEmpty($actions);
        $this->assertSame('high', $actions[0]['priority']);
        $this->assertSame('supplier', $actions[0]['category']);
    }

    public function testGetCriticalActionsWithHighRiskSupplier(): void
    {
        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getName')->willReturn('Critical Supplier');
        $supplier->method('calculateRiskScore')->willReturn(85);

        $this->supplierRepository->method('findOverdueAssessments')->willReturn([]);
        $this->supplierRepository->method('findCriticalSuppliers')->willReturn([$supplier]);
        $this->bcPlanRepository->method('findOverdueTests')->willReturn([]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $actions = $this->service->getCriticalActions();

        $this->assertNotEmpty($actions);
        $criticalAction = null;
        foreach ($actions as $action) {
            if ($action['priority'] === 'critical') {
                $criticalAction = $action;
                break;
            }
        }
        $this->assertNotNull($criticalAction);
    }

    public function testGetCriticalActionsWithOverdueBCTests(): void
    {
        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getName')->willReturn('Test Plan');
        $plan->method('getNextTestDate')->willReturn(new \DateTime('-5 days'));

        $this->supplierRepository->method('findOverdueAssessments')->willReturn([]);
        $this->supplierRepository->method('findCriticalSuppliers')->willReturn([]);
        $this->bcPlanRepository->method('findOverdueTests')->willReturn([$plan]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([]);

        $actions = $this->service->getCriticalActions();

        $this->assertNotEmpty($actions);
        $bcAction = null;
        foreach ($actions as $action) {
            if ($action['category'] === 'bc') {
                $bcAction = $action;
                break;
            }
        }
        $this->assertNotNull($bcAction);
    }

    public function testGetCriticalActionsWithRiskApproval(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('isAcceptanceApprovalRequired')->willReturn(true);
        $risk->method('getTitle')->willReturn('Test Risk');
        $risk->method('getInherentRiskLevel')->willReturn(16);
        $risk->method('getTreatmentStrategy')->willReturn('accept');

        $this->supplierRepository->method('findOverdueAssessments')->willReturn([]);
        $this->supplierRepository->method('findCriticalSuppliers')->willReturn([]);
        $this->bcPlanRepository->method('findOverdueTests')->willReturn([]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([]);
        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $actions = $this->service->getCriticalActions();

        $this->assertNotEmpty($actions);
        $riskAction = null;
        foreach ($actions as $action) {
            if ($action['category'] === 'risk') {
                $riskAction = $action;
                break;
            }
        }
        $this->assertNotNull($riskAction);
    }

    public function testGetRecommendationsForLowCompliance(): void
    {
        $this->setupLowComplianceMocks();

        $recommendations = $this->service->getRecommendations();

        $this->assertIsArray($recommendations);
        $this->assertNotEmpty($recommendations);
    }

    public function testGetRecommendationsIncludesBCExercises(): void
    {
        // Override default mocks to trigger exercise recommendation
        $this->supplierRepository->method('getStatistics')
            ->willReturn(['total' => 5, 'overdue_assessments' => 0, 'non_compliant' => 0]);
        $this->supplierRepository->method('findOverdueAssessments')->willReturn([]);
        $this->supplierRepository->method('findCriticalSuppliers')->willReturn([]);

        $party = $this->createMock(InterestedParty::class);
        $this->interestedPartyRepository->method('findAll')->willReturn([$party]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([]);

        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getReadinessScore')->willReturn(90);
        $this->bcPlanRepository->method('findAll')->willReturn([$plan]);
        $this->bcPlanRepository->method('findActivePlans')->willReturn([$plan]);
        $this->bcPlanRepository->method('findOverdueTests')->willReturn([]);
        $this->bcPlanRepository->method('findOverdueReviews')->willReturn([]);

        // Low exercise count triggers recommendation
        $this->bcExerciseRepository->method('getStatistics')
            ->willReturn(['total' => 1, 'completed' => 0]);

        $this->changeRequestRepository->method('getStatistics')
            ->willReturn(['total' => 10, 'pending_approval' => 2, 'overdue' => 0]);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTreatmentStrategy')->willReturn('mitigate');
        $risk->method('isAcceptanceApprovalRequired')->willReturn(false);
        $this->riskRepository->method('findAll')->willReturn([$risk]);

        $recommendations = $this->service->getRecommendations();

        $hasExerciseRec = false;
        foreach ($recommendations as $rec) {
            if (str_contains($rec['recommendation'], 'BC exercise')) {
                $hasExerciseRec = true;
                break;
            }
        }
        $this->assertTrue($hasExerciseRec);
    }

    private function setupDefaultMocks(): void
    {
        $this->supplierRepository->method('getStatistics')
            ->willReturn(['total' => 5, 'overdue_assessments' => 0, 'non_compliant' => 0]);
        $this->supplierRepository->method('findOverdueAssessments')->willReturn([]);
        $this->supplierRepository->method('findCriticalSuppliers')->willReturn([]);

        $party = $this->createMock(InterestedParty::class);
        $this->interestedPartyRepository->method('findAll')->willReturn([$party]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([]);

        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getReadinessScore')->willReturn(90);
        $this->bcPlanRepository->method('findAll')->willReturn([$plan]);
        $this->bcPlanRepository->method('findActivePlans')->willReturn([$plan]);
        $this->bcPlanRepository->method('findOverdueTests')->willReturn([]);
        $this->bcPlanRepository->method('findOverdueReviews')->willReturn([]);

        $this->bcExerciseRepository->method('getStatistics')
            ->willReturn(['total' => 3, 'completed' => 2]);

        $this->changeRequestRepository->method('getStatistics')
            ->willReturn(['total' => 10, 'pending_approval' => 2, 'overdue' => 0]);

        $risk = $this->createMock(Risk::class);
        $risk->method('getTreatmentStrategy')->willReturn('mitigate');
        $risk->method('isAcceptanceApprovalRequired')->willReturn(false);
        $this->riskRepository->method('findAll')->willReturn([$risk]);
    }

    private function setupLowComplianceMocks(): void
    {
        $this->supplierRepository->method('getStatistics')
            ->willReturn(['total' => 10, 'overdue_assessments' => 5, 'non_compliant' => 3]);
        $this->supplierRepository->method('findOverdueAssessments')->willReturn([]);
        $this->supplierRepository->method('findCriticalSuppliers')->willReturn([]);

        $this->interestedPartyRepository->method('findAll')->willReturn([]);
        $this->interestedPartyRepository->method('findOverdueCommunications')->willReturn([]);

        $this->bcPlanRepository->method('findAll')->willReturn([]);
        $this->bcPlanRepository->method('findActivePlans')->willReturn([]);
        $this->bcPlanRepository->method('findOverdueTests')->willReturn([]);
        $this->bcPlanRepository->method('findOverdueReviews')->willReturn([]);

        $this->bcExerciseRepository->method('getStatistics')
            ->willReturn(['total' => 0, 'completed' => 0]);

        $this->changeRequestRepository->method('getStatistics')
            ->willReturn(['total' => 0, 'pending_approval' => 0, 'overdue' => 0]);

        $this->riskRepository->method('findAll')->willReturn([]);
    }
}
