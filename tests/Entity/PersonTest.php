<?php

namespace App\Tests\Entity;

use App\Entity\Person;
use App\Entity\PhysicalAccessLog;
use App\Entity\Tenant;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class PersonTest extends TestCase
{
    public function testConstructor(): void
    {
        $person = new Person();

        $this->assertNotNull($person->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $person->getCreatedAt());
        $this->assertEquals(0, $person->getAccessLogs()->count());
        $this->assertEquals('visitor', $person->getPersonType());
        $this->assertTrue($person->isActive());
    }

    public function testBasicGettersAndSetters(): void
    {
        $person = new Person();

        $person->setFullName('John Doe');
        $this->assertEquals('John Doe', $person->getFullName());

        $person->setPersonType('employee');
        $this->assertEquals('employee', $person->getPersonType());

        $person->setBadgeId('BADGE123');
        $this->assertEquals('BADGE123', $person->getBadgeId());

        $person->setCompany('Acme Corp');
        $this->assertEquals('Acme Corp', $person->getCompany());

        $person->setEmail('john@acme.com');
        $this->assertEquals('john@acme.com', $person->getEmail());

        $person->setPhone('+49123456789');
        $this->assertEquals('+49123456789', $person->getPhone());

        $person->setDepartment('IT');
        $this->assertEquals('IT', $person->getDepartment());

        $person->setJobTitle('Developer');
        $this->assertEquals('Developer', $person->getJobTitle());

        $person->setNotes('Special access required');
        $this->assertEquals('Special access required', $person->getNotes());
    }

    public function testLinkedUser(): void
    {
        $person = new Person();
        $user = new User();

        $this->assertNull($person->getLinkedUser());

        $person->setLinkedUser($user);
        $this->assertSame($user, $person->getLinkedUser());
    }

    public function testIsActive(): void
    {
        $person = new Person();

        $this->assertTrue($person->isActive());

        $person->setActive(false);
        $this->assertFalse($person->isActive());
    }

    public function testAccessDates(): void
    {
        $person = new Person();

        $validFrom = new \DateTime('2024-01-01');
        $person->setAccessValidFrom($validFrom);
        $this->assertEquals($validFrom, $person->getAccessValidFrom());

        $validUntil = new \DateTime('2024-12-31');
        $person->setAccessValidUntil($validUntil);
        $this->assertEquals($validUntil, $person->getAccessValidUntil());
    }

    public function testAddAndRemoveAccessLog(): void
    {
        $person = new Person();
        $log = new PhysicalAccessLog();

        $this->assertEquals(0, $person->getAccessLogs()->count());

        $person->addAccessLog($log);
        $this->assertEquals(1, $person->getAccessLogs()->count());
        $this->assertTrue($person->getAccessLogs()->contains($log));

        $person->removeAccessLog($log);
        $this->assertEquals(0, $person->getAccessLogs()->count());
    }

    public function testTenantRelationship(): void
    {
        $person = new Person();
        $tenant = new Tenant();

        $this->assertNull($person->getTenant());

        $person->setTenant($tenant);
        $this->assertSame($tenant, $person->getTenant());
    }

    public function testTimestamps(): void
    {
        $person = new Person();

        // createdAt set in constructor
        $this->assertNotNull($person->getCreatedAt());

        // updatedAt initially null
        $this->assertNull($person->getUpdatedAt());

        $now = new \DateTime();
        $person->setUpdatedAt($now);
        $this->assertEquals($now, $person->getUpdatedAt());
    }

    public function testHasValidAccessWhenInactive(): void
    {
        $person = new Person();
        $person->setActive(false);

        $this->assertFalse($person->hasValidAccess());
    }

    public function testHasValidAccessWithFutureStartDate(): void
    {
        $person = new Person();
        $person->setActive(true);
        $futureDate = (new \DateTime())->modify('+5 days');
        $person->setAccessValidFrom($futureDate);

        $this->assertFalse($person->hasValidAccess());
    }

    public function testHasValidAccessWithPastEndDate(): void
    {
        $person = new Person();
        $person->setActive(true);
        $pastDate = (new \DateTime())->modify('-5 days');
        $person->setAccessValidUntil($pastDate);

        $this->assertFalse($person->hasValidAccess());
    }

    public function testHasValidAccessWhenValid(): void
    {
        $person = new Person();
        $person->setActive(true);
        $pastDate = (new \DateTime())->modify('-5 days');
        $futureDate = (new \DateTime())->modify('+5 days');
        $person->setAccessValidFrom($pastDate);
        $person->setAccessValidUntil($futureDate);

        $this->assertTrue($person->hasValidAccess());
    }

    public function testHasValidAccessWithNoDateRestrictions(): void
    {
        $person = new Person();
        $person->setActive(true);

        $this->assertTrue($person->hasValidAccess());
    }

    public function testGetDisplayNameWithoutCompany(): void
    {
        $person = new Person();
        $person->setFullName('Jane Smith');

        $this->assertEquals('Jane Smith', $person->getDisplayName());
    }

    public function testGetDisplayNameWithCompany(): void
    {
        $person = new Person();
        $person->setFullName('Jane Smith');
        $person->setCompany('Tech Corp');

        $this->assertEquals('Jane Smith (Tech Corp)', $person->getDisplayName());
    }
}
