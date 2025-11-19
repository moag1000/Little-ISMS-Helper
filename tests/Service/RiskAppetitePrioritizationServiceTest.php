<?php

namespace App\Tests\Service;

use App\Entity\Risk;
use App\Entity\RiskAppetite;
use App\Entity\Tenant;
use App\Repository\RiskAppetiteRepository;
use App\Repository\RiskRepository;
use App\Service\RiskAppetitePrioritizationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RiskAppetitePrioritizationServiceTest extends TestCase
{
    private MockObject $riskRepository;
    private MockObject $riskAppetiteRepository;
    private MockObject $logger;
    private RiskAppetitePrioritizationService $service;

    protected function setUp(): void
    {
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->riskAppetiteRepository = $this->createMock(RiskAppetiteRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new RiskAppetitePrioritizationService(
            $this->riskRepository,
            $this->riskAppetiteRepository,
            $this->logger
        );
    }

    public function testGetApplicableAppetiteReturnsGlobalWhenNoCategory(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $risk = $this->createRisk('Low risk description', 'Title', 5, $tenant);
        $globalAppetite = $this->createAppetite(null, 10);

        $this->riskAppetiteRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'tenant' => $tenant,
                'category' => null,
                'isActive' => true
            ])
            ->willReturn($globalAppetite);

        $result = $this->service->getApplicableAppetite($risk);

        $this->assertSame($globalAppetite, $result);
    }

    public function testGetApplicableAppetiteReturnsCategorySpecific(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $risk = $this->createRisk('Financial loss from fraud', 'Financial Risk', 12, $tenant);
        $categoryAppetite = $this->createAppetite('Financial', 8);

        $this->riskAppetiteRepository->expects($this->once())
            ->method('findOneBy')
            ->with([
                'tenant' => $tenant,
                'category' => 'Financial',
                'isActive' => true
            ])
            ->willReturn($categoryAppetite);

        $result = $this->service->getApplicableAppetite($risk);

        $this->assertSame($categoryAppetite, $result);
    }

    public function testGetApplicableAppetiteFallsBackToGlobal(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $risk = $this->createRisk('Operational process failure', 'Operational Risk', 10, $tenant);
        $globalAppetite = $this->createAppetite(null, 12);

        $this->riskAppetiteRepository->expects($this->exactly(2))
            ->method('findOneBy')
            ->willReturnCallback(function($criteria) use ($globalAppetite) {
                if ($criteria['category'] === 'Operational') {
                    return null; // No category-specific appetite
                }
                return $globalAppetite; // Global appetite
            });

        $result = $this->service->getApplicableAppetite($risk);

        $this->assertSame($globalAppetite, $result);
    }

    public function testGetApplicableAppetiteReturnsNullWhenNoneExists(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $risk = $this->createRisk('Test risk', 'Test', 5, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn(null);

        $result = $this->service->getApplicableAppetite($risk);

        $this->assertNull($result);
    }

    public function testExceedsAppetiteReturnsTrueWhenExceeded(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $risk = $this->createRisk('High risk', 'Test', 15, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->exceedsAppetite($risk);

        $this->assertTrue($result);
    }

    public function testExceedsAppetiteReturnsFalseWhenWithin(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $risk = $this->createRisk('Low risk', 'Test', 5, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->exceedsAppetite($risk);

        $this->assertFalse($result);
    }

    public function testExceedsAppetiteReturnsFalseWhenNoAppetite(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $risk = $this->createRisk('Test risk', 'Test', 20, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn(null);

        $result = $this->service->exceedsAppetite($risk);

        $this->assertFalse($result);
    }

    public function testGetPriorityLevelReturnsAcceptableWithinAppetite(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 12);
        $risk = $this->createRisk('Low risk', 'Test', 8, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->getPriorityLevel($risk);

        $this->assertSame('acceptable', $result);
    }

    public function testGetPriorityLevelReturnsMediumSlightlyAbove(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $risk = $this->createRisk('Medium risk', 'Test', 12, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->getPriorityLevel($risk);

        $this->assertSame('medium', $result);
    }

    public function testGetPriorityLevelReturnsHighSignificantlyAbove(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $risk = $this->createRisk('High risk', 'Test', 15, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->getPriorityLevel($risk);

        $this->assertSame('high', $result);
    }

    public function testGetPriorityLevelReturnsCriticalFarExceeds(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $risk = $this->createRisk('Critical risk', 'Test', 20, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->getPriorityLevel($risk);

        $this->assertSame('critical', $result);
    }

    public function testGetPriorityLevelUsesAbsoluteWhenNoAppetite(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $risk = $this->createRisk('High risk', 'Test', 20, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn(null);

        $result = $this->service->getPriorityLevel($risk);

        $this->assertSame('critical', $result);
    }

    public function testAnalyzeRiskAppetiteReturnsCompleteAnalysis(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite('Financial', 10);
        $appetite->method('getAppetitePercentage')->willReturn(120.0);

        $risk = $this->createRisk('Financial risk', 'Test', 12, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->analyzeRiskAppetite($risk);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('appetite', $result);
        $this->assertArrayHasKey('within_appetite', $result);
        $this->assertArrayHasKey('priority', $result);
        $this->assertArrayHasKey('exceedance', $result);
        $this->assertArrayHasKey('percentage', $result);
        $this->assertArrayHasKey('requires_action', $result);
        $this->assertArrayHasKey('recommendation', $result);
    }

    public function testAnalyzeRiskAppetiteWithNoAppetite(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $risk = $this->createRisk('Test risk', 'Test', 16, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn(null);

        $result = $this->service->analyzeRiskAppetite($risk);

        $this->assertNull($result['appetite']);
        $this->assertNull($result['within_appetite']);
        $this->assertTrue($result['requires_action']);
        $this->assertStringContainsString('No risk appetite', $result['recommendation']);
    }

    public function testAnalyzeRiskAppetiteWithinAppetite(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 15);
        $appetite->method('getAppetitePercentage')->willReturn(66.67);

        $risk = $this->createRisk('Low risk', 'Test', 10, $tenant);

        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->analyzeRiskAppetite($risk);

        $this->assertTrue($result['within_appetite']);
        $this->assertFalse($result['requires_action']);
        $this->assertSame('acceptable', $result['priority']);
    }

    public function testFindRisksExceedingAppetiteReturnsArray(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $appetite->method('getAppetitePercentage')->willReturn(150.0);

        $risk1 = $this->createRisk('High risk 1', 'Test 1', 15, $tenant);
        $risk2 = $this->createRisk('Low risk', 'Test 2', 5, $tenant);
        $risk3 = $this->createRisk('High risk 2', 'Test 3', 18, $tenant);

        $this->riskRepository->method('findAll')->willReturn([$risk1, $risk2, $risk3]);
        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $this->logger->expects($this->once())->method('info');

        $result = $this->service->findRisksExceedingAppetite();

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('risk', $result[0]);
        $this->assertArrayHasKey('appetite', $result[0]);
        $this->assertArrayHasKey('exceedance', $result[0]);
        $this->assertArrayHasKey('priority', $result[0]);
    }

    public function testFindRisksExceedingAppetiteSortsByExceedance(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $appetite->method('getAppetitePercentage')->willReturn(150.0);

        $risk1 = $this->createRisk('Risk 1', 'Test', 15, $tenant); // exceedance: 5
        $risk2 = $this->createRisk('Risk 2', 'Test', 20, $tenant); // exceedance: 10

        $this->riskRepository->method('findAll')->willReturn([$risk1, $risk2]);
        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->findRisksExceedingAppetite();

        // Should be sorted by exceedance descending
        $this->assertSame(10, $result[0]['exceedance']);
        $this->assertSame(5, $result[1]['exceedance']);
    }

    public function testGetDashboardStatisticsReturnsCompleteStats(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $appetite->method('getAppetitePercentage')->willReturn(100.0);

        $risk1 = $this->createRisk('Risk 1', 'Test', 8, $tenant);
        $risk2 = $this->createRisk('Risk 2', 'Test', 15, $tenant);
        $risk3 = $this->createRisk('Risk 3', 'Test', 20, $tenant);

        $this->riskRepository->method('findAll')->willReturn([$risk1, $risk2, $risk3]);
        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(3, $stats['total_risks']);
        $this->assertSame(1, $stats['within_appetite']);
        $this->assertSame(2, $stats['exceeding_appetite']);
        $this->assertArrayHasKey('critical', $stats);
        $this->assertArrayHasKey('high', $stats);
        $this->assertArrayHasKey('medium', $stats);
        $this->assertArrayHasKey('compliance_rate', $stats);
    }

    public function testGetDashboardStatisticsCountsPriorityLevels(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $appetite->method('getAppetitePercentage')->willReturn(100.0);

        $risk1 = $this->createRisk('Risk 1', 'Test', 12, $tenant); // medium
        $risk2 = $this->createRisk('Risk 2', 'Test', 15, $tenant); // high
        $risk3 = $this->createRisk('Risk 3', 'Test', 20, $tenant); // critical

        $this->riskRepository->method('findAll')->willReturn([$risk1, $risk2, $risk3]);
        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $stats = $this->service->getDashboardStatistics();

        $this->assertGreaterThanOrEqual(1, $stats['critical']);
        $this->assertGreaterThanOrEqual(1, $stats['high']);
        $this->assertGreaterThanOrEqual(1, $stats['medium']);
    }

    public function testGetDashboardStatisticsCalculatesComplianceRate(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $appetite->method('getAppetitePercentage')->willReturn(100.0);

        $risk1 = $this->createRisk('Risk 1', 'Test', 5, $tenant);
        $risk2 = $this->createRisk('Risk 2', 'Test', 8, $tenant);
        $risk3 = $this->createRisk('Risk 3', 'Test', 20, $tenant);

        $this->riskRepository->method('findAll')->willReturn([$risk1, $risk2, $risk3]);
        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $stats = $this->service->getDashboardStatistics();

        // 2 out of 3 within appetite = 66.67%
        $this->assertEqualsWithDelta(66.67, $stats['compliance_rate'], 0.1);
    }

    public function testGetDashboardStatisticsHandlesNoAppetite(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $risk = $this->createRisk('Risk', 'Test', 10, $tenant);

        $this->riskRepository->method('findAll')->willReturn([$risk]);
        $this->riskAppetiteRepository->method('findOneBy')->willReturn(null);

        $stats = $this->service->getDashboardStatistics();

        $this->assertSame(1, $stats['total_risks']);
        $this->assertSame(1, $stats['no_appetite_defined']);
        $this->assertSame(0.0, $stats['compliance_rate']);
    }

    public function testGetPrioritizedRisksReturnsLimitedResults(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $appetite->method('getAppetitePercentage')->willReturn(100.0);

        $risks = [];
        for ($i = 0; $i < 20; $i++) {
            $risks[] = $this->createRisk("Risk $i", "Test $i", 15, $tenant);
        }

        $this->riskRepository->method('findAll')->willReturn($risks);
        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->getPrioritizedRisks(5);

        $this->assertCount(5, $result);
    }

    public function testGetPrioritizedRisksSortsByPriorityScore(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $appetite->method('getAppetitePercentage')->willReturn(100.0);

        $risk1 = $this->createRisk('Low Risk', 'Test', 5, $tenant);
        $risk2 = $this->createRisk('Critical Risk', 'Test', 25, $tenant);
        $risk3 = $this->createRisk('Medium Risk', 'Test', 12, $tenant);

        $this->riskRepository->method('findAll')->willReturn([$risk1, $risk2, $risk3]);
        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->getPrioritizedRisks();

        // Highest priority should be first
        $this->assertGreaterThan($result[1]['score'], $result[0]['score']);
        $this->assertGreaterThan($result[2]['score'], $result[1]['score']);
    }

    public function testGetPrioritizedRisksIncludesAnalysis(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $appetite = $this->createAppetite(null, 10);
        $appetite->method('getAppetitePercentage')->willReturn(100.0);

        $risk = $this->createRisk('Test Risk', 'Test', 15, $tenant);

        $this->riskRepository->method('findAll')->willReturn([$risk]);
        $this->riskAppetiteRepository->method('findOneBy')->willReturn($appetite);

        $result = $this->service->getPrioritizedRisks();

        $this->assertArrayHasKey('risk', $result[0]);
        $this->assertArrayHasKey('analysis', $result[0]);
        $this->assertArrayHasKey('score', $result[0]);
        $this->assertArrayHasKey('within_appetite', $result[0]['analysis']);
    }

    private function createRisk(
        string $description,
        string $title,
        int $residualRiskLevel,
        Tenant $tenant
    ): MockObject {
        $risk = $this->createMock(Risk::class);
        $risk->method('getDescription')->willReturn($description);
        $risk->method('getTitle')->willReturn($title);
        $risk->method('getResidualRiskLevel')->willReturn($residualRiskLevel);
        $risk->method('getTenant')->willReturn($tenant);
        return $risk;
    }

    private function createAppetite(?string $category, int $maxAcceptableRisk): MockObject
    {
        $appetite = $this->createMock(RiskAppetite::class);
        $appetite->method('getCategory')->willReturn($category);
        $appetite->method('getMaxAcceptableRisk')->willReturn($maxAcceptableRisk);
        return $appetite;
    }
}
