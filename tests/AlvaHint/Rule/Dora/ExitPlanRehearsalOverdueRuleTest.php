<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Dora;

use App\AlvaHint\Rule\Dora\ExitPlanRehearsalOverdueRule;
use App\Entity\DoraExitPlan;
use App\Entity\Supplier;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExitPlanRehearsalOverdueRuleTest extends TestCase
{
    private ExitPlanRehearsalOverdueRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new ExitPlanRehearsalOverdueRule();
        $this->user = new User();
    }

    #[Test]
    public function keyMatchesContract(): void
    {
        self::assertSame('dora_exit_plan.rehearsal_overdue', $this->rule->key());
    }

    #[Test]
    public function tierIsTwoAuditGap(): void
    {
        self::assertSame(2, $this->rule->priorityTier());
    }

    #[Test]
    public function requiresNis2DoraModule(): void
    {
        self::assertSame(['nis2_dora'], $this->rule->requiredModules());
    }

    #[Test]
    public function appliesWhenNeverTested(): void
    {
        $plan = $this->buildPlan(null);
        self::assertTrue($this->rule->appliesTo($plan, $this->user));
    }

    #[Test]
    public function appliesWhenOlderThan12Months(): void
    {
        $plan = $this->buildPlan(new DateTimeImmutable('-18 months'));
        self::assertTrue($this->rule->appliesTo($plan, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenRecentRehearsal(): void
    {
        $plan = $this->buildPlan(new DateTimeImmutable('-2 months'));
        self::assertFalse($this->rule->appliesTo($plan, $this->user));
    }

    #[Test]
    public function doesNotApplyForUnrelatedEntity(): void
    {
        self::assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildEmitsNeverTestedBodyKeyWhenNoRehearsal(): void
    {
        $plan = $this->buildPlan(null, 'Acme ICT');
        $hint = $this->rule->build($plan, $this->user);

        self::assertSame('dora_exit_plan.rehearsal_overdue', $hint->key);
        self::assertSame('dora_exit_plan.rehearsal_overdue.body_never', $hint->bodyTranslationKey);
        self::assertSame(['%supplier%' => 'Acme ICT'], $hint->bodyTranslationParams);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame('warning', $hint->variant);
        self::assertSame(2, $hint->priorityTier);
        self::assertTrue($hint->dismissible);
        self::assertSame('DoraExitPlan', $hint->entityType);
        self::assertSame('app_dora_exit_plan_edit', $hint->actionRoute);
        self::assertSame('GET', $hint->actionMethod);
        self::assertSame(['ROLE_MANAGER'], $hint->requiredRoles);
    }

    #[Test]
    public function buildEmitsOverdueBodyKeyWhenLastTestStale(): void
    {
        $plan = $this->buildPlan(new DateTimeImmutable('-14 months'), 'Beta SaaS');
        $hint = $this->rule->build($plan, $this->user);

        self::assertSame('dora_exit_plan.rehearsal_overdue.body_overdue', $hint->bodyTranslationKey);
        self::assertSame(['%supplier%' => 'Beta SaaS'], $hint->bodyTranslationParams);
    }

    private function buildPlan(?DateTimeImmutable $testedAt, string $supplierName = 'Supplier X'): DoraExitPlan
    {
        $supplier = new Supplier();
        $supplier->setName($supplierName);

        return (new DoraExitPlan())
            ->setSupplier($supplier)
            ->setTestedAt($testedAt);
    }
}
