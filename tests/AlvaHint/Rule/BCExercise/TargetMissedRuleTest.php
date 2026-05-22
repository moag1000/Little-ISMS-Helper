<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\BCExercise;

use App\AlvaHint\Rule\BCExercise\TargetMissedRule;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

// Junior-ISB-Audit-2026-05-22 K-07: ISO 22301 Cl. 8.5.3 + 9.1.1 Lessons-Learned loop

#[AllowMockObjectsWithoutExpectations]
class TargetMissedRuleTest extends TestCase
{
    private TargetMissedRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new TargetMissedRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenActualRtoExceedsPlannedRto(): void
    {
        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getRto')->willReturn(4);
        $plan->method('getId')->willReturn(7);
        $plan->method('getName')->willReturn('Tier-1 ERP Recovery Plan');

        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getActualRtoAchieved')->willReturn('6.50');
        $exercise->method('getTestedPlans')->willReturn(new ArrayCollection([$plan]));

        $this->assertTrue($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenActualRtoMeetsPlannedRto(): void
    {
        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getRto')->willReturn(4);

        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getActualRtoAchieved')->willReturn('4.00');
        $exercise->method('getTestedPlans')->willReturn(new ArrayCollection([$plan]));

        $this->assertFalse($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenActualRtoBelowPlannedRto(): void
    {
        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getRto')->willReturn(8);

        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getActualRtoAchieved')->willReturn('3.00');
        $exercise->method('getTestedPlans')->willReturn(new ArrayCollection([$plan]));

        $this->assertFalse($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenActualRtoIsNull(): void
    {
        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getRto')->willReturn(4);

        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getActualRtoAchieved')->willReturn(null);
        $exercise->method('getTestedPlans')->willReturn(new ArrayCollection([$plan]));

        $this->assertFalse($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenNoTestedPlans(): void
    {
        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getActualRtoAchieved')->willReturn('10.00');
        $exercise->method('getTestedPlans')->willReturn(new ArrayCollection());

        $this->assertFalse($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenPlanHasNoRto(): void
    {
        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getRto')->willReturn(null);

        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getActualRtoAchieved')->willReturn('10.00');
        $exercise->method('getTestedPlans')->willReturn(new ArrayCollection([$plan]));

        $this->assertFalse($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonExerciseEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function firesWhenAnyTestedPlansRtoIsExceeded(): void
    {
        // One plan would be met (large RTO), another exceeded (small RTO).
        $okPlan = $this->createMock(BusinessContinuityPlan::class);
        $okPlan->method('getRto')->willReturn(24);

        $missedPlan = $this->createMock(BusinessContinuityPlan::class);
        $missedPlan->method('getRto')->willReturn(2);
        $missedPlan->method('getId')->willReturn(42);
        $missedPlan->method('getName')->willReturn('Customer-Portal Plan');

        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getActualRtoAchieved')->willReturn('5.00');
        $exercise->method('getTestedPlans')->willReturn(new ArrayCollection([$okPlan, $missedPlan]));

        $this->assertTrue($this->rule->appliesTo($exercise, $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKeyAndCtaToPlanEdit(): void
    {
        $plan = $this->createMock(BusinessContinuityPlan::class);
        $plan->method('getRto')->willReturn(4);
        $plan->method('getId')->willReturn(7);
        $plan->method('getName')->willReturn('Tier-1 ERP Recovery Plan');

        $exercise = $this->createMock(BCExercise::class);
        $exercise->method('getActualRtoAchieved')->willReturn('6.50');
        $exercise->method('getTestedPlans')->willReturn(new ArrayCollection([$plan]));
        $exercise->method('getId')->willReturn(99);

        $hint = $this->rule->build($exercise, $this->user);

        $this->assertSame('bc_exercise.target_missed', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertTrue($hint->dismissible);
        $this->assertSame('warning', $hint->variant);
        $this->assertSame('BCExercise', $hint->entityType);
        $this->assertSame(99, $hint->entityId);
        $this->assertSame('app_bc_plan_edit', $hint->actionRoute);
        $this->assertSame(['id' => 7], $hint->actionRouteParams);
        $this->assertSame('GET', $hint->actionMethod);
        $this->assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
        $this->assertSame(4, $hint->bodyTranslationParams['%planRto%']);
        $this->assertSame('6.5', $hint->bodyTranslationParams['%actualRto%']);
        $this->assertSame('Tier-1 ERP Recovery Plan', $hint->bodyTranslationParams['%planTitle%']);
    }

    #[Test]
    public function moduleGateIsBcm(): void
    {
        $this->assertSame(['bcm'], $this->rule->requiredModules());
    }
}
