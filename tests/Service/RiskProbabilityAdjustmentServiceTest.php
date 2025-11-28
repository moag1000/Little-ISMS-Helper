<?php

namespace App\Tests\Service;

use App\Entity\Incident;
use App\Entity\Risk;
use App\Repository\RiskRepository;
use App\Service\RiskProbabilityAdjustmentService;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RiskProbabilityAdjustmentServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $logger;
    private RiskProbabilityAdjustmentService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new RiskProbabilityAdjustmentService(
            $this->entityManager,
            $this->logger
        );
    }

    public function testCalculateSuggestedProbabilityReturnsNullForInsufficientData(): void
    {
        $risk = $this->createRiskWithIncidents([]);

        $result = $this->service->calculateSuggestedProbability($risk);

        $this->assertNull($result);
    }

    public function testCalculateSuggestedProbabilityReturnsNullForOneIncident(): void
    {
        $incident = $this->createClosedIncident(new DateTime('-60 days'));
        $risk = $this->createRiskWithIncidents([$incident], 2);

        $result = $this->service->calculateSuggestedProbability($risk);

        $this->assertNull($result);
    }

    public function testCalculateSuggestedProbabilityReturnsNullForRecentIncidents(): void
    {
        $incident1 = $this->createClosedIncident(new DateTime('-10 days'));
        $incident2 = $this->createClosedIncident(new DateTime('-15 days'));
        $risk = $this->createRiskWithIncidents([$incident1, $incident2], 2);

        $result = $this->service->calculateSuggestedProbability($risk);

        $this->assertNull($result);
    }

    public function testCalculateSuggestedProbabilityReturnsNullForOpenIncidents(): void
    {
        $incident1 = $this->createIncident(new DateTime('-60 days'), 'open');
        $incident2 = $this->createIncident(new DateTime('-90 days'), 'in_progress');
        $risk = $this->createRiskWithIncidents([$incident1, $incident2], 2);

        $result = $this->service->calculateSuggestedProbability($risk);

        $this->assertNull($result);
    }

    public function testCalculateSuggestedProbabilityReturnsNullWhenCurrentProbabilityHigher(): void
    {
        $incident1 = $this->createClosedIncident(new DateTime('-60 days'));
        $incident2 = $this->createClosedIncident(new DateTime('-90 days'));
        $risk = $this->createRiskWithIncidents([$incident1, $incident2], 5); // Already at max

        $result = $this->service->calculateSuggestedProbability($risk);

        $this->assertNull($result);
    }

    public function testCalculateSuggestedProbabilityReturnsSuggestionForFrequentIncidents(): void
    {
        $incidents = [];
        // Create 5 incidents spread over the year
        for ($i = 0; $i < 5; $i++) {
            $incidents[] = $this->createClosedIncident(new DateTime(sprintf('-%d days', 45 + ($i * 60))));
        }
        $risk = $this->createRiskWithIncidents($incidents, 2); // Current probability 2

        $result = $this->service->calculateSuggestedProbability($risk);

        $this->assertNotNull($result);
        $this->assertGreaterThan(2, $result);
        $this->assertLessThanOrEqual(5, $result);
    }

    public function testAnalyzeProbabilityAdjustmentReturnsCompleteStructure(): void
    {
        $risk = $this->createRiskWithIncidents([], 3);

        $result = $this->service->analyzeProbabilityAdjustment($risk);

        $this->assertArrayHasKey('current_probability', $result);
        $this->assertArrayHasKey('suggested_probability', $result);
        $this->assertArrayHasKey('eligible_incidents', $result);
        $this->assertArrayHasKey('total_incidents', $result);
        $this->assertArrayHasKey('frequency_analysis', $result);
        $this->assertArrayHasKey('should_adjust', $result);
        $this->assertArrayHasKey('rationale', $result);

        $this->assertSame(3, $result['current_probability']);
        $this->assertNull($result['suggested_probability']);
        $this->assertSame(0, $result['eligible_incidents']);
        $this->assertFalse($result['should_adjust']);
    }

    public function testAnalyzeProbabilityAdjustmentFrequencyAnalysisStructure(): void
    {
        $incident1 = $this->createClosedIncident(new DateTime('-60 days'));
        $incident2 = $this->createClosedIncident(new DateTime('-120 days'));
        $risk = $this->createRiskWithIncidents([$incident1, $incident2], 2);

        $result = $this->service->analyzeProbabilityAdjustment($risk);

        $this->assertArrayHasKey('last_year', $result['frequency_analysis']);
        $this->assertArrayHasKey('last_6_months', $result['frequency_analysis']);
        $this->assertArrayHasKey('last_3_months', $result['frequency_analysis']);
    }

    public function testAnalyzeProbabilityAdjustmentRationaleForInsufficientData(): void
    {
        $risk = $this->createRiskWithIncidents([], 2);

        $result = $this->service->analyzeProbabilityAdjustment($risk);

        $this->assertStringContainsString('Insufficient historical data', $result['rationale']);
        $this->assertStringContainsString('need at least 2', $result['rationale']);
    }

    public function testApplyProbabilityAdjustmentRequiresUserConfirmation(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getProbability')->willReturn(2);

        $result = $this->service->applyProbabilityAdjustment($risk, 3, false);

        $this->assertFalse($result['success']);
        $this->assertSame('User confirmation required for probability adjustment', $result['message']);
        $this->assertNull($result['new_probability']);
    }

    public function testApplyProbabilityAdjustmentValidatesRange(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getProbability')->willReturn(2);

        $result = $this->service->applyProbabilityAdjustment($risk, 0, true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('must be between 1 and 5', $result['message']);

        $result = $this->service->applyProbabilityAdjustment($risk, 6, true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('must be between 1 and 5', $result['message']);
    }

    public function testApplyProbabilityAdjustmentSuccessfully(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $risk->method('getProbability')->willReturn(2);
        $risk->method('getResidualProbability')->willReturn(2);
        $risk->method('getIncidents')->willReturn(new ArrayCollection());

        $risk->expects($this->once())->method('setProbability')->with(4);
        $risk->expects($this->once())->method('setResidualProbability');

        $this->entityManager->expects($this->once())->method('persist')->with($risk);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->applyProbabilityAdjustment($risk, 4, true);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['old_probability']);
        $this->assertSame(4, $result['new_probability']);
        $this->assertStringContainsString('Probability updated', $result['message']);
    }

    public function testApplyProbabilityAdjustmentLogsWarningOnDecrease(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getId')->willReturn(1);
        $risk->method('getProbability')->willReturn(4);
        $risk->method('getResidualProbability')->willReturn(3);
        $risk->method('getIncidents')->willReturn(new ArrayCollection());

        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                $this->stringContains('manually decreased probability'),
                $this->anything()
            );

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->applyProbabilityAdjustment($risk, 2, true);

        $this->assertTrue($result['success']);
    }

    public function testFindRisksRequiringAdjustmentReturnsEmptyWhenNoAdjustmentsNeeded(): void
    {
        $riskRepository = $this->createMock(EntityRepository::class);
        $riskRepository->method('findAll')->willReturn([]);

        $this->entityManager->method('getRepository')
            ->with(Risk::class)
            ->willReturn($riskRepository);

        $result = $this->service->findRisksRequiringAdjustment();

        $this->assertEmpty($result);
    }

    public function testFindRisksRequiringAdjustmentSortsByProbabilityIncrease(): void
    {
        // Create risks with different adjustment needs
        $incidents = [];
        for ($i = 0; $i < 5; $i++) {
            $incidents[] = $this->createClosedIncident(new DateTime(sprintf('-%d days', 45 + ($i * 30))));
        }

        $risk1 = $this->createRiskWithIncidents($incidents, 1);
        $risk1->method('getId')->willReturn(1);

        $risk2 = $this->createRiskWithIncidents([], 3);
        $risk2->method('getId')->willReturn(2);

        $riskRepository = $this->createMock(EntityRepository::class);
        $riskRepository->method('findAll')->willReturn([$risk1, $risk2]);

        $this->entityManager->method('getRepository')
            ->with(Risk::class)
            ->willReturn($riskRepository);

        $result = $this->service->findRisksRequiringAdjustment();

        // Risk2 has no adjustment needed, Risk1 should require adjustment
        $this->assertIsArray($result);
    }

    private function createRiskWithIncidents(array $incidents, int $currentProbability = 2): MockObject
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getProbability')->willReturn($currentProbability);
        $risk->method('getIncidents')->willReturn(new ArrayCollection($incidents));

        return $risk;
    }

    private function createClosedIncident(DateTime $detectedAt): MockObject
    {
        return $this->createIncident($detectedAt, 'closed');
    }

    private function createIncident(DateTime $detectedAt, string $status): MockObject
    {
        $incident = $this->createMock(Incident::class);
        $incident->method('getStatus')->willReturn($status);
        $incident->method('getDetectedAt')->willReturn($detectedAt);

        return $incident;
    }
}
