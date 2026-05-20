<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\BCExercise;

use App\AlvaHint\Rule\BCExercise\NoFindingsLoggedRule;
use App\Entity\BCExercise;
use App\Entity\User;
use App\Enum\BCExerciseStatus;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class NoFindingsLoggedRuleTest extends TestCase
{
    private NoFindingsLoggedRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new NoFindingsLoggedRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForCompletedExerciseWithNoFindingsOrImprovements(): void
    {
        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getStatusEnum')->willReturn(BCExerciseStatus::Completed);
        $exercise->method('getFindings')->willReturn(null);
        $exercise->method('getAreasForImprovement')->willReturn(null);

        $this->assertTrue($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenFindingsAreDocumented(): void
    {
        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getStatusEnum')->willReturn(BCExerciseStatus::Completed);
        $exercise->method('getFindings')->willReturn('Network failover too slow.');
        $exercise->method('getAreasForImprovement')->willReturn(null);

        $this->assertFalse($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenAreasForImprovementAreDocumented(): void
    {
        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getStatusEnum')->willReturn(BCExerciseStatus::Completed);
        $exercise->method('getFindings')->willReturn(null);
        $exercise->method('getAreasForImprovement')->willReturn('Communication plan needs update.');

        $this->assertFalse($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function doesNotApplyForPlannedExercise(): void
    {
        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getStatusEnum')->willReturn(BCExerciseStatus::Planned);

        $this->assertFalse($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonExerciseEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKey(): void
    {
        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getId')->willReturn(3);

        $hint = $this->rule->build($exercise, $this->user);
        $this->assertSame('bc_exercise.no_findings_logged', $hint->key);
        $this->assertSame(3, $hint->priorityTier);
        $this->assertTrue($hint->dismissible);
    }

    #[Test]
    public function moduleGateIsBcm(): void
    {
        $this->assertSame(['bcm'], $this->rule->requiredModules());
    }
}
