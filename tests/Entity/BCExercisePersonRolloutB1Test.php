<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BCExercise;
use App\Entity\Person;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-Rollout Phase B1 — BCExercise.exerciseLeader{User,Person}
 * chain, falling back to the facilitator slot.
 */
final class BCExercisePersonRolloutB1Test extends TestCase
{
    #[Test]
    public function exerciseLeaderUserTakesPrecedenceOverPersonAndFacilitator(): void
    {
        $exercise = new BCExercise();
        $user = (new User())->setFirstName('Alice')->setLastName('Anderson');
        $person = (new Person())->setFullName('Bob Brown');
        $exercise
            ->setExerciseLeaderPerson($person)
            ->setExerciseLeaderUser($user);

        $this->assertSame('Alice Anderson', $exercise->getEffectiveExerciseLeaderName());
    }

    #[Test]
    public function exerciseLeaderPersonUsedWhenNoUser(): void
    {
        $exercise = new BCExercise();
        $person = (new Person())->setFullName('Bob Brown');
        $exercise
            ->setExerciseLeaderPerson($person);

        $this->assertSame('Bob Brown', $exercise->getEffectiveExerciseLeaderName());
    }

    #[Test]
    public function facilitatorUsedWhenNoTypedLeader(): void
    {
        $exercise = (new BCExercise())->setFacilitatorPerson((new Person())->setFullName('Carol Carlson'));

        $this->assertSame('Carol Carlson', $exercise->getEffectiveExerciseLeaderName());
        $this->assertNull($exercise->getExerciseLeaderUser());
        $this->assertNull($exercise->getExerciseLeaderPerson());
    }

    #[Test]
    public function leaderSlotsCanBeReset(): void
    {
        $exercise = new BCExercise();
        $person = (new Person())->setFullName('Bob Brown');
        $exercise->setExerciseLeaderPerson($person)->setExerciseLeaderPerson(null);

        $this->assertNull($exercise->getExerciseLeaderPerson());
    }
}
