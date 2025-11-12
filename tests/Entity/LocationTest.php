<?php

namespace App\Tests\Entity;

use App\Entity\Location;
use PHPUnit\Framework\TestCase;

class LocationTest extends TestCase
{
    public function testNewLocationHasDefaultValues(): void
    {
        $location = new Location();

        $this->assertNull($location->getId());
        $this->assertNull($location->getName());
        $this->assertEquals('room', $location->getLocationType());
        $this->assertNull($location->getCode());
        $this->assertNull($location->getDescription());
        $this->assertNull($location->getAddress());
        $this->assertNull($location->getCity());
        $this->assertNull($location->getCountry());
        $this->assertNull($location->getPostalCode());
        $this->assertNull($location->getParentLocation());
        $this->assertCount(0, $location->getChildLocations());
        $this->assertEquals('public', $location->getSecurityLevel());
        $this->assertFalse($location->requiresBadgeAccess());
        $this->assertFalse($location->requiresEscort());
        $this->assertFalse($location->isCameraMonitored());
        $this->assertNull($location->getAccessControlSystem());
        $this->assertNull($location->getResponsiblePerson());
        $this->assertNull($location->getCapacity());
        $this->assertNull($location->getSquareMeters());
        $this->assertTrue($location->isActive());
        $this->assertNull($location->getNotes());
        $this->assertCount(0, $location->getAccessLogs());
        $this->assertCount(0, $location->getAssets());
        $this->assertNull($location->getTenant());
        $this->assertInstanceOf(\DateTime::class, $location->getCreatedAt());
        $this->assertNull($location->getUpdatedAt());
    }

    public function testSetAndGetName(): void
    {
        $location = new Location();
        $location->setName('Server Room A');

        $this->assertEquals('Server Room A', $location->getName());
    }

    public function testSetAndGetLocationType(): void
    {
        $location = new Location();

        $location->setLocationType('datacenter');
        $this->assertEquals('datacenter', $location->getLocationType());

        $location->setLocationType('building');
        $this->assertEquals('building', $location->getLocationType());
    }

    public function testSetAndGetCode(): void
    {
        $location = new Location();
        $location->setCode('DC-01-SR-A');

        $this->assertEquals('DC-01-SR-A', $location->getCode());
    }

    public function testSetAndGetDescription(): void
    {
        $location = new Location();
        $location->setDescription('Primary server room with climate control');

        $this->assertEquals('Primary server room with climate control', $location->getDescription());
    }

    public function testSetAndGetAddress(): void
    {
        $location = new Location();
        $location->setAddress('Musterstrasse 123');
        $location->setCity('Berlin');
        $location->setCountry('Germany');
        $location->setPostalCode('12345');

        $this->assertEquals('Musterstrasse 123', $location->getAddress());
        $this->assertEquals('Berlin', $location->getCity());
        $this->assertEquals('Germany', $location->getCountry());
        $this->assertEquals('12345', $location->getPostalCode());
    }

    public function testSetAndGetParentLocation(): void
    {
        $building = new Location();
        $building->setName('Building A');

        $floor = new Location();
        $floor->setName('Floor 2');
        $floor->setParentLocation($building);

        $this->assertSame($building, $floor->getParentLocation());
    }

    public function testAddAndRemoveChildLocation(): void
    {
        $building = new Location();
        $building->setName('Building A');

        $floor = new Location();
        $floor->setName('Floor 2');

        $this->assertCount(0, $building->getChildLocations());

        $building->addChildLocation($floor);
        $this->assertCount(1, $building->getChildLocations());
        $this->assertTrue($building->getChildLocations()->contains($floor));
        $this->assertSame($building, $floor->getParentLocation());

        $building->removeChildLocation($floor);
        $this->assertCount(0, $building->getChildLocations());
    }

    public function testAddChildLocationDoesNotDuplicate(): void
    {
        $building = new Location();
        $floor = new Location();

        $building->addChildLocation($floor);
        $building->addChildLocation($floor);

        $this->assertCount(1, $building->getChildLocations());
    }

    public function testSetAndGetSecurityLevel(): void
    {
        $location = new Location();

        $location->setSecurityLevel('secure');
        $this->assertEquals('secure', $location->getSecurityLevel());

        $location->setSecurityLevel('high_security');
        $this->assertEquals('high_security', $location->getSecurityLevel());
    }

    public function testSetAndGetSecurityFeatures(): void
    {
        $location = new Location();

        $location->setRequiresBadgeAccess(true);
        $this->assertTrue($location->requiresBadgeAccess());

        $location->setRequiresEscort(true);
        $this->assertTrue($location->requiresEscort());

        $location->setCameraMonitored(true);
        $this->assertTrue($location->isCameraMonitored());
    }

    public function testSetAndGetAccessControlSystem(): void
    {
        $location = new Location();
        $location->setAccessControlSystem('Biometric + Badge System');

        $this->assertEquals('Biometric + Badge System', $location->getAccessControlSystem());
    }

    public function testSetAndGetResponsiblePerson(): void
    {
        $location = new Location();
        $location->setResponsiblePerson('Facility Manager');

        $this->assertEquals('Facility Manager', $location->getResponsiblePerson());
    }

    public function testSetAndGetCapacity(): void
    {
        $location = new Location();
        $location->setCapacity(50);

        $this->assertEquals(50, $location->getCapacity());
    }

    public function testSetAndGetSquareMeters(): void
    {
        $location = new Location();
        $location->setSquareMeters('250.50');

        $this->assertEquals('250.50', $location->getSquareMeters());
    }

    public function testSetAndGetActive(): void
    {
        $location = new Location();

        $this->assertTrue($location->isActive());

        $location->setActive(false);
        $this->assertFalse($location->isActive());
    }

    public function testSetAndGetNotes(): void
    {
        $location = new Location();
        $location->setNotes('24/7 monitoring, restricted access after 18:00');

        $this->assertEquals('24/7 monitoring, restricted access after 18:00', $location->getNotes());
    }

    public function testGetFullPathWithNoParent(): void
    {
        $location = new Location();
        $location->setName('Building A');

        $this->assertEquals('Building A', $location->getFullPath());
    }

    public function testGetFullPathWithParent(): void
    {
        $building = new Location();
        $building->setName('Building A');

        $floor = new Location();
        $floor->setName('Floor 2');
        $floor->setParentLocation($building);

        $this->assertEquals('Building A > Floor 2', $floor->getFullPath());
    }

    public function testGetFullPathWithMultipleLevels(): void
    {
        $building = new Location();
        $building->setName('Building A');

        $floor = new Location();
        $floor->setName('Floor 2');
        $floor->setParentLocation($building);

        $room = new Location();
        $room->setName('Room 201');
        $room->setParentLocation($floor);

        $this->assertEquals('Building A > Floor 2 > Room 201', $room->getFullPath());
    }

    public function testGetDisplayNameWithoutCode(): void
    {
        $location = new Location();
        $location->setName('Server Room');

        $this->assertEquals('Server Room', $location->getDisplayName());
    }

    public function testGetDisplayNameWithCode(): void
    {
        $location = new Location();
        $location->setName('Server Room');
        $location->setCode('SR-01');

        $this->assertEquals('Server Room [SR-01]', $location->getDisplayName());
    }

    public function testIsHighSecurityReturnsTrueForSecureLevel(): void
    {
        $location = new Location();
        $location->setSecurityLevel('secure');

        $this->assertTrue($location->isHighSecurity());
    }

    public function testIsHighSecurityReturnsTrueForHighSecurityLevel(): void
    {
        $location = new Location();
        $location->setSecurityLevel('high_security');

        $this->assertTrue($location->isHighSecurity());
    }

    public function testIsHighSecurityReturnsFalseForLowerLevels(): void
    {
        $location = new Location();

        $location->setSecurityLevel('public');
        $this->assertFalse($location->isHighSecurity());

        $location->setSecurityLevel('restricted');
        $this->assertFalse($location->isHighSecurity());

        $location->setSecurityLevel('controlled');
        $this->assertFalse($location->isHighSecurity());
    }

    public function testLocationHierarchyCanBeBuilt(): void
    {
        // Create a complete hierarchy
        $building = new Location();
        $building->setName('Headquarters');
        $building->setLocationType('building');

        $floor1 = new Location();
        $floor1->setName('Floor 1');
        $floor1->setLocationType('floor');

        $floor2 = new Location();
        $floor2->setName('Floor 2');
        $floor2->setLocationType('floor');

        $serverRoom = new Location();
        $serverRoom->setName('Server Room');
        $serverRoom->setLocationType('server_room');
        $serverRoom->setSecurityLevel('high_security');
        $serverRoom->setRequiresBadgeAccess(true);
        $serverRoom->setCameraMonitored(true);

        $office = new Location();
        $office->setName('Office 201');
        $office->setLocationType('office');

        // Build hierarchy
        $building->addChildLocation($floor1);
        $building->addChildLocation($floor2);
        $floor2->addChildLocation($serverRoom);
        $floor2->addChildLocation($office);

        // Verify structure
        $this->assertCount(2, $building->getChildLocations());
        $this->assertCount(2, $floor2->getChildLocations());
        $this->assertEquals('Headquarters > Floor 2 > Server Room', $serverRoom->getFullPath());
        $this->assertTrue($serverRoom->isHighSecurity());
        $this->assertFalse($office->isHighSecurity());
    }

    public function testLocationCanStoreCompleteDatacenterInformation(): void
    {
        $datacenter = new Location();
        $datacenter->setName('Primary Datacenter');
        $datacenter->setLocationType('datacenter');
        $datacenter->setCode('DC-01');
        $datacenter->setAddress('Industriestrasse 100');
        $datacenter->setCity('Frankfurt');
        $datacenter->setCountry('Germany');
        $datacenter->setPostalCode('60486');
        $datacenter->setSecurityLevel('high_security');
        $datacenter->setRequiresBadgeAccess(true);
        $datacenter->setRequiresEscort(true);
        $datacenter->setCameraMonitored(true);
        $datacenter->setAccessControlSystem('Biometric + Multi-Factor Badge System');
        $datacenter->setResponsiblePerson('Datacenter Operations Manager');
        $datacenter->setSquareMeters('5000.00');

        $this->assertEquals('Primary Datacenter', $datacenter->getName());
        $this->assertEquals('Primary Datacenter [DC-01]', $datacenter->getDisplayName());
        $this->assertEquals('Frankfurt', $datacenter->getCity());
        $this->assertTrue($datacenter->isHighSecurity());
        $this->assertTrue($datacenter->requiresBadgeAccess());
        $this->assertTrue($datacenter->requiresEscort());
        $this->assertTrue($datacenter->isCameraMonitored());
    }
}
