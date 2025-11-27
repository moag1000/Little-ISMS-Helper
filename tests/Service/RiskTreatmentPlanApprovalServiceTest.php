<?php

namespace App\Tests\Service;

use App\Entity\RiskTreatmentPlan;
use App\Entity\Risk;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Repository\UserRepository;
use App\Service\RiskTreatmentPlanApprovalService;
use App\Service\WorkflowService;
use App\Service\EmailNotificationService;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Test suite for RiskTreatmentPlanApprovalService
 *
 * Tests the approval workflow logic for risk treatment plans based on cost thresholds.
 */
class RiskTreatmentPlanApprovalServiceTest extends TestCase
{
    private RiskTreatmentPlanApprovalService $service;
    private EntityManagerInterface&MockObject $entityManager;
    private WorkflowService&MockObject $workflowService;
    private EmailNotificationService&MockObject $emailService;
    private UserRepository&MockObject $userRepository;
    private AuditLogger&MockObject $auditLogger;
    private LoggerInterface&MockObject $logger;
    private UrlGeneratorInterface&MockObject $urlGenerator;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->workflowService = $this->createMock(WorkflowService::class);
        $this->emailService = $this->createMock(EmailNotificationService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);

        $this->service = new RiskTreatmentPlanApprovalService(
            $this->entityManager,
            $this->workflowService,
            $this->emailService,
            $this->userRepository,
            $this->auditLogger,
            $this->logger,
            $this->urlGenerator
        );
    }

    /**
     * Test low-cost plan approval (< €10k) - Risk Manager only
     */
    public function testRequestApprovalLowCost(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(5000); // €5,000
        $riskManager = $this->createUser('risk_manager@test.com', ['ROLE_RISK_MANAGER']);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(1);

        $this->userRepository->method('findByRole')
            ->willReturnMap([
                ['ROLE_RISK_MANAGER', [$riskManager]],
            ]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn(null);

        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willReturn($this->createMock(\App\Entity\Workflow::class));

        $this->workflowService->method('startWorkflow')
            ->willReturn($workflowInstance);

        $this->urlGenerator->method('generate')
            ->willReturn('http://test.com/plan/1');

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertSame('low_cost', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
        $this->assertSame(1, $result['workflow_id']);
        $this->assertSame(48, $result['sla_hours']); // 2 days SLA
    }

    /**
     * Test medium-cost plan approval (€10k - €50k) - Risk Manager + CISO
     */
    public function testRequestApprovalMediumCost(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(25000); // €25,000
        $riskManager = $this->createUser('risk_manager@test.com', ['ROLE_RISK_MANAGER']);
        $ciso = $this->createUser('ciso@test.com', ['ROLE_CISO']);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(2);

        $this->userRepository->method('findByRole')
            ->willReturnMap([
                ['ROLE_RISK_MANAGER', [$riskManager]],
                ['ROLE_CISO', [$ciso]],
            ]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn(null);

        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willReturn($this->createMock(\App\Entity\Workflow::class));

        $this->workflowService->method('startWorkflow')
            ->willReturn($workflowInstance);

        $this->urlGenerator->method('generate')
            ->willReturn('http://test.com/plan/2');

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertSame('medium_cost', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
        $this->assertSame(2, $result['approvers_count']);
        $this->assertSame(72, $result['sla_hours']); // 3 days SLA
    }

    /**
     * Test high-cost plan approval (> €50k) - Risk Manager + CISO + Management
     */
    public function testRequestApprovalHighCost(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(100000); // €100,000
        $riskManager = $this->createUser('risk_manager@test.com', ['ROLE_RISK_MANAGER']);
        $ciso = $this->createUser('ciso@test.com', ['ROLE_CISO']);
        $management = $this->createUser('ceo@test.com', ['ROLE_MANAGEMENT']);
        $workflowInstance = $this->createMock(WorkflowInstance::class);
        $workflowInstance->method('getId')->willReturn(3);

        $this->userRepository->method('findByRole')
            ->willReturnMap([
                ['ROLE_RISK_MANAGER', [$riskManager]],
                ['ROLE_CISO', [$ciso]],
                ['ROLE_MANAGEMENT', [$management]],
            ]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn(null);

        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willReturn($this->createMock(\App\Entity\Workflow::class));

        $this->workflowService->method('startWorkflow')
            ->willReturn($workflowInstance);

        $this->urlGenerator->method('generate')
            ->willReturn('http://test.com/plan/3');

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertSame('high_cost', $result['approval_level']);
        $this->assertTrue($result['workflow_started']);
        $this->assertSame(3, $result['approvers_count']);
        $this->assertSame(120, $result['sla_hours']); // 5 days SLA
    }

    /**
     * Test approval request when no approvers found
     */
    public function testRequestApprovalNoApproversFound(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(5000);

        $this->userRepository->method('findByRole')
            ->willReturn([]); // No users found

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('no_approvers_found', $result['reason']);
    }

    /**
     * Test approval request when workflow already active
     */
    public function testRequestApprovalWorkflowAlreadyActive(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(5000);
        $riskManager = $this->createUser('risk_manager@test.com', ['ROLE_RISK_MANAGER']);
        $existingWorkflow = $this->createMock(WorkflowInstance::class);
        $existingWorkflow->method('getId')->willReturn(99);

        $this->userRepository->method('findByRole')
            ->willReturn([$riskManager]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn($existingWorkflow);

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('workflow_already_active', $result['reason']);
        $this->assertSame(99, $result['workflow_id']);
    }

    /**
     * Test approval request handles exception gracefully
     */
    public function testRequestApprovalHandlesException(): void
    {
        // Arrange
        $plan = $this->createTreatmentPlan(5000);
        $riskManager = $this->createUser('risk_manager@test.com', ['ROLE_RISK_MANAGER']);

        $this->userRepository->method('findByRole')
            ->willReturn([$riskManager]);

        $this->workflowService->method('getActiveWorkflowForEntity')
            ->willReturn(null);

        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willThrowException(new \Exception('Database error'));

        // Act
        $result = $this->service->requestApproval($plan);

        // Assert
        $this->assertFalse($result['workflow_started']);
        $this->assertSame('workflow_creation_failed', $result['reason']);
        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Test cost threshold boundaries
     */
    public function testCostThresholdBoundaries(): void
    {
        // Test exactly at €10k threshold (should be medium_cost)
        $plan10k = $this->createTreatmentPlan(10000);
        $riskManager = $this->createUser('rm@test.com', ['ROLE_RISK_MANAGER']);
        $ciso = $this->createUser('ciso@test.com', ['ROLE_CISO']);

        $this->userRepository->method('findByRole')
            ->willReturnMap([
                ['ROLE_RISK_MANAGER', [$riskManager]],
                ['ROLE_CISO', [$ciso]],
            ]);

        $this->workflowService->method('getActiveWorkflowForEntity')->willReturn(null);
        $this->workflowService->method('findOrCreateWorkflowDefinition')
            ->willReturn($this->createMock(\App\Entity\Workflow::class));
        $this->workflowService->method('startWorkflow')
            ->willReturn($this->createMock(WorkflowInstance::class));
        $this->urlGenerator->method('generate')->willReturn('http://test.com/plan');

        $result = $this->service->requestApproval($plan10k);
        $this->assertSame('medium_cost', $result['approval_level']);

        // Test exactly at €50k threshold (should be high_cost)
        $plan50k = $this->createTreatmentPlan(50000);
        $management = $this->createUser('ceo@test.com', ['ROLE_MANAGEMENT']);

        $this->userRepository->method('findByRole')
            ->willReturnMap([
                ['ROLE_RISK_MANAGER', [$riskManager]],
                ['ROLE_CISO', [$ciso]],
                ['ROLE_MANAGEMENT', [$management]],
            ]);

        $result = $this->service->requestApproval($plan50k);
        $this->assertSame('high_cost', $result['approval_level']);
    }

    /**
     * Helper: Create treatment plan mock
     */
    private function createTreatmentPlan(float $estimatedCost): RiskTreatmentPlan
    {
        $plan = $this->createMock(RiskTreatmentPlan::class);
        $plan->method('getId')->willReturn(1);
        $plan->method('getBudget')->willReturn((string)$estimatedCost);

        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $plan->method('getRisk')->willReturn($risk);

        return $plan;
    }

    /**
     * Helper: Create user mock
     */
    private function createUser(string $email, array $roles): User
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn($email);
        $user->method('getId')->willReturn(rand(1, 1000));
        $user->method('getRoles')->willReturnCallback(
            fn() => $roles
        );

        return $user;
    }
}
