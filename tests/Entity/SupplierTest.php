<?php

namespace App\Tests\Entity;

use App\Entity\Supplier;
use App\Entity\Asset;
use App\Entity\Risk;
use App\Entity\Document;
use PHPUnit\Framework\TestCase;

class SupplierTest extends TestCase
{
    public function testNewSupplierHasDefaultValues(): void
    {
        $supplier = new Supplier();

        $this->assertNull($supplier->getId());
        $this->assertNull($supplier->getTenant());
        $this->assertNull($supplier->getName());
        $this->assertNull($supplier->getDescription());
        $this->assertNull($supplier->getContactPerson());
        $this->assertNull($supplier->getEmail());
        $this->assertNull($supplier->getPhone());
        $this->assertNull($supplier->getAddress());
        $this->assertNull($supplier->getServiceProvided());
        $this->assertEquals('medium', $supplier->getCriticality());
        $this->assertEquals('evaluation', $supplier->getStatus());
        $this->assertNull($supplier->getSecurityScore());
        $this->assertNull($supplier->getLastSecurityAssessment());
        $this->assertNull($supplier->getNextAssessmentDate());
        $this->assertNull($supplier->getAssessmentFindings());
        $this->assertNull($supplier->getNonConformities());
        $this->assertNull($supplier->getContractualSLAs());
        $this->assertNull($supplier->getContractStartDate());
        $this->assertNull($supplier->getContractEndDate());
        $this->assertNull($supplier->getSecurityRequirements());
        $this->assertFalse($supplier->isHasISO27001());
        $this->assertFalse($supplier->isHasISO22301());
        $this->assertNull($supplier->getCertifications());
        $this->assertFalse($supplier->isHasDPA());
        $this->assertNull($supplier->getDpaSignedDate());
        $this->assertCount(0, $supplier->getSupportedAssets());
        $this->assertCount(0, $supplier->getIdentifiedRisks());
        $this->assertCount(0, $supplier->getDocuments());
        $this->assertInstanceOf(\DateTime::class, $supplier->getCreatedAt());
        $this->assertNull($supplier->getUpdatedAt());
    }

    public function testSetAndGetName(): void
    {
        $supplier = new Supplier();
        $supplier->setName('Cloud Services Provider GmbH');

        $this->assertEquals('Cloud Services Provider GmbH', $supplier->getName());
    }

    public function testSetAndGetContactDetails(): void
    {
        $supplier = new Supplier();
        $supplier->setContactPerson('Max Mustermann');
        $supplier->setEmail('max@provider.com');
        $supplier->setPhone('+49 123 456789');
        $supplier->setAddress('Musterstrasse 123, 12345 Berlin');

        $this->assertEquals('Max Mustermann', $supplier->getContactPerson());
        $this->assertEquals('max@provider.com', $supplier->getEmail());
        $this->assertEquals('+49 123 456789', $supplier->getPhone());
        $this->assertEquals('Musterstrasse 123, 12345 Berlin', $supplier->getAddress());
    }

    public function testSetAndGetServiceProvided(): void
    {
        $supplier = new Supplier();
        $supplier->setServiceProvided('Cloud infrastructure hosting and managed services');

        $this->assertEquals('Cloud infrastructure hosting and managed services', $supplier->getServiceProvided());
    }

    public function testSetAndGetCriticality(): void
    {
        $supplier = new Supplier();

        $supplier->setCriticality('critical');
        $this->assertEquals('critical', $supplier->getCriticality());

        $supplier->setCriticality('low');
        $this->assertEquals('low', $supplier->getCriticality());
    }

    public function testSetAndGetStatus(): void
    {
        $supplier = new Supplier();

        $supplier->setStatus('active');
        $this->assertEquals('active', $supplier->getStatus());

        $supplier->setStatus('terminated');
        $this->assertEquals('terminated', $supplier->getStatus());
    }

    public function testSetAndGetSecurityScore(): void
    {
        $supplier = new Supplier();
        $supplier->setSecurityScore(85);

        $this->assertEquals(85, $supplier->getSecurityScore());
    }

    public function testSetAndGetAssessmentDates(): void
    {
        $supplier = new Supplier();
        $lastAssessment = new \DateTime('2024-01-15');
        $nextAssessment = new \DateTime('2024-07-15');

        $supplier->setLastSecurityAssessment($lastAssessment);
        $supplier->setNextAssessmentDate($nextAssessment);

        $this->assertEquals($lastAssessment, $supplier->getLastSecurityAssessment());
        $this->assertEquals($nextAssessment, $supplier->getNextAssessmentDate());
    }

    public function testSetAndGetContractDates(): void
    {
        $supplier = new Supplier();
        $startDate = new \DateTime('2024-01-01');
        $endDate = new \DateTime('2025-12-31');

        $supplier->setContractStartDate($startDate);
        $supplier->setContractEndDate($endDate);

        $this->assertEquals($startDate, $supplier->getContractStartDate());
        $this->assertEquals($endDate, $supplier->getContractEndDate());
    }

    public function testSetAndGetCertifications(): void
    {
        $supplier = new Supplier();

        $supplier->setHasISO27001(true);
        $this->assertTrue($supplier->isHasISO27001());

        $supplier->setHasISO22301(true);
        $this->assertTrue($supplier->isHasISO22301());

        $supplier->setCertifications('ISO 27001, ISO 22301, SOC 2 Type II');
        $this->assertEquals('ISO 27001, ISO 22301, SOC 2 Type II', $supplier->getCertifications());
    }

    public function testSetAndGetDPA(): void
    {
        $supplier = new Supplier();
        $dpaDate = new \DateTime('2024-01-01');

        $supplier->setHasDPA(true);
        $supplier->setDpaSignedDate($dpaDate);

        $this->assertTrue($supplier->isHasDPA());
        $this->assertEquals($dpaDate, $supplier->getDpaSignedDate());
    }

    public function testAddAndRemoveSupportedAsset(): void
    {
        $supplier = new Supplier();
        $asset = new Asset();
        $asset->setName('Cloud Infrastructure');

        $supplier->addSupportedAsset($asset);
        $this->assertCount(1, $supplier->getSupportedAssets());
        $this->assertTrue($supplier->getSupportedAssets()->contains($asset));

        $supplier->removeSupportedAsset($asset);
        $this->assertCount(0, $supplier->getSupportedAssets());
    }

    public function testAddSupportedAssetDoesNotDuplicate(): void
    {
        $supplier = new Supplier();
        $asset = new Asset();
        $asset->setName('Cloud Infrastructure');

        $supplier->addSupportedAsset($asset);
        $supplier->addSupportedAsset($asset);

        $this->assertCount(1, $supplier->getSupportedAssets());
    }

    public function testAddAndRemoveIdentifiedRisk(): void
    {
        $supplier = new Supplier();
        $risk = new Risk();
        $risk->setTitle('Vendor Lock-in Risk');

        $supplier->addIdentifiedRisk($risk);
        $this->assertCount(1, $supplier->getIdentifiedRisks());

        $supplier->removeIdentifiedRisk($risk);
        $this->assertCount(0, $supplier->getIdentifiedRisks());
    }

    public function testAddAndRemoveDocument(): void
    {
        $supplier = new Supplier();
        $document = new Document();
        $document->setOriginalFilename('Service Level Agreement.pdf');

        $supplier->addDocument($document);
        $this->assertCount(1, $supplier->getDocuments());

        $supplier->removeDocument($document);
        $this->assertCount(0, $supplier->getDocuments());
    }

    public function testCalculateRiskScoreWithHighCriticalityAndNoSecurityScore(): void
    {
        $supplier = new Supplier();
        $supplier->setCriticality('critical');
        $supplier->setHasISO27001(false);
        $supplier->setHasDPA(false);

        // 40 (critical) + 30 (no security score) + 10 (no ISO27001) + 5 (no ISO22301 for critical) + 10 (no DPA) + 5 (overdue - no assessment) = 100
        $this->assertEquals(100, $supplier->calculateRiskScore());
    }

    public function testCalculateRiskScoreWithGoodSecurityScore(): void
    {
        $supplier = new Supplier();
        $supplier->setCriticality('medium');
        $supplier->setSecurityScore(90); // (100-90)*0.3 = 3
        $supplier->setHasISO27001(true);
        $supplier->setHasDPA(true);

        // 15 (medium) + 3 (high security score) + 5 (overdue - no lastSecurityAssessment) = 23
        $this->assertEquals(23, $supplier->calculateRiskScore());
    }

    public function testCalculateRiskScoreIsCappedAt100(): void
    {
        $supplier = new Supplier();
        $supplier->setCriticality('critical'); // 40
        $supplier->setSecurityScore(0); // 30
        $supplier->setHasISO27001(false); // 10
        $supplier->setHasISO22301(false); // 5 (for critical)
        $supplier->setHasDPA(false); // 10
        $supplier->setNextAssessmentDate(new \DateTime('-1 day')); // 5 (overdue)

        // Would be 100, capped at 100
        $this->assertEquals(100, $supplier->calculateRiskScore());
    }

    public function testIsAssessmentOverdueReturnsFalseWhenNotOverdue(): void
    {
        $supplier = new Supplier();
        $supplier->setNextAssessmentDate(new \DateTime('+30 days'));

        $this->assertFalse($supplier->isAssessmentOverdue());
    }

    public function testIsAssessmentOverdueReturnsTrueWhenOverdue(): void
    {
        $supplier = new Supplier();
        $supplier->setNextAssessmentDate(new \DateTime('-1 day'));

        $this->assertTrue($supplier->isAssessmentOverdue());
    }

    public function testIsAssessmentOverdueReturnsTrueWhenNeverAssessed(): void
    {
        $supplier = new Supplier();

        $this->assertTrue($supplier->isAssessmentOverdue());
    }

    public function testGetAssessmentStatusReturnsNotAssessedWhenNeverAssessed(): void
    {
        $supplier = new Supplier();

        $this->assertEquals('not_assessed', $supplier->getAssessmentStatus());
    }

    public function testGetAssessmentStatusReturnsOverdueWhenPastDue(): void
    {
        $supplier = new Supplier();
        $supplier->setLastSecurityAssessment(new \DateTime('-1 year'));
        $supplier->setNextAssessmentDate(new \DateTime('-1 day'));

        $this->assertEquals('overdue', $supplier->getAssessmentStatus());
    }

    public function testGetAssessmentStatusReturnsDueSoonWhenWithin30Days(): void
    {
        $supplier = new Supplier();
        $supplier->setLastSecurityAssessment(new \DateTime('-6 months'));
        $supplier->setNextAssessmentDate(new \DateTime('+15 days'));

        $this->assertEquals('due_soon', $supplier->getAssessmentStatus());
    }

    public function testGetAssessmentStatusReturnsCurrentWhenNotDueSoon(): void
    {
        $supplier = new Supplier();
        $supplier->setLastSecurityAssessment(new \DateTime('-3 months'));
        $supplier->setNextAssessmentDate(new \DateTime('+3 months'));

        $this->assertEquals('current', $supplier->getAssessmentStatus());
    }

    public function testGetAggregatedRiskLevelReturnsUnknownWhenNoRisks(): void
    {
        $supplier = new Supplier();

        $this->assertEquals('unknown', $supplier->getAggregatedRiskLevel());
    }

    public function testSupportsCriticalAssetsReturnsFalseWhenNoAssets(): void
    {
        $supplier = new Supplier();

        $this->assertFalse($supplier->supportsCriticalAssets());
    }

    public function testGetComplianceStatusReturnsCorrectStatus(): void
    {
        $supplier = new Supplier();
        $supplier->setHasISO27001(true);
        $supplier->setHasDPA(true);
        $supplier->setLastSecurityAssessment(new \DateTime('-1 month'));
        $supplier->setNextAssessmentDate(new \DateTime('+5 months'));

        $status = $supplier->getComplianceStatus();

        $this->assertTrue($status['iso27001']);
        $this->assertTrue($status['dpa']);
        $this->assertTrue($status['security_assessment']);
        $this->assertTrue($status['overall_compliant']);
    }

    public function testGetComplianceStatusReturnsFalseWhenNotCompliant(): void
    {
        $supplier = new Supplier();
        $supplier->setHasISO27001(false);
        $supplier->setHasDPA(true);
        $supplier->setNextAssessmentDate(new \DateTime('-1 day')); // overdue

        $status = $supplier->getComplianceStatus();

        $this->assertFalse($status['iso27001']);
        $this->assertFalse($status['security_assessment']);
        $this->assertFalse($status['overall_compliant']);
    }
}
