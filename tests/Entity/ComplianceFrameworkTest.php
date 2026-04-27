<?php

namespace App\Tests\Entity;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ComplianceFrameworkTest extends TestCase
{
    #[Test]
    public function testNewFrameworkHasDefaultValues(): void
    {
        $framework = new ComplianceFramework();

        $this->assertNull($framework->id);
        $this->assertNull($framework->getName());
        $this->assertNull($framework->getCode());
        $this->assertNull($framework->getDescription());
        $this->assertNull($framework->getVersion());
        $this->assertInstanceOf(\DateTimeImmutable::class, $framework->getCreatedAt());
        $this->assertNull($framework->getUpdatedAt());
        $this->assertCount(0, $framework->requirements);
    }

    #[Test]
    public function testSetAndGetName(): void
    {
        $framework = new ComplianceFramework();
        $framework->setName('ISO 27001:2022');

        $this->assertEquals('ISO 27001:2022', $framework->getName());
    }

    #[Test]
    public function testSetAndGetCode(): void
    {
        $framework = new ComplianceFramework();
        $framework->setCode('ISO27001');

        $this->assertEquals('ISO27001', $framework->getCode());
    }

    #[Test]
    public function testSetAndGetDescription(): void
    {
        $framework = new ComplianceFramework();
        $framework->setDescription('Information security management system standard');

        $this->assertEquals('Information security management system standard', $framework->getDescription());
    }

    #[Test]
    public function testSetAndGetVersion(): void
    {
        $framework = new ComplianceFramework();
        $framework->setVersion('2022');

        $this->assertEquals('2022', $framework->getVersion());
    }

    #[Test]
    public function testSetAndGetApplicableIndustry(): void
    {
        $framework = new ComplianceFramework();
        $framework->setApplicableIndustry('General');

        $this->assertEquals('General', $framework->getApplicableIndustry());
    }

    #[Test]
    public function testSetAndGetRegulatoryBody(): void
    {
        $framework = new ComplianceFramework();
        $framework->setRegulatoryBody('ISO/IEC');

        $this->assertEquals('ISO/IEC', $framework->getRegulatoryBody());
    }

    #[Test]
    public function testSetAndGetMandatory(): void
    {
        $framework = new ComplianceFramework();

        $framework->setMandatory(true);
        $this->assertTrue($framework->isMandatory());

        $framework->setMandatory(false);
        $this->assertFalse($framework->isMandatory());
    }

    #[Test]
    public function testAddAndRemoveRequirement(): void
    {
        $framework = new ComplianceFramework();
        $requirement = new ComplianceRequirement();
        $requirement->setTitle('A.5.1 Policies for information security');

        $this->assertCount(0, $framework->requirements);

        $framework->addRequirement($requirement);
        $this->assertCount(1, $framework->requirements);
        $this->assertTrue($framework->requirements->contains($requirement));
        $this->assertSame($framework, $requirement->getFramework());

        $framework->removeRequirement($requirement);
        $this->assertCount(0, $framework->requirements);
        $this->assertFalse($framework->requirements->contains($requirement));
    }

    #[Test]
    public function testAddRequirementDoesNotDuplicate(): void
    {
        $framework = new ComplianceFramework();
        $requirement = new ComplianceRequirement();
        $requirement->setTitle('A.5.1 Policies for information security');

        $framework->addRequirement($requirement);
        $framework->addRequirement($requirement); // Add same requirement again

        $this->assertCount(1, $framework->requirements);
    }
}
