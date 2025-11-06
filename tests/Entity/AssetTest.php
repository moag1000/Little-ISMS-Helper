<?php

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Risk;
use PHPUnit\Framework\TestCase;

class AssetTest extends TestCase
{
    public function testGetTotalValue(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(4);
        $asset->setAvailabilityValue(5);

        $this->assertEquals(12, $asset->getTotalValue());
    }

    public function testGetTotalValueWithMinimumValues(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(1);
        $asset->setIntegrityValue(1);
        $asset->setAvailabilityValue(1);

        $this->assertEquals(3, $asset->getTotalValue());
    }

    public function testGetTotalValueWithMaximumValues(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(5);
        $asset->setIntegrityValue(5);
        $asset->setAvailabilityValue(5);

        $this->assertEquals(15, $asset->getTotalValue());
    }

    public function testGetRiskScoreWithNoRisksOrIncidents(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(3);
        $asset->setAvailabilityValue(3);

        // Base score = totalValue * 10 = 9 * 10 = 90
        // No active risks = -0
        // No incidents = -0
        // No controls = +0
        // Result: max(0, min(100, 90)) = 90
        $this->assertEquals(90.0, $asset->getRiskScore());
    }

    public function testGetRiskScoreWithActiveRisks(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(2);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2); // totalValue = 6

        $risk1 = new Risk();
        $risk1->setStatus('active');
        $risk2 = new Risk();
        $risk2->setStatus('active');
        $risk3 = new Risk();
        $risk3->setStatus('closed'); // Should not count

        $asset->addRisk($risk1);
        $asset->addRisk($risk2);
        $asset->addRisk($risk3);

        // Base score = 6 * 10 = 60
        // Active risks = 2 * 5 = +10
        // Result: 60 + 10 = 70
        $this->assertEquals(70.0, $asset->getRiskScore());
    }

    public function testGetRiskScoreWithControls(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(3);
        $asset->setAvailabilityValue(3); // totalValue = 9

        $control1 = new Control();
        $control1->setImplementationPercentage(100);
        $control2 = new Control();
        $control2->setImplementationPercentage(80);

        $asset->addProtectingControl($control1);
        $asset->addProtectingControl($control2);

        // Base score = 9 * 10 = 90
        // 2 controls, avg implementation = (100 + 80) / 2 = 90
        // Control reduction = 2 * (90 / 100) * 10 = 18
        // Result: max(0, min(100, 90 - 18)) = 72
        $this->assertEquals(72.0, $asset->getRiskScore());
    }

    public function testIsHighRisk(): void
    {
        $asset = new Asset();

        // High risk score (> 70)
        $asset->setConfidentialityValue(5);
        $asset->setIntegrityValue(5);
        $asset->setAvailabilityValue(5); // score = 15 * 10 = 150 -> clamped to 100
        $this->assertTrue($asset->isHighRisk());

        // Low risk score
        $asset->setConfidentialityValue(2);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2); // score = 6 * 10 = 60
        $this->assertFalse($asset->isHighRisk());

        // Boundary case: exactly 70
        // Need specific conditions to get exactly 70
        $asset = new Asset();
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2); // totalValue = 7, score = 70
        $this->assertFalse($asset->isHighRisk()); // Should be > 70, not >= 70
    }

    public function testGetProtectionStatusUnprotected(): void
    {
        $asset = new Asset();

        $risk1 = new Risk();
        $risk1->setStatus('active');

        $asset->addRisk($risk1);
        // No controls, but has active risk

        $this->assertEquals('unprotected', $asset->getProtectionStatus());
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
        $this->assertEquals('under_protected', $asset->getProtectionStatus());
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
        $this->assertEquals('adequately_protected', $asset->getProtectionStatus());
    }

    public function testGetProtectionStatusNoRisks(): void
    {
        $asset = new Asset();

        $control1 = new Control();
        $asset->addProtectingControl($control1);

        // No risks at all
        $this->assertEquals('adequately_protected', $asset->getProtectionStatus());
    }

    public function testAddAndRemoveRisk(): void
    {
        $asset = new Asset();
        $risk = new Risk();

        $this->assertEquals(0, $asset->getRisks()->count());

        $asset->addRisk($risk);
        $this->assertEquals(1, $asset->getRisks()->count());
        $this->assertTrue($asset->getRisks()->contains($risk));

        $asset->removeRisk($risk);
        $this->assertEquals(0, $asset->getRisks()->count());
        $this->assertFalse($asset->getRisks()->contains($risk));
    }

    public function testAddAndRemoveIncident(): void
    {
        $asset = new Asset();
        $incident = new Incident();

        $this->assertEquals(0, $asset->getIncidents()->count());

        $asset->addIncident($incident);
        $this->assertEquals(1, $asset->getIncidents()->count());
        $this->assertTrue($asset->getIncidents()->contains($incident));

        $asset->removeIncident($incident);
        $this->assertEquals(0, $asset->getIncidents()->count());
        $this->assertFalse($asset->getIncidents()->contains($incident));
    }

    public function testAddAndRemoveProtectingControl(): void
    {
        $asset = new Asset();
        $control = new Control();

        $this->assertEquals(0, $asset->getProtectingControls()->count());

        $asset->addProtectingControl($control);
        $this->assertEquals(1, $asset->getProtectingControls()->count());
        $this->assertTrue($asset->getProtectingControls()->contains($control));

        $asset->removeProtectingControl($control);
        $this->assertEquals(0, $asset->getProtectingControls()->count());
        $this->assertFalse($asset->getProtectingControls()->contains($control));
    }
}
