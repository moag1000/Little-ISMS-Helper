<?php

namespace App\Tests\Entity;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use PHPUnit\Framework\TestCase;

class ComplianceFrameworkTest extends TestCase
{
    public function testNewFrameworkHasDefaultValues(): void
    {
        $framework = new ComplianceFramework();

        $this->assertNull($framework->getId());
        $this->assertNull($framework->getName());
        $this->assertNull($framework->getCode());
        $this->assertNull($framework->getDescription());
        $this->assertNull($framework->getVersion());
        $this->assertInstanceOf(\DateTimeImmutable::class, $framework->getCreatedAt());
        $this->assertNull($framework->getUpdatedAt());
        $this->assertCount(0, $framework->getRequirements());
    }

    public function testSetAndGetName(): void
    {
        $framework = new ComplianceFramework();
        $framework->setName('ISO 27001:2022');

        $this->assertEquals('ISO 27001:2022', $framework->getName());
    }

    public function testSetAndGetCode(): void
    {
        $framework = new ComplianceFramework();
        $framework->setCode('ISO27001');

        $this->assertEquals('ISO27001', $framework->getCode());
    }

    public function testSetAndGetDescription(): void
    {
        $framework = new ComplianceFramework();
        $framework->setDescription('Information security management system standard');

        $this->assertEquals('Information security management system standard', $framework->getDescription());
    }

    public function testSetAndGetVersion(): void
    {
        $framework = new ComplianceFramework();
        $framework->setVersion('2022');

        $this->assertEquals('2022', $framework->getVersion());
    }

    public function testSetAndGetIndustry(): void
    {
        $framework = new ComplianceFramework();
        $framework->setIndustry('General');

        $this->assertEquals('General', $framework->getIndustry());
    }

    public function testSetAndGetRegulatoryBody(): void
    {
        $framework = new ComplianceFramework();
        $framework->setRegulatoryBody('ISO/IEC');

        $this->assertEquals('ISO/IEC', $framework->getRegulatoryBody());
    }

    public function testSetAndGetMandatory(): void
    {
        $framework = new ComplianceFramework();

        $framework->setMandatory(true);
        $this->assertTrue($framework->isMandatory());

        $framework->setMandatory(false);
        $this->assertFalse($framework->isMandatory());
    }

    public function testAddAndRemoveRequirement(): void
    {
        $framework = new ComplianceFramework();
        $requirement = new ComplianceRequirement();
        $requirement->setTitle('A.5.1 Policies for information security');

        $this->assertCount(0, $framework->getRequirements());

        $framework->addRequirement($requirement);
        $this->assertCount(1, $framework->getRequirements());
        $this->assertTrue($framework->getRequirements()->contains($requirement));
        $this->assertSame($framework, $requirement->getFramework());

        $framework->removeRequirement($requirement);
        $this->assertCount(0, $framework->getRequirements());
        $this->assertFalse($framework->getRequirements()->contains($requirement));
    }

    public function testAddRequirementDoesNotDuplicate(): void
    {
        $framework = new ComplianceFramework();
        $requirement = new ComplianceRequirement();
        $requirement->setTitle('A.5.1 Policies for information security');

        $framework->addRequirement($requirement);
        $framework->addRequirement($requirement); // Add same requirement again

        $this->assertCount(1, $framework->getRequirements());
    }

    public function testToString(): void
    {
        $framework = new ComplianceFramework();
        $framework->setName('ISO 27001:2022');

        $this->assertEquals('ISO 27001:2022', (string) $framework);
    }

    public function testToStringWhenNameIsNull(): void
    {
        $framework = new ComplianceFramework();

        $this->assertEquals('', (string) $framework);
    }
}
