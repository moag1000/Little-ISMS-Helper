<?php

namespace App\Tests\Service;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Service\AssetRiskCalculator;
use PHPUnit\Framework\TestCase;

class AssetRiskCalculatorTest extends TestCase
{
    private AssetRiskCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new AssetRiskCalculator();
    }

    public function testCalculateRiskScoreWithNoRisksOrIncidents(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(3);
        $asset->setAvailabilityValue(3);

        // getTotalValue() = max(3, 3, 3) = 3
        // Base score = 3 * 10 = 30
        // No active risks = +0
        // No incidents = +0
        // No controls = -0
        // Result: max(0, min(100, 30)) = 30
        $this->assertEquals(30.0, $this->calculator->calculateRiskScore($asset));
    }

    public function testCalculateRiskScoreWithActiveRisks(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(2);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2);

        $risk1 = new Risk();
        $risk1->setStatus('active');
        $risk2 = new Risk();
        $risk2->setStatus('active');
        $risk3 = new Risk();
        $risk3->setStatus('closed'); // Should not count

        $asset->addRisk($risk1);
        $asset->addRisk($risk2);
        $asset->addRisk($risk3);

        // getTotalValue() = max(2, 2, 2) = 2
        // Base score = 2 * 10 = 20
        // Active risks = 2 * 5 = +10
        // Result: 20 + 10 = 30
        $this->assertEquals(30.0, $this->calculator->calculateRiskScore($asset));
    }

    public function testCalculateRiskScoreWithControls(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(3);
        $asset->setAvailabilityValue(3);

        $control1 = new Control();
        $control1->setImplementationPercentage(100);
        $control2 = new Control();
        $control2->setImplementationPercentage(80);

        $asset->addProtectingControl($control1);
        $asset->addProtectingControl($control2);

        // getTotalValue() = max(3, 3, 3) = 3
        // Base score = 3 * 10 = 30
        // No active risks = +0
        // No incidents = +0
        // Control reduction = 2 * 3 = -6
        // Result: max(0, min(100, 30 - 6)) = 24
        $this->assertEquals(24.0, $this->calculator->calculateRiskScore($asset));
    }

    public function testIsHighRisk(): void
    {
        $asset = new Asset();

        // High risk score (>= 70) - high value + active risks
        $asset->setConfidentialityValue(5);
        $asset->setIntegrityValue(5);
        $asset->setAvailabilityValue(5);

        $risk1 = new Risk();
        $risk1->setStatus('active');
        $risk2 = new Risk();
        $risk2->setStatus('active');
        $risk3 = new Risk();
        $risk3->setStatus('active');
        $risk4 = new Risk();
        $risk4->setStatus('active');

        $asset->addRisk($risk1);
        $asset->addRisk($risk2);
        $asset->addRisk($risk3);
        $asset->addRisk($risk4);

        // getTotalValue() = max(5, 5, 5) = 5
        // Base = 5 * 10 = 50
        // Active risks = 4 * 5 = 20
        // Total = 70 >= 70
        $this->assertTrue($this->calculator->isHighRisk($asset));

        // Low risk score
        $asset2 = new Asset();
        $asset2->setConfidentialityValue(2);
        $asset2->setIntegrityValue(2);
        $asset2->setAvailabilityValue(2);
        // score = 2 * 10 = 20 < 70
        $this->assertFalse($this->calculator->isHighRisk($asset2));

        // Boundary case: just below 70
        $asset3 = new Asset();
        $asset3->setConfidentialityValue(5);
        $asset3->setIntegrityValue(3);
        $asset3->setAvailabilityValue(3);

        $risk5 = new Risk();
        $risk5->setStatus('active');
        $risk6 = new Risk();
        $risk6->setStatus('active');
        $risk7 = new Risk();
        $risk7->setStatus('active');

        $asset3->addRisk($risk5);
        $asset3->addRisk($risk6);
        $asset3->addRisk($risk7);

        // getTotalValue() = max(5, 3, 3) = 5
        // Base = 5 * 10 = 50
        // Active risks = 3 * 5 = 15
        // Total = 65 < 70
        $this->assertFalse($this->calculator->isHighRisk($asset3));
    }

    public function testGetProtectionStatusUnprotected(): void
    {
        $asset = new Asset();

        $risk1 = new Risk();
        $risk1->setStatus('active');

        $asset->addRisk($risk1);
        // No controls, but has active risk

        $this->assertEquals('unprotected', $this->calculator->getProtectionStatus($asset));
    }

    public function testGetProtectionStatusUnderProtected(): void
    {
        $asset = new Asset();

        $risk1 = new Risk();
        $risk1->setStatus('active');
        $risk2 = new Risk();
        $risk2->setStatus('active');

        $control1 = new Control();

        $asset->addRisk($risk1);
        $asset->addRisk($risk2);
        $asset->addProtectingControl($control1);

        // 2 active risks but only 1 control
        $this->assertEquals('under_protected', $this->calculator->getProtectionStatus($asset));
    }

    public function testGetProtectionStatusAdequatelyProtected(): void
    {
        $asset = new Asset();

        $risk1 = new Risk();
        $risk1->setStatus('active');

        $control1 = new Control();
        $control2 = new Control();

        $asset->addRisk($risk1);
        $asset->addProtectingControl($control1);
        $asset->addProtectingControl($control2);

        // 1 active risk but 2 controls
        $this->assertEquals('adequately_protected', $this->calculator->getProtectionStatus($asset));
    }

    public function testGetProtectionStatusNoRisks(): void
    {
        $asset = new Asset();

        $control1 = new Control();
        $asset->addProtectingControl($control1);

        // No risks at all
        $this->assertEquals('adequately_protected', $this->calculator->getProtectionStatus($asset));
    }

    public function testCalculateRiskScoreWithRecentIncidents(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(2);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2);

        // Add a recent incident (within 6 months)
        $incident = new Incident();
        $incident->setDetectedAt(new \DateTimeImmutable('-2 months'));
        $asset->addIncident($incident);

        // Add an old incident (more than 6 months ago) - should not count
        $oldIncident = new Incident();
        $oldIncident->setDetectedAt(new \DateTimeImmutable('-8 months'));
        $asset->addIncident($oldIncident);

        // getTotalValue() = max(2, 2, 2) = 2
        // Base score = 2 * 10 = 20
        // Recent incidents = 1 * 10 = +10
        // Result: 20 + 10 = 30
        $this->assertEquals(30.0, $this->calculator->calculateRiskScore($asset));
    }

    public function testCalculateRiskScoreMaximum(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(5);
        $asset->setIntegrityValue(5);
        $asset->setAvailabilityValue(5);

        // Add many risks
        for ($i = 0; $i < 10; $i++) {
            $risk = new Risk();
            $risk->setStatus('active');
            $asset->addRisk($risk);
        }

        // Score should be capped at 100
        $score = $this->calculator->calculateRiskScore($asset);
        $this->assertLessThanOrEqual(100.0, $score);
        $this->assertEquals(100.0, $score);
    }

    public function testCalculateRiskScoreMinimum(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(1);
        $asset->setIntegrityValue(1);
        $asset->setAvailabilityValue(1);

        // Add many controls (more than enough to go negative)
        for ($i = 0; $i < 20; $i++) {
            $control = new Control();
            $control->setImplementationPercentage(100);
            $asset->addProtectingControl($control);
        }

        // Score should be floored at 0
        $score = $this->calculator->calculateRiskScore($asset);
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertEquals(0.0, $score);
    }
}
