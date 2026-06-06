<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RoadmapGroup;
use App\Entity\Team;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RoadmapGroupTest extends TestCase
{
    #[Test]
    public function testConstructorSetsCreatedAt(): void
    {
        $group = new RoadmapGroup();

        $this->assertNotNull($group->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $group->getCreatedAt());
    }

    #[Test]
    public function testNameGetterAndSetter(): void
    {
        $group = new RoadmapGroup();

        $this->assertNull($group->getName());

        $group->setName('Governance');
        $this->assertEquals('Governance', $group->getName());

        $group->setName(null);
        $this->assertNull($group->getName());
    }

    #[Test]
    public function testSortOrderDefaultAndSetter(): void
    {
        $group = new RoadmapGroup();

        $this->assertEquals(0, $group->getSortOrder());

        $group->setSortOrder(5);
        $this->assertEquals(5, $group->getSortOrder());
    }

    #[Test]
    public function testColorTokenGetterAndSetter(): void
    {
        $group = new RoadmapGroup();

        $this->assertNull($group->getColorToken());

        $group->setColorToken('primary');
        $this->assertEquals('primary', $group->getColorToken());

        $group->setColorToken(null);
        $this->assertNull($group->getColorToken());
    }

    #[Test]
    public function testIconGetterAndSetter(): void
    {
        $group = new RoadmapGroup();

        $this->assertNull($group->getIcon());

        $group->setIcon('fa-shield');
        $this->assertEquals('fa-shield', $group->getIcon());

        $group->setIcon(null);
        $this->assertNull($group->getIcon());
    }

    #[Test]
    public function testIsmsDomainGetterAndSetter(): void
    {
        $group = new RoadmapGroup();

        $this->assertNull($group->getIsmsDomain());

        $group->setIsmsDomain('A.5');
        $this->assertEquals('A.5', $group->getIsmsDomain());

        $group->setIsmsDomain(null);
        $this->assertNull($group->getIsmsDomain());
    }

    #[Test]
    public function testDefaultVisibilityDefaultAndSetter(): void
    {
        $group = new RoadmapGroup();

        $this->assertEquals('team', $group->getDefaultVisibility());

        $group->setDefaultVisibility('all');
        $this->assertEquals('all', $group->getDefaultVisibility());

        $group->setDefaultVisibility('team');
        $this->assertEquals('team', $group->getDefaultVisibility());
    }

    #[Test]
    public function testIsSystemGroupDefaultAndSetter(): void
    {
        $group = new RoadmapGroup();

        $this->assertFalse($group->isSystemGroup());

        $group->setIsSystemGroup(true);
        $this->assertTrue($group->isSystemGroup());

        $group->setIsSystemGroup(false);
        $this->assertFalse($group->isSystemGroup());
    }

    #[Test]
    public function testIsActiveDefaultAndSetter(): void
    {
        $group = new RoadmapGroup();

        $this->assertTrue($group->isActive());

        $group->setIsActive(false);
        $this->assertFalse($group->isActive());

        $group->setIsActive(true);
        $this->assertTrue($group->isActive());
    }

    #[Test]
    public function testDefaultTeamRelationship(): void
    {
        $group = new RoadmapGroup();
        $team = new Team();

        $this->assertNull($group->getDefaultTeam());

        $group->setDefaultTeam($team);
        $this->assertSame($team, $group->getDefaultTeam());

        $group->setDefaultTeam(null);
        $this->assertNull($group->getDefaultTeam());
    }
}
