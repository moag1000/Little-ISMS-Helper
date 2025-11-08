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

        // getTotalValue returns max(C, I, A)
        $this->assertEquals(5, $asset->getTotalValue());
    }

    public function testGetTotalValueWithMinimumValues(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(1);
        $asset->setIntegrityValue(1);
        $asset->setAvailabilityValue(1);

        // All values are 1, so max is 1
        $this->assertEquals(1, $asset->getTotalValue());
    }

    public function testGetTotalValueWithMaximumValues(): void
    {
        $asset = new Asset();
        $asset->setConfidentialityValue(5);
        $asset->setIntegrityValue(5);
        $asset->setAvailabilityValue(5);

        // All values are 5, so max is 5
        $this->assertEquals(5, $asset->getTotalValue());
    }

    // Note: Risk calculation tests moved to AssetRiskCalculatorTest
    // (Business logic now in dedicated service)

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
