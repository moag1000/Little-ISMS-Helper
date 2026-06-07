<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\RoadmapGroup;
use App\Entity\RoadmapTask;
use App\Entity\Team;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RoadmapTaskTest extends TestCase
{
    #[Test]
    public function testConstructorSetsCreatedAtAndEmptyVisibleTeams(): void
    {
        $task = new RoadmapTask();

        $this->assertNotNull($task->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $task->getCreatedAt());
        $this->assertCount(0, $task->getVisibleTeams());
    }

    #[Test]
    public function testNameGetterAndSetter(): void
    {
        $task = new RoadmapTask();

        $this->assertNull($task->getName());

        $task->setName('Risk Assessment');
        $this->assertEquals('Risk Assessment', $task->getName());

        $task->setName(null);
        $this->assertNull($task->getName());
    }

    #[Test]
    public function testDefaultPtPerWeekGetterAndSetter(): void
    {
        $task = new RoadmapTask();

        $this->assertNull($task->getDefaultPtPerWeek());

        $task->setDefaultPtPerWeek('2.5');
        $this->assertEquals('2.5', $task->getDefaultPtPerWeek());

        $task->setDefaultPtPerWeek(null);
        $this->assertNull($task->getDefaultPtPerWeek());
    }

    #[Test]
    public function testRecurringDefaultAndSetter(): void
    {
        $task = new RoadmapTask();

        $this->assertFalse($task->isRecurring());

        $task->setRecurring(true);
        $this->assertTrue($task->isRecurring());

        $task->setRecurring(false);
        $this->assertFalse($task->isRecurring());
    }

    #[Test]
    public function testVisibilityDefaultAndSetter(): void
    {
        $task = new RoadmapTask();

        $this->assertEquals('team', $task->getVisibility());

        $task->setVisibility('all');
        $this->assertEquals('all', $task->getVisibility());

        $task->setVisibility('team');
        $this->assertEquals('team', $task->getVisibility());
    }

    #[Test]
    public function testIsmsDomainGetterAndSetter(): void
    {
        $task = new RoadmapTask();

        $this->assertNull($task->getIsmsDomain());

        $task->setIsmsDomain('A.8');
        $this->assertEquals('A.8', $task->getIsmsDomain());

        $task->setIsmsDomain(null);
        $this->assertNull($task->getIsmsDomain());
    }

    #[Test]
    public function testIsReactiveReservationDefaultAndSetter(): void
    {
        $task = new RoadmapTask();

        $this->assertFalse($task->isReactiveReservation());

        $task->setIsReactiveReservation(true);
        $this->assertTrue($task->isReactiveReservation());

        $task->setIsReactiveReservation(false);
        $this->assertFalse($task->isReactiveReservation());
    }

    #[Test]
    public function testIsSystemTaskDefaultAndSetter(): void
    {
        $task = new RoadmapTask();

        $this->assertFalse($task->isSystemTask());

        $task->setIsSystemTask(true);
        $this->assertTrue($task->isSystemTask());

        $task->setIsSystemTask(false);
        $this->assertFalse($task->isSystemTask());
    }

    #[Test]
    public function testIsActiveDefaultAndSetter(): void
    {
        $task = new RoadmapTask();

        $this->assertTrue($task->isActive());

        $task->setIsActive(false);
        $this->assertFalse($task->isActive());

        $task->setIsActive(true);
        $this->assertTrue($task->isActive());
    }

    #[Test]
    public function testGroupRelationship(): void
    {
        $task = new RoadmapTask();
        $group = new RoadmapGroup();

        $this->assertNull($task->getGroup());

        $task->setGroup($group);
        $this->assertSame($group, $task->getGroup());

        $task->setGroup(null);
        $this->assertNull($task->getGroup());
    }

    #[Test]
    public function testDefaultTeamRelationship(): void
    {
        $task = new RoadmapTask();
        $team = new Team();

        $this->assertNull($task->getDefaultTeam());

        $task->setDefaultTeam($team);
        $this->assertSame($team, $task->getDefaultTeam());

        $task->setDefaultTeam(null);
        $this->assertNull($task->getDefaultTeam());
    }

    #[Test]
    public function testAddVisibleTeamAndRemoveVisibleTeam(): void
    {
        $task = new RoadmapTask();
        $team1 = new Team();
        $team2 = new Team();

        $this->assertCount(0, $task->getVisibleTeams());

        $task->addVisibleTeam($team1);
        $this->assertCount(1, $task->getVisibleTeams());
        $this->assertTrue($task->getVisibleTeams()->contains($team1));

        $task->addVisibleTeam($team2);
        $this->assertCount(2, $task->getVisibleTeams());

        // Adding the same team again must not increase the count
        $task->addVisibleTeam($team1);
        $this->assertCount(2, $task->getVisibleTeams());

        $task->removeVisibleTeam($team1);
        $this->assertCount(1, $task->getVisibleTeams());
        $this->assertFalse($task->getVisibleTeams()->contains($team1));
        $this->assertTrue($task->getVisibleTeams()->contains($team2));

        $task->removeVisibleTeam($team2);
        $this->assertCount(0, $task->getVisibleTeams());
    }
}
