<?php

namespace App\Tests\Entity;

use App\Entity\RiskAppetite;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class RiskAppetiteTest extends TestCase
{
    public function testConstructor(): void
    {
        $appetite = new RiskAppetite();

        $this->assertNotNull($appetite->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $appetite->getCreatedAt());
        $this->assertTrue($appetite->isActive()); // Default
    }

    public function testGettersAndSetters(): void
    {
        $appetite = new RiskAppetite();

        $appetite->setCategory('Financial');
        $this->assertEquals('Financial', $appetite->getCategory());

        $appetite->setMaxAcceptableRisk(12);
        $this->assertEquals(12, $appetite->getMaxAcceptableRisk());

        $appetite->setDescription('Maximum acceptable financial risk level');
        $this->assertEquals('Maximum acceptable financial risk level', $appetite->getDescription());
    }

    public function testTenantRelationship(): void
    {
        $appetite = new RiskAppetite();
        $tenant = new Tenant();

        $this->assertNull($appetite->getTenant());

        $appetite->setTenant($tenant);
        $this->assertSame($tenant, $appetite->getTenant());
    }

    public function testIsActive(): void
    {
        $appetite = new RiskAppetite();

        $this->assertTrue($appetite->isActive()); // Default

        $appetite->setIsActive(false);
        $this->assertFalse($appetite->isActive());
    }

    public function testApprovalFields(): void
    {
        $appetite = new RiskAppetite();
        $user = new User();
        $approvedAt = new \DateTime('2024-01-15');

        $this->assertNull($appetite->getApprovedBy());
        $this->assertNull($appetite->getApprovedAt());

        $appetite->setApprovedBy($user);
        $this->assertSame($user, $appetite->getApprovedBy());

        $appetite->setApprovedAt($approvedAt);
        $this->assertEquals($approvedAt, $appetite->getApprovedAt());
    }

    public function testTimestamps(): void
    {
        $appetite = new RiskAppetite();

        // createdAt set in constructor
        $this->assertNotNull($appetite->getCreatedAt());

        // updatedAt initially null
        $this->assertNull($appetite->getUpdatedAt());

        $now = new \DateTime();
        $appetite->setUpdatedAt($now);
        $this->assertEquals($now, $appetite->getUpdatedAt());
    }

    public function testIsGlobal(): void
    {
        $appetite = new RiskAppetite();

        // No category = global
        $this->assertTrue($appetite->isGlobal());

        $appetite->setCategory('Operational');
        $this->assertFalse($appetite->isGlobal());

        $appetite->setCategory(null);
        $this->assertTrue($appetite->isGlobal());
    }

    public function testGetDisplayName(): void
    {
        $appetite = new RiskAppetite();

        // Global appetite
        $this->assertEquals('Global Risk Appetite', $appetite->getDisplayName());

        // Category-specific appetite
        $appetite->setCategory('Compliance');
        $this->assertEquals('Compliance Risk Appetite', $appetite->getDisplayName());
    }

    public function testGetRiskLevelClassification(): void
    {
        $appetite = new RiskAppetite();
        $appetite->setMaxAcceptableRisk(10);

        // Risk within appetite
        $this->assertEquals('acceptable', $appetite->getRiskLevelClassification(8));
        $this->assertEquals('acceptable', $appetite->getRiskLevelClassification(10));

        // Risk slightly over appetite (but within 1.5x)
        $this->assertEquals('review_required', $appetite->getRiskLevelClassification(12));
        $this->assertEquals('review_required', $appetite->getRiskLevelClassification(15));

        // Risk exceeding appetite significantly
        $this->assertEquals('exceeds_appetite', $appetite->getRiskLevelClassification(16));
        $this->assertEquals('exceeds_appetite', $appetite->getRiskLevelClassification(25));
    }

    public function testIsRiskAcceptable(): void
    {
        $appetite = new RiskAppetite();
        $appetite->setMaxAcceptableRisk(12);

        $this->assertTrue($appetite->isRiskAcceptable(10));
        $this->assertTrue($appetite->isRiskAcceptable(12));
        $this->assertFalse($appetite->isRiskAcceptable(13));
        $this->assertFalse($appetite->isRiskAcceptable(20));
    }

    public function testGetAppetitePercentage(): void
    {
        $appetite = new RiskAppetite();
        $appetite->setMaxAcceptableRisk(10);

        $this->assertEquals(50.0, $appetite->getAppetitePercentage(5));
        $this->assertEquals(100.0, $appetite->getAppetitePercentage(10));
        $this->assertEquals(150.0, $appetite->getAppetitePercentage(15));
    }

    public function testGetAppetitePercentageWithZeroMax(): void
    {
        $appetite = new RiskAppetite();
        $appetite->setMaxAcceptableRisk(0);

        $this->assertEquals(0.0, $appetite->getAppetitePercentage(5));
    }

    public function testIsApprovedWhenNotApproved(): void
    {
        $appetite = new RiskAppetite();

        $this->assertFalse($appetite->isApproved());
    }

    public function testIsApprovedWithOnlyApprover(): void
    {
        $appetite = new RiskAppetite();
        $user = new User();

        $appetite->setApprovedBy($user);

        // Not approved without approval date
        $this->assertFalse($appetite->isApproved());
    }

    public function testIsApprovedWithOnlyDate(): void
    {
        $appetite = new RiskAppetite();

        $appetite->setApprovedAt(new \DateTime());

        // Not approved without approver
        $this->assertFalse($appetite->isApproved());
    }

    public function testIsApprovedWithBothFields(): void
    {
        $appetite = new RiskAppetite();
        $user = new User();

        $appetite->setApprovedBy($user);
        $appetite->setApprovedAt(new \DateTime());

        // Fully approved
        $this->assertTrue($appetite->isApproved());
    }
}
