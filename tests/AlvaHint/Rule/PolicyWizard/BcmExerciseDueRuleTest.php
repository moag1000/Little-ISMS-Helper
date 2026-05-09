<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\PolicyWizard;

use App\AlvaHint\Rule\PolicyWizard\BcmExerciseDueRule;
use App\Entity\BCExercise;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\BCExerciseRepository;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W7-D — BcmExerciseDueRule unit tests.
 */
#[AllowMockObjectsWithoutExpectations]
final class BcmExerciseDueRuleTest extends TestCase
{
    private BCExerciseRepository&MockObject $repository;
    private User $user;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(BCExerciseRepository::class);
        $this->user = new User();
    }

    #[Test]
    public function testFiresWhenConditionsMet(): void
    {
        $tenant = $this->makeTenant(11);

        $upcoming = $this->makeExercise(501, 'Tabletop Q3', 'tabletop', $this->daysFromNow(7));
        $this->repository->method('findBy')->willReturn([$upcoming]);

        $rule = new BcmExerciseDueRule($this->repository);
        self::assertTrue($rule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function testSkipsWhenConditionsNotMet(): void
    {
        $tenant = $this->makeTenant(11);

        // Case 1: only future exercises beyond the upcoming window.
        $farFuture = $this->makeExercise(601, 'Annual Drill', 'simulation', $this->daysFromNow(60));
        $this->repository->method('findBy')->willReturn([$farFuture]);

        $rule = new BcmExerciseDueRule($this->repository);
        self::assertFalse($rule->appliesTo($tenant, $this->user));

        // Case 2: no planned exercises at all.
        $emptyRepo = $this->createMock(BCExerciseRepository::class);
        $emptyRepo->method('findBy')->willReturn([]);
        $emptyRule = new BcmExerciseDueRule($emptyRepo);
        self::assertFalse($emptyRule->appliesTo($tenant, $this->user));
    }

    #[Test]
    public function testSkipsWhenWrongRole(): void
    {
        $tenant = $this->makeTenant(11);

        $upcoming = $this->makeExercise(501, 'Tabletop', 'tabletop', $this->daysFromNow(7));
        $this->repository->method('findBy')->willReturn([$upcoming]);

        $rule = new BcmExerciseDueRule($this->repository);
        $hint = $rule->build($tenant, $this->user);

        self::assertSame(['ROLE_ADMIN', 'ROLE_GROUP_BCM_OFFICER'], $hint->requiredRoles);
        self::assertNotContains('ROLE_USER', $hint->requiredRoles);
        self::assertNotContains('ROLE_AUDITOR', $hint->requiredRoles);
    }

    #[Test]
    public function testSkipsWhenModuleDisabled(): void
    {
        $rule = new BcmExerciseDueRule($this->repository);
        // BCM hint requires BOTH the policy_wizard host and the bcm
        // module so it doesn't render where the module isn't even active.
        self::assertContains('policy_wizard', $rule->requiredModules());
        self::assertContains('bcm', $rule->requiredModules());
    }

    #[Test]
    public function testRenderAndDismissTelemetry(): void
    {
        $tenant = $this->makeTenant(42);

        // Overdue exercise wins over upcoming and escalates to Tier-1
        // non-dismissible. Verify the data telemetry depends on.
        $overdue = $this->makeExercise(701, 'Overdue Walkthrough', 'walkthrough', $this->daysFromNow(-5));
        $upcoming = $this->makeExercise(702, 'Soon Tabletop', 'tabletop', $this->daysFromNow(3));
        $this->repository->method('findBy')->willReturn([$overdue, $upcoming]);

        $rule = new BcmExerciseDueRule($this->repository);
        $hint = $rule->build($tenant, $this->user);

        self::assertSame('policy_wizard.bcm_exercise_due', $hint->key);
        self::assertSame(BcmExerciseDueRule::VERSION, $hint->version);
        self::assertSame(1, $hint->priorityTier, 'Overdue exercise must escalate to Tier-1');
        self::assertFalse($hint->dismissible, 'Tier-1 hints must not be dismissible');
        self::assertSame('BCExercise', $hint->entityType);
        self::assertSame(701, $hint->entityId);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame('alva_hint.bcm_exercise_overdue.title', $hint->titleTranslationKey);
        self::assertSame('alva_hint.bcm_exercise_overdue.body', $hint->bodyTranslationKey);
        self::assertSame('alva_hint.bcm_exercise_overdue.cta_label', $hint->actionLabelTranslationKey);
        self::assertSame('app_bc_exercise_show', $hint->actionRoute);
        self::assertSame(['id' => 701], $hint->actionRouteParams);
        self::assertSame('Overdue Walkthrough', $hint->bodyTranslationParams['%exercise_name%'] ?? null);
        self::assertSame('walkthrough', $hint->bodyTranslationParams['%exercise_type%'] ?? null);
        self::assertSame('5', $hint->bodyTranslationParams['%days%'] ?? null);

        // Pure upcoming case — Tier-2 + dismissible, different key set.
        $upcomingOnlyRepo = $this->createMock(BCExerciseRepository::class);
        $upcomingOnly = $this->makeExercise(801, 'Tabletop Q4', 'tabletop', $this->daysFromNow(10));
        $upcomingOnlyRepo->method('findBy')->willReturn([$upcomingOnly]);
        $upcomingRule = new BcmExerciseDueRule($upcomingOnlyRepo);
        $upHint = $upcomingRule->build($tenant, $this->user);

        self::assertSame(2, $upHint->priorityTier);
        self::assertTrue($upHint->dismissible);
        self::assertSame('alva_hint.bcm_exercise_due.title', $upHint->titleTranslationKey);
        self::assertSame('alva_hint.bcm_exercise_due.body', $upHint->bodyTranslationKey);
        self::assertSame('alva_hint.bcm_exercise_due.cta_label', $upHint->actionLabelTranslationKey);
        self::assertSame('Tabletop Q4', $upHint->bodyTranslationParams['%exercise_name%'] ?? null);
        self::assertSame((string) BcmExerciseDueRule::UPCOMING_WINDOW_DAYS, $upHint->bodyTranslationParams['%window_days%'] ?? null);
    }

    private function makeTenant(int $id): Tenant&MockObject
    {
        $stub = $this->createMock(Tenant::class);
        $stub->method('getId')->willReturn($id);
        return $stub;
    }

    private function makeExercise(int $id, string $name, string $type, DateTimeImmutable $exerciseDate): BCExercise&MockObject
    {
        $stub = $this->createMock(BCExercise::class);
        $stub->method('getId')->willReturn($id);
        $stub->method('getName')->willReturn($name);
        $stub->method('getExerciseType')->willReturn($type);
        $stub->method('getExerciseDate')->willReturn($exerciseDate);
        $stub->method('getStatus')->willReturn('planned');
        return $stub;
    }

    private function daysFromNow(int $days): DateTimeImmutable
    {
        $now = new DateTimeImmutable();
        if ($days >= 0) {
            return $now->add(new DateInterval('P' . $days . 'D'));
        }
        return $now->sub(new DateInterval('P' . abs($days) . 'D'));
    }
}
