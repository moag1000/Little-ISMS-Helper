<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Global;

use App\AlvaHint\Rule\Global\Bsi2004ImprovementActionOverdueRule;
use App\Entity\Bsi2004ExerciseLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Bsi2004ExerciseLogRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Bsi2004ImprovementActionOverdueRule.
 */
#[AllowMockObjectsWithoutExpectations]
final class Bsi2004ImprovementActionOverdueRuleTest extends TestCase
{
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user   = new User();
    }

    #[Test]
    public function returnsNullWhenNoOverdueLogs(): void
    {
        $repo = $this->createMock(Bsi2004ExerciseLogRepository::class);
        $repo->method('findImprovementActionsOverdue')->willReturn([]);

        $rule = new Bsi2004ImprovementActionOverdueRule($repo);
        self::assertNull($rule->evaluate($this->tenant, $this->user));
    }

    #[Test]
    public function returnsHintWhenOverdueLogsExist(): void
    {
        $yesterday = (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d');

        $log = new Bsi2004ExerciseLog();
        $log->setImprovementActions([
            ['description' => 'Fix fire exit', 'due_date' => $yesterday, 'completed' => false],
        ]);

        $repo = $this->createMock(Bsi2004ExerciseLogRepository::class);
        $repo->method('findImprovementActionsOverdue')->willReturn([$log]);

        $rule = new Bsi2004ImprovementActionOverdueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('global.bsi_2004_improvement_action_overdue', $hint->key);
        self::assertSame('warning', $hint->variant);
        self::assertSame(2, $hint->priorityTier);
        self::assertTrue($hint->dismissible);
        self::assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
        self::assertSame('bcm_exercise_log_index', $hint->actionRoute);
    }

    #[Test]
    public function hintBodyParamsContainCounts(): void
    {
        $yesterday = (new \DateTimeImmutable())->modify('-1 day')->format('Y-m-d');

        $log1 = new Bsi2004ExerciseLog();
        $log1->setImprovementActions([
            ['description' => 'A', 'due_date' => $yesterday, 'completed' => false],
            ['description' => 'B', 'due_date' => $yesterday, 'completed' => false],
        ]);

        $log2 = new Bsi2004ExerciseLog();
        $log2->setImprovementActions([
            ['description' => 'C', 'due_date' => $yesterday, 'completed' => false],
        ]);

        $repo = $this->createMock(Bsi2004ExerciseLogRepository::class);
        $repo->method('findImprovementActionsOverdue')->willReturn([$log1, $log2]);

        $rule = new Bsi2004ImprovementActionOverdueRule($repo);
        $hint = $rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame('3', $hint->bodyTranslationParams['%count%']);
        self::assertSame('2', $hint->bodyTranslationParams['%log_count%']);
    }

    #[Test]
    public function requiresBcmModule(): void
    {
        $rule = new Bsi2004ImprovementActionOverdueRule(
            $this->createMock(Bsi2004ExerciseLogRepository::class)
        );
        self::assertSame(['bcm'], $rule->requiredModules());
    }

    #[Test]
    public function appliesToExerciseLogPages(): void
    {
        $rule = new Bsi2004ImprovementActionOverdueRule(
            $this->createMock(Bsi2004ExerciseLogRepository::class)
        );
        self::assertContains('bcm_exercise_log_index', $rule->appliesToPages());
    }

    #[Test]
    public function priorityTierIsTwo(): void
    {
        $rule = new Bsi2004ImprovementActionOverdueRule(
            $this->createMock(Bsi2004ExerciseLogRepository::class)
        );
        self::assertSame(2, $rule->priorityTier());
    }
}
