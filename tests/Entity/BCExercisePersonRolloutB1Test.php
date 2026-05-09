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
 * Tri-State chain on top of legacy `facilitator` free-text.
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
            ->setFacilitator('Carol Carlson')
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
            ->setFacilitator('Carol Carlson')
            ->setExerciseLeaderPerson($person);

        $this->assertSame('Bob Brown', $exercise->getEffectiveExerciseLeaderName());
    }

    #[Test]
    public function legacyFacilitatorUsedWhenNoTypedLeader(): void
    {
        $exercise = new BCExercise();
        $exercise->setFacilitator('Carol Carlson');

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
