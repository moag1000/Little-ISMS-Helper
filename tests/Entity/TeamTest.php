<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Person;
use App\Entity\Team;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TeamTest extends TestCase
{
    #[Test]
    public function testConstructorSetsCreatedAtAndEmptyMembers(): void
    {
        $team = new Team();

        $this->assertNotNull($team->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $team->getCreatedAt());
        $this->assertCount(0, $team->getMembers());
        $this->assertEquals(0, $team->getMemberCount());
    }

    #[Test]
    public function testNameGetterAndSetter(): void
    {
        $team = new Team();

        $this->assertNull($team->getName());

        $team->setName('ISMS Core Team');
        $this->assertEquals('ISMS Core Team', $team->getName());

        $team->setName(null);
        $this->assertNull($team->getName());
    }

    #[Test]
    public function testDescriptionGetterAndSetter(): void
    {
        $team = new Team();

        $this->assertNull($team->getDescription());

        $team->setDescription('Responsible for ISO 27001 implementation');
        $this->assertEquals('Responsible for ISO 27001 implementation', $team->getDescription());

        $team->setDescription(null);
        $this->assertNull($team->getDescription());
    }

    #[Test]
    public function testTypeGetterAndSetter(): void
    {
        $team = new Team();

        $this->assertNull($team->getType());

        $team->setType('operational');
        $this->assertEquals('operational', $team->getType());

        $team->setType('strategic');
        $this->assertEquals('strategic', $team->getType());

        $team->setType(null);
        $this->assertNull($team->getType());
    }

    #[Test]
    public function testIsActiveDefaultAndSetter(): void
    {
        $team = new Team();

        $this->assertTrue($team->isActive());

        $team->setIsActive(false);
        $this->assertFalse($team->isActive());

        $team->setIsActive(true);
        $this->assertTrue($team->isActive());
    }

    #[Test]
    public function testTeamLeadRelationship(): void
    {
        $team = new Team();
        $user = new User();
        $user->setEmail('lead@example.com');

        $this->assertNull($team->getTeamLead());

        $team->setTeamLead($user);
        $this->assertSame($user, $team->getTeamLead());

        $team->setTeamLead(null);
        $this->assertNull($team->getTeamLead());
    }

    #[Test]
    public function testTeamLeadPersonRelationship(): void
    {
        $team = new Team();
        $person = new Person();

        $this->assertNull($team->getTeamLeadPerson());

        $team->setTeamLeadPerson($person);
        $this->assertSame($person, $team->getTeamLeadPerson());

        $team->setTeamLeadPerson(null);
        $this->assertNull($team->getTeamLeadPerson());
    }

    #[Test]
    public function testAddMemberAndRemoveMember(): void
    {
        $team = new Team();
        $person1 = new Person();
        $person2 = new Person();

        $this->assertEquals(0, $team->getMemberCount());

        $team->addMember($person1);
        $this->assertEquals(1, $team->getMemberCount());
        $this->assertTrue($team->getMembers()->contains($person1));

        $team->addMember($person2);
        $this->assertEquals(2, $team->getMemberCount());

        // Adding the same person again must not increase the count
        $team->addMember($person1);
        $this->assertEquals(2, $team->getMemberCount());

        $team->removeMember($person1);
        $this->assertEquals(1, $team->getMemberCount());
        $this->assertFalse($team->getMembers()->contains($person1));
        $this->assertTrue($team->getMembers()->contains($person2));

        $team->removeMember($person2);
        $this->assertEquals(0, $team->getMemberCount());
    }

    #[Test]
    public function testValidFromAndValidUntil(): void
    {
        $team = new Team();

        $this->assertNull($team->getValidFrom());
        $this->assertNull($team->getValidUntil());

        $from = new DateTimeImmutable('2026-01-01');
        $until = new DateTimeImmutable('2026-12-31');

        $team->setValidFrom($from);
        $this->assertEquals($from, $team->getValidFrom());

        $team->setValidUntil($until);
        $this->assertEquals($until, $team->getValidUntil());

        $team->setValidFrom(null);
        $this->assertNull($team->getValidFrom());

        $team->setValidUntil(null);
        $this->assertNull($team->getValidUntil());
    }
}
