<?php

namespace App\Tests\Entity;

use App\Entity\BusinessProcess;
use App\Entity\Asset;
use App\Entity\Risk;
use PHPUnit\Framework\TestCase;

class BusinessProcessTest extends TestCase
{
    public function testNewBusinessProcessHasDefaultValues(): void
    {
        $process = new BusinessProcess();

        $this->assertNull($process->getId());
        $this->assertNull($process->getName());
        $this->assertNull($process->getDescription());
        $this->assertNull($process->getProcessOwner());
        $this->assertNull($process->getCriticality());
        $this->assertNull($process->getRto());
        $this->assertNull($process->getRpo());
        $this->assertNull($process->getMtpd());
        $this->assertNull($process->getFinancialImpactPerHour());
        $this->assertNull($process->getFinancialImpactPerDay());
        $this->assertNull($process->getReputationalImpact());
        $this->assertNull($process->getRegulatoryImpact());
        $this->assertNull($process->getOperationalImpact());
        $this->assertNull($process->getDependenciesUpstream());
        $this->assertNull($process->getDependenciesDownstream());
        $this->assertNull($process->getRecoveryStrategy());
        $this->assertInstanceOf(\DateTimeImmutable::class, $process->getCreatedAt());
        $this->assertNull($process->getUpdatedAt());
        $this->assertCount(0, $process->getSupportingAssets());
        $this->assertCount(0, $process->getIdentifiedRisks());
    }

    public function testSetAndGetName(): void
    {
        $process = new BusinessProcess();
        $process->setName('Order Processing System');

        $this->assertEquals('Order Processing System', $process->getName());
    }

    public function testSetAndGetDescription(): void
    {
        $process = new BusinessProcess();
        $process->setDescription('Handles all customer orders from receipt to fulfillment');

        $this->assertEquals('Handles all customer orders from receipt to fulfillment', $process->getDescription());
    }

    public function testSetAndGetProcessOwner(): void
    {
        $process = new BusinessProcess();
        $process->setProcessOwner('Sales Director');

        $this->assertEquals('Sales Director', $process->getProcessOwner());
    }

    public function testSetAndGetCriticality(): void
    {
        $process = new BusinessProcess();
        $process->setCriticality('critical');

        $this->assertEquals('critical', $process->getCriticality());
    }

    public function testSetAndGetRTO(): void
    {
        $process = new BusinessProcess();
        $process->setRto(4); // 4 hours

        $this->assertEquals(4, $process->getRto());
    }

    public function testSetAndGetRPO(): void
    {
        $process = new BusinessProcess();
        $process->setRpo(1); // 1 hour

        $this->assertEquals(1, $process->getRpo());
    }

    public function testSetAndGetMTPD(): void
    {
        $process = new BusinessProcess();
        $process->setMtpd(24); // 24 hours

        $this->assertEquals(24, $process->getMtpd());
    }

    public function testSetAndGetFinancialImpacts(): void
    {
        $process = new BusinessProcess();
        $process->setFinancialImpactPerHour('5000.00');
        $process->setFinancialImpactPerDay('120000.00');

        $this->assertEquals('5000.00', $process->getFinancialImpactPerHour());
        $this->assertEquals('120000.00', $process->getFinancialImpactPerDay());
    }

    public function testSetAndGetImpactScores(): void
    {
        $process = new BusinessProcess();
        $process->setReputationalImpact(5);
        $process->setRegulatoryImpact(4);
        $process->setOperationalImpact(3);

        $this->assertEquals(5, $process->getReputationalImpact());
        $this->assertEquals(4, $process->getRegulatoryImpact());
        $this->assertEquals(3, $process->getOperationalImpact());
    }

    public function testSetAndGetDependencies(): void
    {
        $process = new BusinessProcess();
        $process->setDependenciesUpstream('CRM System, Inventory Management');
        $process->setDependenciesDownstream('Shipping System, Accounting');

        $this->assertEquals('CRM System, Inventory Management', $process->getDependenciesUpstream());
        $this->assertEquals('Shipping System, Accounting', $process->getDependenciesDownstream());
    }

    public function testSetAndGetRecoveryStrategy(): void
    {
        $process = new BusinessProcess();
        $process->setRecoveryStrategy('Failover to backup datacenter within 2 hours');

        $this->assertEquals('Failover to backup datacenter within 2 hours', $process->getRecoveryStrategy());
    }

    public function testAddAndRemoveSupportingAsset(): void
    {
        $process = new BusinessProcess();
        $asset = new Asset();
        $asset->setName('Application Server');

        $this->assertCount(0, $process->getSupportingAssets());

        $process->addSupportingAsset($asset);
        $this->assertCount(1, $process->getSupportingAssets());
        $this->assertTrue($process->getSupportingAssets()->contains($asset));

        $process->removeSupportingAsset($asset);
        $this->assertCount(0, $process->getSupportingAssets());
    }

    public function testAddSupportingAssetDoesNotDuplicate(): void
    {
        $process = new BusinessProcess();
        $asset = new Asset();
        $asset->setName('Database Server');

        $process->addSupportingAsset($asset);
        $process->addSupportingAsset($asset);

        $this->assertCount(1, $process->getSupportingAssets());
    }

    public function testAddAndRemoveIdentifiedRisk(): void
    {
        $process = new BusinessProcess();
        $risk = new Risk();
        $risk->setTitle('Service Disruption Risk');

        $process->addIdentifiedRisk($risk);
        $this->assertCount(1, $process->getIdentifiedRisks());

        $process->removeIdentifiedRisk($risk);
        $this->assertCount(0, $process->getIdentifiedRisks());
    }

    public function testGetBusinessImpactScoreCalculatesAverage(): void
    {
        $process = new BusinessProcess();
        $process->setReputationalImpact(5);
        $process->setRegulatoryImpact(4);
        $process->setOperationalImpact(3);

        // Average: (5 + 4 + 3) / 3 = 4
        $this->assertEquals(4, $process->getBusinessImpactScore());
    }

    public function testGetBusinessImpactScoreRoundsCorrectly(): void
    {
        $process = new BusinessProcess();
        $process->setReputationalImpact(5);
        $process->setRegulatoryImpact(5);
        $process->setOperationalImpact(4);

        // Average: (5 + 5 + 4) / 3 = 4.666... rounds to 5
        $this->assertEquals(5, $process->getBusinessImpactScore());
    }

    public function testGetSuggestedAvailabilityValueBasedOnRTO(): void
    {
        $process = new BusinessProcess();

        // RTO <= 1 hour = Very high (5)
        $process->setRto(1);
        $this->assertEquals(5, $process->getSuggestedAvailabilityValue());

        // RTO <= 4 hours = High (4)
        $process->setRto(3);
        $this->assertEquals(4, $process->getSuggestedAvailabilityValue());

        // RTO <= 24 hours = Medium (3)
        $process->setRto(12);
        $this->assertEquals(3, $process->getSuggestedAvailabilityValue());

        // RTO <= 72 hours = Low (2)
        $process->setRto(48);
        $this->assertEquals(2, $process->getSuggestedAvailabilityValue());

        // RTO > 72 hours = Very low (1)
        $process->setRto(100);
        $this->assertEquals(1, $process->getSuggestedAvailabilityValue());
    }

    public function testGetProcessRiskLevelReturnsUnknownWhenNoRisks(): void
    {
        $process = new BusinessProcess();

        $this->assertEquals('unknown', $process->getProcessRiskLevel());
    }

    public function testGetActiveRiskCountReturnsZeroWhenNoRisks(): void
    {
        $process = new BusinessProcess();

        $this->assertEquals(0, $process->getActiveRiskCount());
    }

    public function testHasUnmitigatedHighRisksReturnsFalseWhenNoRisks(): void
    {
        $process = new BusinessProcess();

        $this->assertFalse($process->hasUnmitigatedHighRisks());
    }

    public function testIsCriticalityAlignedReturnsTrueWhenNoRisks(): void
    {
        $process = new BusinessProcess();
        $process->setCriticality('critical');

        // Cannot validate without risk data
        $this->assertTrue($process->isCriticalityAligned());
    }

    public function testGetSuggestedRTOReturnsCurrentRTOWhenNoRisks(): void
    {
        $process = new BusinessProcess();
        $process->setRto(24);

        // Should return current RTO when risk level is unknown
        $this->assertEquals(24, $process->getSuggestedRTO());
    }

    public function testBusinessProcessCanStoreCompleteBIAData(): void
    {
        $process = new BusinessProcess();

        $process->setName('Customer Portal');
        $process->setProcessOwner('IT Director');
        $process->setCriticality('high');
        $process->setRto(2);
        $process->setRpo(1);
        $process->setMtpd(6);
        $process->setFinancialImpactPerHour('10000.00');
        $process->setFinancialImpactPerDay('240000.00');
        $process->setReputationalImpact(5);
        $process->setRegulatoryImpact(3);
        $process->setOperationalImpact(4);
        $process->setRecoveryStrategy('Use backup site');

        $this->assertEquals('Customer Portal', $process->getName());
        $this->assertEquals('high', $process->getCriticality());
        $this->assertEquals(2, $process->getRto());
        $this->assertEquals('10000.00', $process->getFinancialImpactPerHour());
        $this->assertEquals(4, $process->getBusinessImpactScore()); // (5+3+4)/3
        $this->assertEquals(4, $process->getSuggestedAvailabilityValue()); // RTO=2 -> High (4)
    }
}
