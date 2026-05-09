<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ManagementReview;
use App\Entity\Person;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-Rollout Phase B1 — ManagementReview.personParticipants
 * Collection<Person> twin of the User attendance roster.
 */
final class ManagementReviewPersonRolloutB1Test extends TestCase
{
    #[Test]
    public function personParticipantsStartEmpty(): void
    {
        $review = new ManagementReview();

        $this->assertCount(0, $review->getPersonParticipants());
        $this->assertSame(0, $review->getEffectiveParticipantCount());
        $this->assertSame([], $review->getEffectiveParticipantNames());
    }

    #[Test]
    public function addPersonParticipantDeduplicates(): void
    {
        $review = new ManagementReview();
        $alice = (new Person())->setFullName('Alice Anderson');

        $review->addPersonParticipant($alice);
        $review->addPersonParticipant($alice);

        $this->assertCount(1, $review->getPersonParticipants());
    }

    #[Test]
    public function effectiveParticipantCountSumsUserAndPerson(): void
    {
        $review = new ManagementReview();
        $userAttendee = (new User())->setFirstName('Carl')->setLastName('Schmidt');
        $personAttendee = (new Person())->setFullName('Dora Friedrich');

        $review->addParticipant($userAttendee);
        $review->addPersonParticipant($personAttendee);

        $this->assertSame(2, $review->getEffectiveParticipantCount());
    }

    #[Test]
    public function effectiveParticipantNamesListsUsersThenPersons(): void
    {
        $review = new ManagementReview();
        $userAttendee = (new User())->setFirstName('Carl')->setLastName('Schmidt');
        $personAttendee = (new Person())->setFullName('Dora Friedrich');

        $review->addParticipant($userAttendee);
        $review->addPersonParticipant($personAttendee);

        $this->assertSame(['Carl Schmidt', 'Dora Friedrich'], $review->getEffectiveParticipantNames());
    }

    #[Test]
    public function removePersonParticipantDropsLink(): void
    {
        $review = new ManagementReview();
        $alice = (new Person())->setFullName('Alice Anderson');
        $bob = (new Person())->setFullName('Bob Brown');

        $review
            ->addPersonParticipant($alice)
            ->addPersonParticipant($bob)
            ->removePersonParticipant($alice);

        $this->assertCount(1, $review->getPersonParticipants());
        $this->assertFalse($review->getPersonParticipants()->contains($alice));
    }
}
