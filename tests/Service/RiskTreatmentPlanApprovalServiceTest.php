<?php

namespace App\Tests\Service;

use App\Entity\RiskTreatmentPlan;
use App\Entity\Risk;
use App\Entity\WorkflowInstance;
use App\Repository\UserRepository;
use App\Service\RiskTreatmentPlanApprovalService;
use App\Service\WorkflowService;
use App\Service\EmailNotificationService;
use App\Service\AuditLogger;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

/**
 * Test suite for RiskTreatmentPlanApprovalService
 *
 * Tests the approval workflow triggering logic for risk treatment plans.
 */
class RiskTreatmentPlanApprovalServiceTest extends TestCase
{
    private RiskTreatmentPlanApprovalService $service;
    private WorkflowService&MockObject $workflowService;
    private EmailNotificationService&MockObject $emailService;
    private UserRepository&MockObject $userRepository;
    private AuditLogger&MockObject $auditLogger;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->workflowService = $this->createMock(WorkflowService::class);
        $this->emailService = $this->createMock(EmailNotificationService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new RiskTreatmentPlanApprovalService(
            $this->workflowService,
            $this->emailService,
            $this->userRepository,
            $this->auditLogger,
            $this->logger
        );
    }

    /**
     * Test low-cost plan approval (< €10k)
     */
    public function testRequestApprovalLowCost(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(5000); // €5,000
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(1);
        $workflowInstance->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')
            ->with('RiskTreatmentPlan', 1)
            ->willReturn(null); // No existing workflow

        $this->workflowService->method('startWorkflow')
            ->with('RiskTreatmentPlan', 1, 'risk_treatment_plan_approval')
            ->willReturn($workflowInstance);

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertSame('low_cost', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
        $this->assertSame(1, $result['workflow_id']);
    }

    /**
     * Test medium-cost plan approval (€10k - €50k)
     */
    public function testRequestApprovalMediumCost(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(25000); // €25,000
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(2);
        $workflowInstance->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')->willReturn($workflowInstance);

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertSame('medium_cost', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
    }

    /**
     * Test high-cost plan approval (> €50k)
     */
    public function testRequestApprovalHighCost(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(100000); // €100,000
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(3);
        $workflowInstance->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')->willReturn($workflowInstance);

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertSame('high_cost', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
    }

    /**
     * Test approval request when workflow already active
     */
    public function testRequestApprovalWorkflowAlreadyActive(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(5000);
        $existingWorkflow = $this->createMock(WorkflowInstance::class);
        $existingWorkflow->method('getId')->willReturn(99);
        $existingWorkflow->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')
            ->willReturn($existingWorkflow);

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('workflow_already_active', $result['reason']);
        $this->assertSame(99, $result['workflow_id']);
    }

    /**
     * Test approval request when no workflow definition exists
     */
    public function testRequestApprovalNoWorkflowDefinition(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(5000);

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')->willReturn(null); // No definition found

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('no_workflow_definition', $result['reason']);
        $this->assertArrayHasKey('message', $result);
    }

    /**
     * Test approval request handles exception gracefully
     */
    public function testRequestApprovalHandlesException(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(5000);

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')
            ->willThrowException(new \Exception('Database error'));

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('workflow_start_failed', $result['reason']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test cost threshold boundaries
     */
    public function testCostThresholdBoundaries(): void
    {
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getStatus')->willReturn('in_progress');

        $this->workflowService->method('getWorkflowInstance')->willReturn(null);
        $this->workflowService->method('startWorkflow')->willReturn($workflowInstance);

        // Test exactly at €10k threshold (should be medium_cost)
        $plan10k = $this->createTreatmentPlan(10000);
        $result = $this->service->requestApproval($plan10k);
        $this->assertSame('medium_cost', $result['approval_level']);

        // Test exactly at €50k threshold (should be high_cost)
        $plan50k = $this->createTreatmentPlan(50000);
        $result = $this->service->requestApproval($plan50k);
        $this->assertSame('high_cost', $result['approval_level']);
    }

    /**
     * Helper: Create treatment plan mock
     */
    private function createTreatmentPlan(float $budget): RiskTreatmentPlan
    {
        $plan = $this->createMock(RiskTreatmentPlan::class);
        $plan->method('getId')->willReturn(1);
        $plan->method('getBudget')->willReturn((string)$budget);

        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $plan->method('getRisk')->willReturn($risk);

        return $plan;
    }
}
