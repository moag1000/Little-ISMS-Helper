<?php

namespace App\Tests\Entity;

use App\Entity\Location;
use App\Entity\Person;
use App\Entity\PhysicalAccessLog;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class PhysicalAccessLogTest extends TestCase
{
    public function testConstructor(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNotNull($log->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $log->getCreatedAt());
        $this->assertNotNull($log->getAccessTime());
        $this->assertInstanceOf(\DateTimeInterface::class, $log->getAccessTime());
        $this->assertEquals('entry', $log->getAccessType());
        $this->assertEquals('badge', $log->getAuthenticationMethod());
        $this->assertTrue($log->isAuthorized());
        $this->assertFalse($log->isAfterHours());
    }

    public function testPersonRelationship(): void
    {
        $log = new PhysicalAccessLog();
        $person = new Person();

        $this->assertNull($log->getPerson());

        $log->setPerson($person);
        $this->assertSame($person, $log->getPerson());

        $log->setPerson(null);
        $this->assertNull($log->getPerson());
    }

    public function testLocationEntityRelationship(): void
    {
        $log = new PhysicalAccessLog();
        $location = new Location();

        $this->assertNull($log->getLocationEntity());

        $log->setLocationEntity($location);
        $this->assertSame($location, $log->getLocationEntity());

        $log->setLocationEntity(null);
        $this->assertNull($log->getLocationEntity());
    }

    public function testPersonName(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNull($log->getPersonName());

        $log->setPersonName('John Doe');
        $this->assertEquals('John Doe', $log->getPersonName());
    }

    public function testBadgeId(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNull($log->getBadgeId());

        $log->setBadgeId('BADGE-12345');
        $this->assertEquals('BADGE-12345', $log->getBadgeId());

        $log->setBadgeId(null);
        $this->assertNull($log->getBadgeId());
    }

    public function testLocation(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNull($log->getLocation());

        $log->setLocation('Server Room');
        $this->assertEquals('Server Room', $log->getLocation());
    }

    public function testAccessType(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertEquals('entry', $log->getAccessType());

        $log->setAccessType('exit');
        $this->assertEquals('exit', $log->getAccessType());

        $log->setAccessType('denied');
        $this->assertEquals('denied', $log->getAccessType());

        $log->setAccessType('forced_entry');
        $this->assertEquals('forced_entry', $log->getAccessType());

        $log->setAccessType('entry');
        $this->assertEquals('entry', $log->getAccessType());
    }

    public function testAccessTime(): void
    {
        $log = new PhysicalAccessLog();

        // Constructor sets accessTime
        $this->assertNotNull($log->getAccessTime());

        $newTime = new \DateTime('2024-06-15 14:30:00');
        $log->setAccessTime($newTime);
        $this->assertEquals($newTime, $log->getAccessTime());
    }

    public function testAuthenticationMethod(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertEquals('badge', $log->getAuthenticationMethod());

        $log->setAuthenticationMethod('biometric');
        $this->assertEquals('biometric', $log->getAuthenticationMethod());

        $log->setAuthenticationMethod('pin');
        $this->assertEquals('pin', $log->getAuthenticationMethod());

        $log->setAuthenticationMethod('key');
        $this->assertEquals('key', $log->getAuthenticationMethod());

        $log->setAuthenticationMethod('escort');
        $this->assertEquals('escort', $log->getAuthenticationMethod());

        $log->setAuthenticationMethod('override');
        $this->assertEquals('override', $log->getAuthenticationMethod());

        $log->setAuthenticationMethod('other');
        $this->assertEquals('other', $log->getAuthenticationMethod());
    }

    public function testPurpose(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNull($log->getPurpose());

        $log->setPurpose('Maintenance');
        $this->assertEquals('Maintenance', $log->getPurpose());

        $log->setPurpose(null);
        $this->assertNull($log->getPurpose());
    }

    public function testEscortedBy(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNull($log->getEscortedBy());

        $log->setEscortedBy('Jane Smith');
        $this->assertEquals('Jane Smith', $log->getEscortedBy());

        $log->setEscortedBy(null);
        $this->assertNull($log->getEscortedBy());
    }

    public function testCompany(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNull($log->getCompany());

        $log->setCompany('Acme Corp');
        $this->assertEquals('Acme Corp', $log->getCompany());

        $log->setCompany(null);
        $this->assertNull($log->getCompany());
    }

    public function testIsAuthorized(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertTrue($log->isAuthorized());

        $log->setAuthorized(false);
        $this->assertFalse($log->isAuthorized());

        $log->setAuthorized(true);
        $this->assertTrue($log->isAuthorized());
    }

    public function testNotes(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNull($log->getNotes());

        $log->setNotes('Required extra verification');
        $this->assertEquals('Required extra verification', $log->getNotes());

        $log->setNotes(null);
        $this->assertNull($log->getNotes());
    }

    public function testAlertLevel(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNull($log->getAlertLevel());

        $log->setAlertLevel('high');
        $this->assertEquals('high', $log->getAlertLevel());

        $log->setAlertLevel(null);
        $this->assertNull($log->getAlertLevel());
    }

    public function testIsAfterHours(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertFalse($log->isAfterHours());

        $log->setAfterHours(true);
        $this->assertTrue($log->isAfterHours());

        $log->setAfterHours(false);
        $this->assertFalse($log->isAfterHours());
    }

    public function testDoorOrGate(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNull($log->getDoorOrGate());

        $log->setDoorOrGate('Main Entrance');
        $this->assertEquals('Main Entrance', $log->getDoorOrGate());

        $log->setDoorOrGate(null);
        $this->assertNull($log->getDoorOrGate());
    }

    public function testExitTime(): void
    {
        $log = new PhysicalAccessLog();

        $this->assertNull($log->getExitTime());

        $exitTime = new \DateTime('2024-06-15 18:00:00');
        $log->setExitTime($exitTime);
        $this->assertEquals($exitTime, $log->getExitTime());

        $log->setExitTime(null);
        $this->assertNull($log->getExitTime());
    }

    public function testUserRelationship(): void
    {
        $log = new PhysicalAccessLog();
        $user = new User();

        $this->assertNull($log->getUser());

        $log->setUser($user);
        $this->assertSame($user, $log->getUser());

        $log->setUser(null);
        $this->assertNull($log->getUser());
    }

    public function testTenantRelationship(): void
    {
        $log = new PhysicalAccessLog();
        $tenant = new Tenant();

        $this->assertNull($log->getTenant());

        $log->setTenant($tenant);
        $this->assertSame($tenant, $log->getTenant());

        $log->setTenant(null);
        $this->assertNull($log->getTenant());
    }

    public function testCreatedAt(): void
    {
        $log = new PhysicalAccessLog();

        // Constructor sets createdAt
        $this->assertNotNull($log->getCreatedAt());

        $newDate = new \DateTime('2024-06-15 10:00:00');
        $log->setCreatedAt($newDate);
        $this->assertEquals($newDate, $log->getCreatedAt());
    }

    public function testSetPersonSyncsLegacyFields(): void
    {
        $log = new PhysicalAccessLog();
        $person = new Person();
        $person->setFullName('Jane Doe');
        $person->setBadgeId('BADGE-999');
        $person->setCompany('Tech Corp');

        $log->setPerson($person);

        $this->assertEquals('Jane Doe', $log->getPersonName());
        $this->assertEquals('BADGE-999', $log->getBadgeId());
        $this->assertEquals('Tech Corp', $log->getCompany());
    }

    public function testSetLocationEntitySyncsLegacyField(): void
    {
        $log = new PhysicalAccessLog();
        $location = new Location();
        $location->setName('Data Center');

        $log->setLocationEntity($location);

        $this->assertEquals('Data Center', $log->getLocation());
    }

    public function testGetEffectivePersonNameFromPersonEntity(): void
    {
        $log = new PhysicalAccessLog();
        $person = new Person();
        $person->setFullName('John Smith');

        $log->setPerson($person);

        $this->assertEquals('John Smith', $log->getEffectivePersonName());
    }

    public function testGetEffectivePersonNameFromLegacyField(): void
    {
        $log = new PhysicalAccessLog();
        $log->setPersonName('Legacy Name');

        $this->assertEquals('Legacy Name', $log->getEffectivePersonName());
    }

    public function testGetEffectivePersonNamePrefersPersonEntity(): void
    {
        $log = new PhysicalAccessLog();
        $person = new Person();
        $person->setFullName('Entity Name');

        $log->setPerson($person);
        $log->setPersonName('Legacy Name');

        $this->assertEquals('Entity Name', $log->getEffectivePersonName());
    }

    public function testGetEffectiveLocationFromLocationEntity(): void
    {
        $log = new PhysicalAccessLog();
        $location = new Location();
        $location->setName('Office 1');

        $log->setLocationEntity($location);

        $this->assertEquals('Office 1', $log->getEffectiveLocation());
    }

    public function testGetEffectiveLocationFromLegacyField(): void
    {
        $log = new PhysicalAccessLog();
        $log->setLocation('Legacy Location');

        $this->assertEquals('Legacy Location', $log->getEffectiveLocation());
    }

    public function testGetEffectiveLocationPrefersLocationEntity(): void
    {
        $log = new PhysicalAccessLog();
        $location = new Location();
        $location->setName('Entity Location');

        $log->setLocationEntity($location);
        $log->setLocation('Legacy Location');

        $this->assertEquals('Entity Location', $log->getEffectiveLocation());
    }

    public function testFluentSetters(): void
    {
        $log = new PhysicalAccessLog();
        $person = new Person();
        $location = new Location();
        $user = new User();
        $tenant = new Tenant();

        $result = $log
            ->setPerson($person)
            ->setLocationEntity($location)
            ->setAccessType('entry')
            ->setAuthenticationMethod('biometric')
            ->setAuthorized(true)
            ->setAfterHours(false)
            ->setUser($user)
            ->setTenant($tenant);

        $this->assertSame($log, $result);
        $this->assertSame($person, $log->getPerson());
        $this->assertSame($location, $log->getLocationEntity());
        $this->assertEquals('entry', $log->getAccessType());
        $this->assertEquals('biometric', $log->getAuthenticationMethod());
        $this->assertTrue($log->isAuthorized());
        $this->assertFalse($log->isAfterHours());
        $this->assertSame($user, $log->getUser());
        $this->assertSame($tenant, $log->getTenant());
    }
}
