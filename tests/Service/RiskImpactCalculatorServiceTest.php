<?php

namespace App\Tests\Service;

use App\Entity\Risk;
use App\Entity\Asset;
use App\Service\RiskImpactCalculatorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RiskImpactCalculatorServiceTest extends TestCase
{
    private RiskImpactCalculatorService $service;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new RiskImpactCalculatorService($this->entityManager, $this->logger);
    }

    public function testCalculateSuggestedImpactReturnsNullWhenNoAsset(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn(null);

        $result = $this->service->calculateSuggestedImpact($risk);

        $this->assertNull($result);
    }

    public function testCalculateSuggestedImpactReturnsNullWhenNoMonetaryValue(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getMonetaryValue')->willReturn(null);

        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn($asset);

        $result = $this->service->calculateSuggestedImpact($risk);

        $this->assertNull($result);
    }

    public function testCalculateSuggestedImpactReturnsNullWhenMonetaryValueIsZero(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getMonetaryValue')->willReturn('0.00');

        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn($asset);

        $result = $this->service->calculateSuggestedImpact($risk);

        $this->assertNull($result);
    }

    public function testCalculateSuggestedImpactNegligible(): void
    {
        // Loss < €10,000 = Impact 1 (Negligible)
        $asset = $this->createMock(Asset::class);
        $asset->method('getMonetaryValue')->willReturn('5000.00');

        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn($asset);

        $result = $this->service->calculateSuggestedImpact($risk);

        $this->assertEquals(1, $result);
    }

    public function testCalculateSuggestedImpactMinor(): void
    {
        // Loss €10,000 - €50,000 = Impact 2 (Minor)
        $testCases = ['10000.00', '25000.00', '49999.99'];

        foreach ($testCases as $value) {
            $asset = $this->createMock(Asset::class);
            $asset->method('getMonetaryValue')->willReturn($value);

            $risk = $this->createMock(Risk::class);
            $risk->method('getAsset')->willReturn($asset);

            $result = $this->service->calculateSuggestedImpact($risk);

            $this->assertEquals(2, $result, "Failed for value: $value");
        }
    }

    public function testCalculateSuggestedImpactModerate(): void
    {
        // Loss €50,000 - €250,000 = Impact 3 (Moderate)
        $testCases = ['50000.00', '150000.00', '249999.99'];

        foreach ($testCases as $value) {
            $asset = $this->createMock(Asset::class);
            $asset->method('getMonetaryValue')->willReturn($value);

            $risk = $this->createMock(Risk::class);
            $risk->method('getAsset')->willReturn($asset);

            $result = $this->service->calculateSuggestedImpact($risk);

            $this->assertEquals(3, $result, "Failed for value: $value");
        }
    }

    public function testCalculateSuggestedImpactMajor(): void
    {
        // Loss €250,000 - €1,000,000 = Impact 4 (Major)
        $testCases = ['250000.00', '500000.00', '999999.99'];

        foreach ($testCases as $value) {
            $asset = $this->createMock(Asset::class);
            $asset->method('getMonetaryValue')->willReturn($value);

            $risk = $this->createMock(Risk::class);
            $risk->method('getAsset')->willReturn($asset);

            $result = $this->service->calculateSuggestedImpact($risk);

            $this->assertEquals(4, $result, "Failed for value: $value");
        }
    }

    public function testCalculateSuggestedImpactCatastrophic(): void
    {
        // Loss > €1,000,000 = Impact 5 (Catastrophic)
        $testCases = ['1000000.00', '5000000.00', '10000000.00'];

        foreach ($testCases as $value) {
            $asset = $this->createMock(Asset::class);
            $asset->method('getMonetaryValue')->willReturn($value);

            $risk = $this->createMock(Risk::class);
            $risk->method('getAsset')->willReturn($asset);

            $result = $this->service->calculateSuggestedImpact($risk);

            $this->assertEquals(5, $result, "Failed for value: $value");
        }
    }

    public function testGetImpactCalculationDetailsWithNoMonetaryValue(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn(null);
        $risk->method('getImpact')->willReturn(3);

        $result = $this->service->getImpactCalculationDetails($risk);

        $this->assertNull($result['suggested_impact']);
        $this->assertEquals(3, $result['current_impact']);
        $this->assertFalse($result['should_update']);
        $this->assertStringContainsString('No monetary value', $result['rationale']);
    }

    public function testGetImpactCalculationDetailsWhenAligned(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getMonetaryValue')->willReturn('100000.00'); // Should suggest impact 3

        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn($asset);
        $risk->method('getImpact')->willReturn(3); // Already correct

        $result = $this->service->getImpactCalculationDetails($risk);

        $this->assertEquals(3, $result['suggested_impact']);
        $this->assertEquals(3, $result['current_impact']);
        $this->assertEquals(0, $result['difference']);
        $this->assertFalse($result['should_update']);
        $this->assertStringContainsString('aligns', $result['rationale']);
    }

    public function testGetImpactCalculationDetailsSuggestsHigher(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getMonetaryValue')->willReturn('1500000.00'); // Should suggest impact 5

        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn($asset);
        $risk->method('getImpact')->willReturn(2); // Currently too low

        $result = $this->service->getImpactCalculationDetails($risk);

        $this->assertEquals(5, $result['suggested_impact']);
        $this->assertEquals(2, $result['current_impact']);
        $this->assertEquals(3, $result['difference']); // 5 - 2
        $this->assertTrue($result['should_update']); // difference >= 2
        $this->assertStringContainsString('higher impact level', $result['rationale']);
    }

    public function testGetImpactCalculationDetailsSuggestsLower(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getMonetaryValue')->willReturn('5000.00'); // Should suggest impact 1

        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn($asset);
        $risk->method('getImpact')->willReturn(4); // Currently too high

        $result = $this->service->getImpactCalculationDetails($risk);

        $this->assertEquals(1, $result['suggested_impact']);
        $this->assertEquals(4, $result['current_impact']);
        $this->assertEquals(-3, $result['difference']); // 1 - 4
        $this->assertTrue($result['should_update']); // abs(difference) >= 2
        $this->assertStringContainsString('lower impact level', $result['rationale']);
    }

    public function testShouldUpdateOnlyWhenDifferenceIsSignificant(): void
    {
        // Difference of 1 should NOT trigger update
        $asset1 = $this->createMock(Asset::class);
        $asset1->method('getMonetaryValue')->willReturn('60000.00'); // Impact 3

        $risk1 = $this->createMock(Risk::class);
        $risk1->method('getAsset')->willReturn($asset1);
        $risk1->method('getImpact')->willReturn(2); // Difference = 1

        $result1 = $this->service->getImpactCalculationDetails($risk1);
        $this->assertFalse($result1['should_update']);

        // Difference of 2 SHOULD trigger update
        $asset2 = $this->createMock(Asset::class);
        $asset2->method('getMonetaryValue')->willReturn('300000.00'); // Impact 4

        $risk2 = $this->createMock(Risk::class);
        $risk2->method('getAsset')->willReturn($asset2);
        $risk2->method('getImpact')->willReturn(2); // Difference = 2

        $result2 = $this->service->getImpactCalculationDetails($risk2);
        $this->assertTrue($result2['should_update']);
    }

    public function testGetSuggestionReturnsFailureWhenNoMonetaryValue(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn(null);
        $risk->method('getImpact')->willReturn(3);

        $result = $this->service->getSuggestion($risk);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No monetary value', $result['message']);
        $this->assertEquals(3, $result['old_impact']);
        $this->assertNull($result['new_impact']);
    }

    public function testGetSuggestionReturnsFailureWhenAlreadyAligned(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getMonetaryValue')->willReturn('100000.00'); // Suggests 3

        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn($asset);
        $risk->method('getImpact')->willReturn(3); // Already 3

        $result = $this->service->getSuggestion($risk);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('already matches', $result['message']);
    }

    public function testGetSuggestionReturnsSuccessWithNewValue(): void
    {
        $asset = $this->createMock(Asset::class);
        $asset->method('getMonetaryValue')->willReturn('500000.00'); // Suggests 4

        $risk = $this->createMock(Risk::class);
        $risk->method('getAsset')->willReturn($asset);
        $risk->method('getImpact')->willReturn(2);

        $result = $this->service->getSuggestion($risk);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['old_impact']);
        $this->assertEquals(4, $result['new_impact']);
        $this->assertStringContainsString('Suggested impact: 4', $result['message']);
    }

    public function testUpdateRiskImpactRequiresConfirmation(): void
    {
        $risk = $this->createMock(Risk::class);

        $result = $this->service->updateRiskImpact($risk, 4, false);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('confirmation required', $result['message']);
    }

    public function testUpdateRiskImpactValidatesRange(): void
    {
        $risk = $this->createMock(Risk::class);

        // Test below minimum
        $result1 = $this->service->updateRiskImpact($risk, 0, true);
        $this->assertFalse($result1['success']);
        $this->assertStringContainsString('between 1 and 5', $result1['message']);

        // Test above maximum
        $result2 = $this->service->updateRiskImpact($risk, 6, true);
        $this->assertFalse($result2['success']);
        $this->assertStringContainsString('between 1 and 5', $result2['message']);
    }

    public function testUpdateRiskImpactSuccessfullyUpdates(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getImpact')->willReturn(2);
        $risk->method('getResidualImpact')->willReturn(1);
        $risk->expects($this->once())->method('setImpact')->with(4);
        $risk->expects($this->once())->method('setResidualImpact');

        $this->entityManager->expects($this->once())->method('persist')->with($risk);
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->updateRiskImpact($risk, 4, true);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('updated from 2 to 4', $result['message']);
    }

    public function testUpdateRiskImpactRecalculatesResidualImpact(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getImpact')->willReturn(2);
        $risk->method('getResidualImpact')->willReturn(1);

        // If impact goes from 2 to 4 (doubled), residual should also double: 1 * 2 = 2
        $risk->expects($this->once())
            ->method('setResidualImpact')
            ->with(2);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->updateRiskImpact($risk, 4, true);
    }

    public function testUpdateRiskImpactClampsResidualImpactTo5(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getImpact')->willReturn(2);
        $risk->method('getResidualImpact')->willReturn(4);

        // If impact goes from 2 to 5 (2.5x), residual would be 4 * 2.5 = 10
        // But should be clamped to 5
        $risk->expects($this->once())
            ->method('setResidualImpact')
            ->with(5); // Clamped maximum

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->updateRiskImpact($risk, 5, true);
    }

    public function testBoundaryValueAt10000(): void
    {
        // €9,999.99 should be impact 1
        $asset1 = $this->createMock(Asset::class);
        $asset1->method('getMonetaryValue')->willReturn('9999.99');
        $risk1 = $this->createMock(Risk::class);
        $risk1->method('getAsset')->willReturn($asset1);
        $this->assertEquals(1, $this->service->calculateSuggestedImpact($risk1));

        // €10,000.00 should be impact 2
        $asset2 = $this->createMock(Asset::class);
        $asset2->method('getMonetaryValue')->willReturn('10000.00');
        $risk2 = $this->createMock(Risk::class);
        $risk2->method('getAsset')->willReturn($asset2);
        $this->assertEquals(2, $this->service->calculateSuggestedImpact($risk2));
    }

    public function testBoundaryValueAt1Million(): void
    {
        // €999,999.99 should be impact 4
        $asset1 = $this->createMock(Asset::class);
        $asset1->method('getMonetaryValue')->willReturn('999999.99');
        $risk1 = $this->createMock(Risk::class);
        $risk1->method('getAsset')->willReturn($asset1);
        $this->assertEquals(4, $this->service->calculateSuggestedImpact($risk1));

        // €1,000,000.00 should be impact 5
        $asset2 = $this->createMock(Asset::class);
        $asset2->method('getMonetaryValue')->willReturn('1000000.00');
        $risk2 = $this->createMock(Risk::class);
        $risk2->method('getAsset')->willReturn($asset2);
        $this->assertEquals(5, $this->service->calculateSuggestedImpact($risk2));
    }
}
