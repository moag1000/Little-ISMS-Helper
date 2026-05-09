<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CrisisTeam;
use App\Entity\Person;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-Rollout Phase B1 — CrisisTeam.personMembers Collection<Person>
 * (Pattern A dual-state with the legacy JSON `members` blob).
 */
final class CrisisTeamPersonRolloutB1Test extends TestCase
{
    #[Test]
    public function personMembersCollectionStartsEmpty(): void
    {
        $team = new CrisisTeam();

        $this->assertCount(0, $team->getPersonMembers());
        $this->assertSame(0, $team->getPersonMemberCount());
        $this->assertSame([], $team->getPersonMemberNames());
    }

    #[Test]
    public function addPersonMemberDeduplicates(): void
    {
        $team = new CrisisTeam();
        $alice = (new Person())->setFullName('Alice Anderson');

        $team->addPersonMember($alice);
        $team->addPersonMember($alice);

        $this->assertSame(1, $team->getPersonMemberCount());
    }

    #[Test]
    public function removePersonMemberDropsLink(): void
    {
        $team = new CrisisTeam();
        $alice = (new Person())->setFullName('Alice Anderson');
        $bob = (new Person())->setFullName('Bob Brown');

        $team
            ->addPersonMember($alice)
            ->addPersonMember($bob)
            ->removePersonMember($alice);

        $this->assertSame(1, $team->getPersonMemberCount());
        $this->assertFalse($team->getPersonMembers()->contains($alice));
        $this->assertTrue($team->getPersonMembers()->contains($bob));
    }

    #[Test]
    public function getPersonMemberNamesSkipsBlankFullNames(): void
    {
        $team = new CrisisTeam();
        $alice = (new Person())->setFullName('Alice Anderson');
        // Blank-name persons should not pollute the display roster.
        $blank = new Person();

        $team->addPersonMember($alice);
        $team->addPersonMember($blank);

        $this->assertSame(['Alice Anderson'], $team->getPersonMemberNames());
    }

    #[Test]
    public function effectiveMemberCountSumsJsonAndPersons(): void
    {
        $team = new CrisisTeam();
        $team->setMembers([
            ['user_id' => 1, 'name' => 'Carl Schmidt', 'role' => 'Comms Lead'],
            ['user_id' => 2, 'name' => 'Dora Friedrich', 'role' => 'Tech Lead'],
        ]);
        $alice = (new Person())->setFullName('Alice Anderson');
        $team->addPersonMember($alice);

        $this->assertSame(3, $team->getEffectiveMemberCount());
    }
}
