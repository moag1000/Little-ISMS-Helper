<?php

namespace App\Tests\Service;

use App\Entity\Risk;
use App\Repository\RiskRepository;
use App\Service\RiskMatrixService;
use PHPUnit\Framework\TestCase;

class RiskMatrixServiceTest extends TestCase
{
    private RiskMatrixService $service;
    private RiskRepository $riskRepository;

    protected function setUp(): void
    {
        $this->riskRepository = $this->createMock(RiskRepository::class);
        $this->service = new RiskMatrixService($this->riskRepository);
    }

    public function testCalculateRiskLevelForCritical(): void
    {
        // Score >= 20 is critical
        $this->assertEquals('critical', $this->service->calculateRiskLevel(5, 4)); // 20
        $this->assertEquals('critical', $this->service->calculateRiskLevel(4, 5)); // 20
        $this->assertEquals('critical', $this->service->calculateRiskLevel(5, 5)); // 25
    }

    public function testCalculateRiskLevelForHigh(): void
    {
        // Score >= 12 and < 20 is high
        $this->assertEquals('high', $this->service->calculateRiskLevel(3, 4)); // 12
        $this->assertEquals('high', $this->service->calculateRiskLevel(4, 3)); // 12
        $this->assertEquals('high', $this->service->calculateRiskLevel(4, 4)); // 16
    }

    public function testCalculateRiskLevelForMedium(): void
    {
        // Score >= 6 and < 12 is medium
        $this->assertEquals('medium', $this->service->calculateRiskLevel(2, 3)); // 6
        $this->assertEquals('medium', $this->service->calculateRiskLevel(3, 2)); // 6
        $this->assertEquals('medium', $this->service->calculateRiskLevel(3, 3)); // 9
    }

    public function testCalculateRiskLevelForLow(): void
    {
        // Score < 6 is low
        $this->assertEquals('low', $this->service->calculateRiskLevel(1, 1)); // 1
        $this->assertEquals('low', $this->service->calculateRiskLevel(1, 5)); // 5
        $this->assertEquals('low', $this->service->calculateRiskLevel(2, 2)); // 4
    }

    public function testGetRiskLevelClassReturnsCorrectClasses(): void
    {
        $this->assertEquals('risk-critical', $this->service->getRiskLevelClass('critical'));
        $this->assertEquals('risk-high', $this->service->getRiskLevelClass('high'));
        $this->assertEquals('risk-medium', $this->service->getRiskLevelClass('medium'));
        $this->assertEquals('risk-low', $this->service->getRiskLevelClass('low'));
        $this->assertEquals('risk-unknown', $this->service->getRiskLevelClass('invalid'));
    }

    public function testGetRiskLevelColorReturnsCorrectColors(): void
    {
        $this->assertEquals('#dc3545', $this->service->getRiskLevelColor('critical'));
        $this->assertEquals('#fd7e14', $this->service->getRiskLevelColor('high'));
        $this->assertEquals('#ffc107', $this->service->getRiskLevelColor('medium'));
        $this->assertEquals('#28a745', $this->service->getRiskLevelColor('low'));
        $this->assertEquals('#6c757d', $this->service->getRiskLevelColor('invalid'));
    }

    public function testGenerateMatrixWithEmptyRiskArray(): void
    {
        $result = $this->service->generateMatrix([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('matrix', $result);
        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('statistics', $result);
        $this->assertArrayHasKey('riskLevels', $result);

        // Statistics should be zero
        $this->assertEquals(0, $result['statistics']['total']);
        $this->assertEquals(0, $result['statistics']['critical']);
        $this->assertEquals(0, $result['statistics']['high']);
        $this->assertEquals(0, $result['statistics']['medium']);
        $this->assertEquals(0, $result['statistics']['low']);

        // Matrix should be initialized but empty
        $this->assertCount(5, $result['matrix']);
        foreach ($result['matrix'] as $likelihood) {
            $this->assertCount(5, $likelihood);
            foreach ($likelihood as $impactCell) {
                $this->assertEmpty($impactCell);
            }
        }
    }

    public function testGenerateMatrixWithRisks(): void
    {
        $risks = [];

        // Create critical risk (5x5 = 25)
        $criticalRisk = $this->createMock(Risk::class);
        $criticalRisk->method('getLikelihood')->willReturn(5);
        $criticalRisk->method('getImpact')->willReturn(5);
        $risks[] = $criticalRisk;

        // Create high risk (4x4 = 16)
        $highRisk = $this->createMock(Risk::class);
        $highRisk->method('getLikelihood')->willReturn(4);
        $highRisk->method('getImpact')->willReturn(4);
        $risks[] = $highRisk;

        // Create medium risk (3x3 = 9)
        $mediumRisk = $this->createMock(Risk::class);
        $mediumRisk->method('getLikelihood')->willReturn(3);
        $mediumRisk->method('getImpact')->willReturn(3);
        $risks[] = $mediumRisk;

        // Create low risk (1x1 = 1)
        $lowRisk = $this->createMock(Risk::class);
        $lowRisk->method('getLikelihood')->willReturn(1);
        $lowRisk->method('getImpact')->willReturn(1);
        $risks[] = $lowRisk;

        $result = $this->service->generateMatrix($risks);

        // Check statistics
        $this->assertEquals(4, $result['statistics']['total']);
        $this->assertEquals(1, $result['statistics']['critical']);
        $this->assertEquals(1, $result['statistics']['high']);
        $this->assertEquals(1, $result['statistics']['medium']);
        $this->assertEquals(1, $result['statistics']['low']);

        // Check matrix cells contain the risks
        $this->assertCount(1, $result['matrix'][5][5]); // Critical
        $this->assertCount(1, $result['matrix'][4][4]); // High
        $this->assertCount(1, $result['matrix'][3][3]); // Medium
        $this->assertCount(1, $result['matrix'][1][1]); // Low

        // Check risk levels
        $this->assertEquals('critical', $result['riskLevels'][5][5]);
        $this->assertEquals('high', $result['riskLevels'][4][4]);
        $this->assertEquals('medium', $result['riskLevels'][3][3]);
        $this->assertEquals('low', $result['riskLevels'][1][1]);
    }

    public function testGenerateMatrixHandlesNullLikelihoodAndImpact(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getLikelihood')->willReturn(null);
        $risk->method('getImpact')->willReturn(null);

        $result = $this->service->generateMatrix([$risk]);

        // Should default to 3x3
        $this->assertCount(1, $result['matrix'][3][3]);
        $this->assertEquals(1, $result['statistics']['total']);
        $this->assertEquals(1, $result['statistics']['medium']); // 3x3 = 9 is medium
    }

    public function testGenerateMatrixClampsOutOfRangeValues(): void
    {
        // Test lower bound clamping
        $riskLow = $this->createMock(Risk::class);
        $riskLow->method('getLikelihood')->willReturn(0);
        $riskLow->method('getImpact')->willReturn(-1);

        // Test upper bound clamping
        $riskHigh = $this->createMock(Risk::class);
        $riskHigh->method('getLikelihood')->willReturn(10);
        $riskHigh->method('getImpact')->willReturn(15);

        $result = $this->service->generateMatrix([$riskLow, $riskHigh]);

        // Both should be clamped
        $this->assertCount(1, $result['matrix'][1][1]); // Clamped to minimum
        $this->assertCount(1, $result['matrix'][5][5]); // Clamped to maximum
    }

    public function testGenerateHeatmapDataReturnsCorrectStructure(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getLikelihood')->willReturn(4);
        $risk->method('getImpact')->willReturn(5);

        $result = $this->service->generateHeatmapData([$risk]);

        $this->assertIsArray($result);
        $this->assertCount(25, $result); // 5x5 matrix = 25 cells

        // Check structure of first element
        $firstCell = $result[0];
        $this->assertArrayHasKey('x', $firstCell);
        $this->assertArrayHasKey('y', $firstCell);
        $this->assertArrayHasKey('v', $firstCell);
        $this->assertArrayHasKey('level', $firstCell);
        $this->assertArrayHasKey('color', $firstCell);

        // Find the cell with our risk (4,5)
        $cellWithRisk = array_filter($result, fn($cell) => $cell['x'] === 5 && $cell['y'] === 4);
        $this->assertNotEmpty($cellWithRisk);

        $cell = reset($cellWithRisk);
        $this->assertEquals(1, $cell['v']); // 1 risk in this cell
        $this->assertEquals('critical', $cell['level']); // 4*5=20 is critical
        $this->assertEquals('#dc3545', $cell['color']); // Red for critical
    }

    public function testGetRisksByLevelGroupsRisksCorrectly(): void
    {
        $risks = [];

        // Create 2 critical risks
        for ($i = 0; $i < 2; $i++) {
            $risk = $this->createMock(Risk::class);
            $risk->method('getLikelihood')->willReturn(5);
            $risk->method('getImpact')->willReturn(5);
            $risks[] = $risk;
        }

        // Create 1 high risk
        $risk = $this->createMock(Risk::class);
        $risk->method('getLikelihood')->willReturn(4);
        $risk->method('getImpact')->willReturn(3);
        $risks[] = $risk;

        // Create 1 medium risk
        $risk = $this->createMock(Risk::class);
        $risk->method('getLikelihood')->willReturn(3);
        $risk->method('getImpact')->willReturn(2);
        $risks[] = $risk;

        // Create 1 low risk
        $risk = $this->createMock(Risk::class);
        $risk->method('getLikelihood')->willReturn(1);
        $risk->method('getImpact')->willReturn(2);
        $risks[] = $risk;

        $this->riskRepository->method('findAll')->willReturn($risks);

        $result = $this->service->getRisksByLevel();

        $this->assertCount(2, $result['critical']);
        $this->assertCount(1, $result['high']);
        $this->assertCount(1, $result['medium']);
        $this->assertCount(1, $result['low']);
    }

    public function testGetRiskStatistics(): void
    {
        $risks = [];

        // Create various risks
        $criticalRisk = $this->createMock(Risk::class);
        $criticalRisk->method('getLikelihood')->willReturn(5);
        $criticalRisk->method('getImpact')->willReturn(5);
        $risks[] = $criticalRisk;

        $lowRisk = $this->createMock(Risk::class);
        $lowRisk->method('getLikelihood')->willReturn(1);
        $lowRisk->method('getImpact')->willReturn(1);
        $risks[] = $lowRisk;

        $this->riskRepository->method('findAll')->willReturn($risks);

        $stats = $this->service->getRiskStatistics();

        $this->assertEquals(2, $stats['total']);
        $this->assertEquals(1, $stats['critical']);
        $this->assertEquals(1, $stats['low']);
        $this->assertEquals(0, $stats['high']);
        $this->assertEquals(0, $stats['medium']);
    }

    public function testGenerateMatrixLabelsAreCorrect(): void
    {
        $result = $this->service->generateMatrix([]);

        $this->assertArrayHasKey('likelihood', $result['labels']);
        $this->assertArrayHasKey('impact', $result['labels']);

        // Check likelihood labels
        $this->assertEquals('Sehr selten', $result['labels']['likelihood'][1]);
        $this->assertEquals('Sehr wahrscheinlich', $result['labels']['likelihood'][5]);

        // Check impact labels
        $this->assertEquals('Unbedeutend', $result['labels']['impact'][1]);
        $this->assertEquals('Kritisch', $result['labels']['impact'][5]);
    }
}
