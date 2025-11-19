<?php

namespace App\Tests\Entity;

use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceFramework;
use PHPUnit\Framework\TestCase;

class ComplianceMappingTest extends TestCase
{
    public function testNewComplianceMappingHasDefaultValues(): void
    {
        $mapping = new ComplianceMapping();

        $this->assertNull($mapping->getId());
        $this->assertNull($mapping->getSourceRequirement());
        $this->assertNull($mapping->getTargetRequirement());
        $this->assertEquals(0, $mapping->getMappingPercentage());
        $this->assertNull($mapping->getMappingType());
        $this->assertNull($mapping->getMappingRationale());
        $this->assertFalse($mapping->isBidirectional());
        $this->assertEquals('medium', $mapping->getConfidence());
        $this->assertNull($mapping->getVerifiedBy());
        $this->assertNull($mapping->getVerificationDate());
        $this->assertInstanceOf(\DateTimeImmutable::class, $mapping->getCreatedAt());
        $this->assertNull($mapping->getUpdatedAt());
    }

    public function testSetAndGetSourceRequirement(): void
    {
        $mapping = new ComplianceMapping();
        $requirement = new ComplianceRequirement();
        $requirement->setRequirementId('ISO-27001-A.5.1');

        $mapping->setSourceRequirement($requirement);

        $this->assertSame($requirement, $mapping->getSourceRequirement());
    }

    public function testSetAndGetTargetRequirement(): void
    {
        $mapping = new ComplianceMapping();
        $requirement = new ComplianceRequirement();
        $requirement->setRequirementId('GDPR-Art-32');

        $mapping->setTargetRequirement($requirement);

        $this->assertSame($requirement, $mapping->getTargetRequirement());
    }

    public function testSetMappingPercentageAutoUpdatesType(): void
    {
        $mapping = new ComplianceMapping();

        // Test weak mapping
        $mapping->setMappingPercentage(30);
        $this->assertEquals(30, $mapping->getMappingPercentage());
        $this->assertEquals('weak', $mapping->getMappingType());

        // Test partial mapping
        $mapping->setMappingPercentage(75);
        $this->assertEquals(75, $mapping->getMappingPercentage());
        $this->assertEquals('partial', $mapping->getMappingType());

        // Test full mapping
        $mapping->setMappingPercentage(100);
        $this->assertEquals(100, $mapping->getMappingPercentage());
        $this->assertEquals('full', $mapping->getMappingType());

        // Test exceeds mapping
        $mapping->setMappingPercentage(120);
        $this->assertEquals(120, $mapping->getMappingPercentage());
        $this->assertEquals('exceeds', $mapping->getMappingType());
    }

    public function testSetMappingPercentageClampsBetween0And150(): void
    {
        $mapping = new ComplianceMapping();

        // Test below minimum
        $mapping->setMappingPercentage(-10);
        $this->assertEquals(0, $mapping->getMappingPercentage());

        // Test above maximum
        $mapping->setMappingPercentage(200);
        $this->assertEquals(150, $mapping->getMappingPercentage());
    }

    public function testSetAndGetMappingRationale(): void
    {
        $mapping = new ComplianceMapping();
        $rationale = 'Both requirements address access control mechanisms';

        $mapping->setMappingRationale($rationale);

        $this->assertEquals($rationale, $mapping->getMappingRationale());
    }

    public function testSetAndGetBidirectional(): void
    {
        $mapping = new ComplianceMapping();

        $this->assertFalse($mapping->isBidirectional());

        $mapping->setBidirectional(true);
        $this->assertTrue($mapping->isBidirectional());

        $mapping->setBidirectional(false);
        $this->assertFalse($mapping->isBidirectional());
    }

    public function testSetAndGetConfidence(): void
    {
        $mapping = new ComplianceMapping();

        $mapping->setConfidence('high');
        $this->assertEquals('high', $mapping->getConfidence());

        $mapping->setConfidence('low');
        $this->assertEquals('low', $mapping->getConfidence());
    }

    public function testSetAndGetVerifiedBy(): void
    {
        $mapping = new ComplianceMapping();
        $mapping->setVerifiedBy('Compliance Officer');

        $this->assertEquals('Compliance Officer', $mapping->getVerifiedBy());
    }

    public function testSetAndGetVerificationDate(): void
    {
        $mapping = new ComplianceMapping();
        $date = new \DateTimeImmutable('2024-01-15');

        $mapping->setVerificationDate($date);

        $this->assertEquals($date, $mapping->getVerificationDate());
    }

    public function testSetAndGetCreatedAt(): void
    {
        $mapping = new ComplianceMapping();
        $date = new \DateTimeImmutable('2024-01-01');

        $mapping->setCreatedAt($date);

        $this->assertEquals($date, $mapping->getCreatedAt());
    }

    public function testSetAndGetUpdatedAt(): void
    {
        $mapping = new ComplianceMapping();
        $date = new \DateTimeImmutable('2024-01-20');

        $mapping->setUpdatedAt($date);

        $this->assertEquals($date, $mapping->getUpdatedAt());
    }

    public function testGetMappingBadgeClass(): void
    {
        $mapping = new ComplianceMapping();

        $mapping->setMappingPercentage(30); // weak
        $this->assertEquals('secondary', $mapping->getMappingBadgeClass());

        $mapping->setMappingPercentage(75); // partial
        $this->assertEquals('warning', $mapping->getMappingBadgeClass());

        $mapping->setMappingPercentage(100); // full
        $this->assertEquals('success', $mapping->getMappingBadgeClass());

        $mapping->setMappingPercentage(120); // exceeds
        $this->assertEquals('success', $mapping->getMappingBadgeClass());
    }

    public function testGetMappingDescription(): void
    {
        $mapping = new ComplianceMapping();

        $mapping->setMappingPercentage(30);
        $this->assertEquals('Weak relationship (30%)', $mapping->getMappingDescription());

        $mapping->setMappingPercentage(75);
        $this->assertEquals('Partially satisfies target requirement (75%)', $mapping->getMappingDescription());

        $mapping->setMappingPercentage(100);
        $this->assertEquals('Fully satisfies target requirement (100%)', $mapping->getMappingDescription());

        $mapping->setMappingPercentage(120);
        $this->assertEquals('Exceeds target requirement (120%)', $mapping->getMappingDescription());
    }

    public function testCalculateTransitiveFulfillment(): void
    {
        $framework = new ComplianceFramework();
        $framework->setName('ISO 27001');

        $sourceReq = new ComplianceRequirement();
        $sourceReq->setRequirementId('ISO-A.5.1')
                  ->setFramework($framework);

        $targetReq = new ComplianceRequirement();
        $targetReq->setRequirementId('GDPR-Art-32')
                  ->setFramework($framework);

        // NOTE: Fulfillment tracking moved to ComplianceRequirementFulfillment entity (CRITICAL-03)
        // TODO: Rewrite this test to use ComplianceRequirementFulfillment entity
        // For now, test the mapping itself without fulfillment calculation

        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($sourceReq);
        $mapping->setTargetRequirement($targetReq);
        $mapping->setMappingPercentage(75); // 75% mapping strength

        // Test that mapping is set correctly
        $this->assertEquals($sourceReq, $mapping->getSourceRequirement());
        $this->assertEquals($targetReq, $mapping->getTargetRequirement());
        $this->assertEquals(75, $mapping->getMappingPercentage());
    }

    public function testCalculateTransitiveFulfillmentWithFullMapping(): void
    {
        $framework = new ComplianceFramework();
        $framework->setName('ISO 27001');

        $sourceReq = new ComplianceRequirement();
        $sourceReq->setRequirementId('ISO-A.5.1')
                  ->setFramework($framework);

        $targetReq = new ComplianceRequirement();
        $targetReq->setRequirementId('GDPR-Art-32')
                  ->setFramework($framework);

        // NOTE: Fulfillment tracking moved to ComplianceRequirementFulfillment entity (CRITICAL-03)
        // TODO: Rewrite this test to use ComplianceRequirementFulfillment entity

        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($sourceReq);
        $mapping->setTargetRequirement($targetReq);
        $mapping->setMappingPercentage(100);

        // Test that full mapping is set correctly
        $this->assertEquals($sourceReq, $mapping->getSourceRequirement());
        $this->assertEquals($targetReq, $mapping->getTargetRequirement());
        $this->assertEquals(100, $mapping->getMappingPercentage());
    }
}
