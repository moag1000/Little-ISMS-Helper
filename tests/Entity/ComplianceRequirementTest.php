<?php

namespace App\Tests\Entity;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use PHPUnit\Framework\TestCase;

class ComplianceRequirementTest extends TestCase
{
    public function testNewRequirementHasDefaultValues(): void
    {
        $requirement = new ComplianceRequirement();

        $this->assertNull($requirement->getId());
        $this->assertNull($requirement->getFramework());
        $this->assertNull($requirement->getRequirementId());
        $this->assertNull($requirement->getTitle());
        $this->assertNull($requirement->getDescription());
        $this->assertNull($requirement->getCategory());
        $this->assertNull($requirement->getPriority());
        $this->assertEquals(0, $requirement->getFulfillmentPercentage());
        $this->assertInstanceOf(\DateTimeImmutable::class, $requirement->getCreatedAt());
        $this->assertNull($requirement->getUpdatedAt());
        $this->assertCount(0, $requirement->getMappedControls());
        $this->assertCount(0, $requirement->getTrainings());
    }

    public function testSetAndGetFramework(): void
    {
        $requirement = new ComplianceRequirement();
        $framework = new ComplianceFramework();
        $framework->setName('ISO 27001:2022');

        $requirement->setFramework($framework);

        $this->assertSame($framework, $requirement->getFramework());
    }

    public function testSetAndGetRequirementId(): void
    {
        $requirement = new ComplianceRequirement();
        $requirement->setRequirementId('A.5.1');

        $this->assertEquals('A.5.1', $requirement->getRequirementId());
    }

    public function testSetAndGetTitle(): void
    {
        $requirement = new ComplianceRequirement();
        $requirement->setTitle('Policies for information security');

        $this->assertEquals('Policies for information security', $requirement->getTitle());
    }

    public function testSetAndGetDescription(): void
    {
        $requirement = new ComplianceRequirement();
        $requirement->setDescription('Information security policy shall be defined');

        $this->assertEquals('Information security policy shall be defined', $requirement->getDescription());
    }

    public function testSetAndGetCategory(): void
    {
        $requirement = new ComplianceRequirement();
        $requirement->setCategory('Organizational controls');

        $this->assertEquals('Organizational controls', $requirement->getCategory());
    }

    public function testSetAndGetPriority(): void
    {
        $requirement = new ComplianceRequirement();
        $requirement->setPriority('high');

        $this->assertEquals('high', $requirement->getPriority());
    }

    public function testSetAndGetFulfillmentPercentage(): void
    {
        $requirement = new ComplianceRequirement();
        $requirement->setFulfillmentPercentage(75);

        $this->assertEquals(75, $requirement->getFulfillmentPercentage());
    }

    public function testSetAndGetFulfillmentNotes(): void
    {
        $requirement = new ComplianceRequirement();
        $requirement->setFulfillmentNotes('Partially implemented, needs review');

        $this->assertEquals('Partially implemented, needs review', $requirement->getFulfillmentNotes());
    }

    public function testAddAndRemoveMappedControl(): void
    {
        $requirement = new ComplianceRequirement();
        $control = new Control();
        $control->setTitle('Access Control Policy');

        $this->assertCount(0, $requirement->getMappedControls());

        $requirement->addMappedControl($control);
        $this->assertCount(1, $requirement->getMappedControls());
        $this->assertTrue($requirement->getMappedControls()->contains($control));

        $requirement->removeMappedControl($control);
        $this->assertCount(0, $requirement->getMappedControls());
        $this->assertFalse($requirement->getMappedControls()->contains($control));
    }

    public function testAddMappedControlDoesNotDuplicate(): void
    {
        $requirement = new ComplianceRequirement();
        $control = new Control();
        $control->setTitle('Access Control Policy');

        $requirement->addMappedControl($control);
        $requirement->addMappedControl($control); // Add same control again

        $this->assertCount(1, $requirement->getMappedControls());
    }

    public function testSetUpdatedAt(): void
    {
        $requirement = new ComplianceRequirement();
        $now = new \DateTimeImmutable();

        $requirement->setUpdatedAt($now);

        $this->assertEquals($now, $requirement->getUpdatedAt());
    }
}
