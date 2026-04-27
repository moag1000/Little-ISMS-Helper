<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Risk;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class IncidentTest extends TestCase
{
    #[Test]
    public function testHasCriticalAssetsAffectedWithNoAssets(): void
    {
        $incident = new Incident();

        $this->assertFalse($incident->hasCriticalAssetsAffected());
    }

    #[Test]
    public function testHasCriticalAssetsAffectedWithOnlyLowRiskAssets(): void
    {
        $incident = new Incident();

        $asset = new Asset();
        $asset->setConfidentialityValue(2);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2);
        // Risk score = 20 < 70 (not high risk)

        $incident->addAffectedAsset($asset);

        $this->assertFalse($incident->hasCriticalAssetsAffected());
    }

    #[Test]
    public function testHasCriticalAssetsAffectedWithHighRiskAsset(): void
    {
        $incident = new Incident();

        // High-risk asset (need score >= 70)
        $asset = new Asset();
        $asset->setConfidentialityValue(5);
        $asset->setIntegrityValue(5);
        $asset->setAvailabilityValue(5);

        // Add risks to make it high-risk (base 50 + 20 = 70)
        for ($i = 0; $i < 4; $i++) {
            $risk = new Risk();
            $risk->setStatus(\App\Enum\RiskStatus::Open);
            $asset->addRisk($risk);
        }

        $incident->addAffectedAsset($asset);

        $this->assertTrue($incident->hasCriticalAssetsAffected());
    }

    #[Test]
    public function testHasCriticalAssetsAffectedWithMixedAssets(): void
    {
        $incident = new Incident();

        // Low-risk asset
        $asset1 = new Asset();
        $asset1->setConfidentialityValue(2);
        $asset1->setIntegrityValue(2);
        $asset1->setAvailabilityValue(2);

        // High-risk asset
        $asset2 = new Asset();
        $asset2->setConfidentialityValue(5);
        $asset2->setIntegrityValue(5);
        $asset2->setAvailabilityValue(5);
        for ($i = 0; $i < 4; $i++) {
            $risk = new Risk();
            $risk->setStatus(\App\Enum\RiskStatus::Open);
            $asset2->addRisk($risk);
        }

        $incident->addAffectedAsset($asset1);
        $incident->addAffectedAsset($asset2);

        // Should return true because at least one asset is high-risk
        $this->assertTrue($incident->hasCriticalAssetsAffected());
    }

    #[Test]
    public function testGetRealizedRiskCountWithNoRisks(): void
    {
        $incident = new Incident();

        $this->assertEquals(0, $incident->getRealizedRiskCount());
    }

    #[Test]
    public function testGetRealizedRiskCountWithMultipleRisks(): void
    {
        $incident = new Incident();

        $risk1 = new Risk();
        $risk2 = new Risk();
        $risk3 = new Risk();

        $incident->addRealizedRisk($risk1);
        $incident->addRealizedRisk($risk2);
        $incident->addRealizedRisk($risk3);

        $this->assertEquals(3, $incident->getRealizedRiskCount());
    }

    #[Test]
    public function testGetTotalAssetImpactWithNoAssets(): void
    {
        $incident = new Incident();

        $this->assertEquals(0, $incident->getTotalAssetImpact());
    }

    #[Test]
    public function testGetTotalAssetImpactWithSingleAsset(): void
    {
        $incident = new Incident();

        $asset = new Asset();
        $asset->setConfidentialityValue(3);
        $asset->setIntegrityValue(4);
        $asset->setAvailabilityValue(5);
        // getTotalValue() = max(3, 4, 5) = 5

        $incident->addAffectedAsset($asset);

        $this->assertEquals(5, $incident->getTotalAssetImpact());
    }

    #[Test]
    public function testGetTotalAssetImpactWithMultipleAssets(): void
    {
        $incident = new Incident();

        $asset1 = new Asset();
        $asset1->setConfidentialityValue(5);
        $asset1->setIntegrityValue(3);
        $asset1->setAvailabilityValue(4);
        // getTotalValue() = max(5, 3, 4) = 5

        $asset2 = new Asset();
        $asset2->setConfidentialityValue(2);
        $asset2->setIntegrityValue(3);
        $asset2->setAvailabilityValue(3);
        // getTotalValue() = max(2, 3, 3) = 3

        $asset3 = new Asset();
        $asset3->setConfidentialityValue(4);
        $asset3->setIntegrityValue(4);
        $asset3->setAvailabilityValue(2);
        // getTotalValue() = max(4, 4, 2) = 4

        $incident->addAffectedAsset($asset1);
        $incident->addAffectedAsset($asset2);
        $incident->addAffectedAsset($asset3);

        // Total = 5 + 3 + 4 = 12
        $this->assertEquals(12, $incident->getTotalAssetImpact());
    }

    #[Test]
    public function testIsRiskValidatedWithNoRisks(): void
    {
        $incident = new Incident();

        // No realized risks = not validated
        $this->assertFalse($incident->isRiskValidated());
    }

    #[Test]
    public function testIsRiskValidatedWithRisks(): void
    {
        $incident = new Incident();

        $risk = new Risk();
        $incident->addRealizedRisk($risk);

        // Has realized risk = validated
        $this->assertTrue($incident->isRiskValidated());
    }

    #[Test]
    public function testIsRiskValidatedWithMultipleRisks(): void
    {
        $incident = new Incident();

        $risk1 = new Risk();
        $risk2 = new Risk();

        $incident->addRealizedRisk($risk1);
        $incident->addRealizedRisk($risk2);

        $this->assertTrue($incident->isRiskValidated());
    }

    #[Test]
    public function testAddAndRemoveRelatedControl(): void
    {
        $incident = new Incident();
        $control = new Control();

        $this->assertEquals(0, $incident->getRelatedControls()->count());

        $incident->addRelatedControl($control);
        $this->assertEquals(1, $incident->getRelatedControls()->count());
        $this->assertTrue($incident->getRelatedControls()->contains($control));

        $incident->removeRelatedControl($control);
        $this->assertEquals(0, $incident->getRelatedControls()->count());
        $this->assertFalse($incident->getRelatedControls()->contains($control));
    }

    #[Test]
    public function testAddAndRemoveAffectedAsset(): void
    {
        $incident = new Incident();
        $asset = new Asset();

        $this->assertEquals(0, $incident->getAffectedAssets()->count());

        $incident->addAffectedAsset($asset);
        $this->assertEquals(1, $incident->getAffectedAssets()->count());
        $this->assertTrue($incident->getAffectedAssets()->contains($asset));

        $incident->removeAffectedAsset($asset);
        $this->assertEquals(0, $incident->getAffectedAssets()->count());
        $this->assertFalse($incident->getAffectedAssets()->contains($asset));
    }

    #[Test]
    public function testAddAndRemoveRealizedRisk(): void
    {
        $incident = new Incident();
        $risk = new Risk();

        $this->assertEquals(0, $incident->getRealizedRisks()->count());

        $incident->addRealizedRisk($risk);
        $this->assertEquals(1, $incident->getRealizedRisks()->count());
        $this->assertTrue($incident->getRealizedRisks()->contains($risk));

        $incident->removeRealizedRisk($risk);
        $this->assertEquals(0, $incident->getRealizedRisks()->count());
        $this->assertFalse($incident->getRealizedRisks()->contains($risk));
    }

    #[Test]
    public function testSeverityChoices(): void
    {
        $incident = new Incident();

        // Test valid severity values
        $validSeverities = [
            \App\Enum\IncidentSeverity::Critical,
            \App\Enum\IncidentSeverity::High,
            \App\Enum\IncidentSeverity::Medium,
            \App\Enum\IncidentSeverity::Low,
        ];

        foreach ($validSeverities as $severity) {
            $incident->setSeverity($severity);
            $this->assertEquals($severity, $incident->getSeverity());
        }
    }

    #[Test]
    public function testStatusChoices(): void
    {
        $incident = new Incident();

        // Test valid status values (using enum cases)
        $validStatuses = [
            \App\Enum\IncidentStatus::Reported,
            \App\Enum\IncidentStatus::InInvestigation,
            \App\Enum\IncidentStatus::Resolved,
            \App\Enum\IncidentStatus::Closed,
        ];

        foreach ($validStatuses as $status) {
            $incident->setStatus($status);
            $this->assertEquals($status, $incident->getStatus());
        }
    }

    #[Test]
    public function testDataBreachFlag(): void
    {
        $incident = new Incident();

        // Default should be false
        $this->assertFalse($incident->isDataBreachOccurred());

        $incident->setDataBreachOccurred(true);
        $this->assertTrue($incident->isDataBreachOccurred());

        $incident->setDataBreachOccurred(false);
        $this->assertFalse($incident->isDataBreachOccurred());
    }
}
