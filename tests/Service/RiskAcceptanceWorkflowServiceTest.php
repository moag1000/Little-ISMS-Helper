<?php

namespace App\Tests\Service;

use App\Entity\Risk;
use App\Entity\RiskAppetite;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\EmailNotificationService;
use App\Service\RiskAcceptanceWorkflowService;
use App\Service\RiskAppetitePrioritizationService;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RiskAcceptanceWorkflowServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $riskAppetiteService;
    private MockObject $workflowService;
    private MockObject $emailNotificationService;
    private MockObject $userRepository;
    private MockObject $auditLogger;
    private MockObject $logger;
    private RiskAcceptanceWorkflowService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->riskAppetiteService = $this->createMock(RiskAppetitePrioritizationService::class);
        $this->workflowService = $this->createMock(WorkflowService::class);
        $this->emailNotificationService = $this->createMock(EmailNotificationService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new RiskAcceptanceWorkflowService(
            $this->entityManager,
            $this->riskAppetiteService,
            $this->workflowService,
            $this->emailNotificationService,
            $this->userRepository,
            $this->auditLogger,
            $this->logger
        );
    }

    public function testDetermineApprovalLevelAutomaticForLowScore(): void
    {
        $risk = $this->createRiskWithResidualLevel(3);

        $result = $this->service->determineApprovalLevel($risk);

        $this->assertSame('automatic', $result);
    }

    public function testDetermineApprovalLevelAutomaticForMinimumScore(): void
    {
        $risk = $this->createRiskWithResidualLevel(1);

        $result = $this->service->determineApprovalLevel($risk);

        $this->assertSame('automatic', $result);
    }

    public function testDetermineApprovalLevelManagerForMediumScore(): void
    {
        $risk = $this->createRiskWithResidualLevel(4);

        $result = $this->service->determineApprovalLevel($risk);

        $this->assertSame('manager', $result);
    }

    public function testDetermineApprovalLevelManagerForScore7(): void
    {
        $risk = $this->createRiskWithResidualLevel(7);

        $result = $this->service->determineApprovalLevel($risk);

        $this->assertSame('manager', $result);
    }

    public function testDetermineApprovalLevelExecutiveForHighScore(): void
    {
        $risk = $this->createRiskWithResidualLevel(8);

        $result = $this->service->determineApprovalLevel($risk);

        $this->assertSame('executive', $result);
    }

    public function testDetermineApprovalLevelExecutiveForMaxScore(): void
    {
        $risk = $this->createRiskWithResidualLevel(25);

        $result = $this->service->determineApprovalLevel($risk);

        $this->assertSame('executive', $result);
    }

    public function testRequestAcceptanceThrowsExceptionForNonAcceptStrategy(): void
    {
        $risk = $this->createRisk();
        $risk->method('getTreatmentStrategy')->willReturn('mitigate');

        $user = $this->createUser();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Risk must have "accept" treatment strategy');

        $this->service->requestAcceptance($risk, $user, 'Test justification');
    }

    public function testRequestAcceptanceThrowsExceptionWithoutProbability(): void
    {
        $risk = $this->createRisk();
        $risk->method('getTreatmentStrategy')->willReturn('accept');
        $risk->method('getProbability')->willReturn(null);
        $risk->method('getImpact')->willReturn(3);

        $user = $this->createUser();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Risk assessment must be completed before acceptance');

        $this->service->requestAcceptance($risk, $user, 'Test justification');
    }

    public function testRequestAcceptanceThrowsExceptionWithoutImpact(): void
    {
        $risk = $this->createRisk();
        $risk->method('getTreatmentStrategy')->willReturn('accept');
        $risk->method('getProbability')->willReturn(3);
        $risk->method('getImpact')->willReturn(null);

        $user = $this->createUser();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Risk assessment must be completed before acceptance');

        $this->service->requestAcceptance($risk, $user, 'Test justification');
    }

    public function testRequestAcceptanceThrowsExceptionWithoutResidualProbability(): void
    {
        $risk = $this->createRisk();
        $risk->method('getTreatmentStrategy')->willReturn('accept');
        $risk->method('getProbability')->willReturn(3);
        $risk->method('getImpact')->willReturn(3);
        $risk->method('getResidualProbability')->willReturn(null);
        $risk->method('getResidualImpact')->willReturn(2);

        $user = $this->createUser();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Residual risk must be assessed before acceptance');

        $this->service->requestAcceptance($risk, $user, 'Test justification');
    }

    public function testRequestAcceptanceThrowsExceptionIfAlreadyAccepted(): void
    {
        $risk = $this->createRisk();
        $risk->method('getTreatmentStrategy')->willReturn('accept');
        $risk->method('getProbability')->willReturn(3);
        $risk->method('getImpact')->willReturn(3);
        $risk->method('getResidualProbability')->willReturn(2);
        $risk->method('getResidualImpact')->willReturn(2);
        $risk->method('isFormallyAccepted')->willReturn(true);

        $user = $this->createUser();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Risk is already formally accepted');

        $this->service->requestAcceptance($risk, $user, 'Test justification');
    }

    public function testRequestAcceptanceThrowsExceptionWhenAppetiteNotApproved(): void
    {
        $risk = $this->createValidRiskForAcceptance();
        $user = $this->createUser();

        $appetite = $this->createMock(RiskAppetite::class);
        $appetite->method('isApproved')->willReturn(false);

        $this->riskAppetiteService->method('getApplicableAppetite')
            ->with($risk)
            ->willReturn($appetite);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Risk appetite must be approved before use');

        $this->service->requestAcceptance($risk, $user, 'Test justification');
    }

    public function testRequestAcceptanceThrowsExceptionWhenExceedsAppetite(): void
    {
        $risk = $this->createValidRiskForAcceptance();
        $risk->method('getResidualRiskLevel')->willReturn(10);
        $user = $this->createUser();

        $appetite = $this->createMock(RiskAppetite::class);
        $appetite->method('isApproved')->willReturn(true);
        $appetite->method('getMaxAcceptableRisk')->willReturn(5);

        $this->riskAppetiteService->method('getApplicableAppetite')
            ->with($risk)
            ->willReturn($appetite);
        $this->riskAppetiteService->method('exceedsAppetite')
            ->with($risk)
            ->willReturn(true);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exceeds organizational risk appetite');

        $this->service->requestAcceptance($risk, $user, 'Test justification');
    }

    public function testApproveAcceptanceSetsRiskAsAccepted(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $risk->method('getRiskOwner')->willReturn(null);

        $user = $this->createMock(User::class);
        $user->method('getFullName')->willReturn('John Doe');
        $user->method('getEmail')->willReturn('john@example.com');

        $risk->expects($this->once())->method('setFormallyAccepted')->with(true);
        $risk->expects($this->once())->method('setAcceptanceApprovedBy')->with('John Doe');
        $risk->expects($this->once())->method('setAcceptanceApprovedAt');
        $risk->expects($this->once())->method('setStatus')->with('accepted');

        $this->entityManager->expects($this->once())->method('persist')->with($risk);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->approveAcceptance($risk, $user, 'Approved');

        $this->assertSame('accepted', $result['status']);
        $this->assertSame('John Doe', $result['approved_by']);
        $this->assertArrayHasKey('approved_at', $result);
    }

    public function testRejectAcceptanceResetsRiskStatus(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $risk->method('getRiskOwner')->willReturn(null);

        $user = $this->createMock(User::class);
        $user->method('getFullName')->willReturn('Jane Doe');
        $user->method('getEmail')->willReturn('jane@example.com');

        $risk->expects($this->once())->method('setStatus')->with('assessed');
        $risk->expects($this->once())->method('setFormallyAccepted')->with(false);

        $this->entityManager->expects($this->once())->method('persist')->with($risk);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->rejectAcceptance($risk, $user, 'Needs more mitigation');

        $this->assertSame('rejected', $result['status']);
        $this->assertSame('Jane Doe', $result['rejected_by']);
        $this->assertSame('Needs more mitigation', $result['reason']);
    }

    public function testGetApprovalThresholdsReturnsCorrectConfiguration(): void
    {
        $thresholds = $this->service->getApprovalThresholds();

        $this->assertArrayHasKey('automatic', $thresholds);
        $this->assertArrayHasKey('manager', $thresholds);
        $this->assertArrayHasKey('executive', $thresholds);

        $this->assertSame(3, $thresholds['automatic']['max_score']);
        $this->assertSame(4, $thresholds['manager']['min_score']);
        $this->assertSame(7, $thresholds['manager']['max_score']);
        $this->assertSame(8, $thresholds['executive']['min_score']);
        $this->assertSame(25, $thresholds['executive']['max_score']);
    }

    private function createRisk(): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);
        $risk->method('getTenant')->willReturn($tenant);
        $risk->method('getId')->willReturn(1);

        return $risk;
    }

    private function createRiskWithResidualLevel(int $level): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getResidualRiskLevel')->willReturn($level);

        return $risk;
    }

    private function createValidRiskForAcceptance(): MockObject
    {
        $risk = $this->createRisk();
        $risk->method('getTreatmentStrategy')->willReturn('accept');
        $risk->method('getProbability')->willReturn(3);
        $risk->method('getImpact')->willReturn(3);
        $risk->method('getResidualProbability')->willReturn(2);
        $risk->method('getResidualImpact')->willReturn(2);
        $risk->method('isFormallyAccepted')->willReturn(false);

        return $risk;
    }

    private function createUser(): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getEmail')->willReturn('test@example.com');
        $user->method('getFullName')->willReturn('Test User');

        return $user;
    }
}
