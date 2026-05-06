<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\BusinessContinuityPlan\TestOverdueRule;
use App\Entity\BusinessContinuityPlan;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TestOverdueRuleTest extends TestCase
{
    private TestOverdueRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new TestOverdueRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForActivePlanNeverTested(): void
    {
        $plan = $this->buildPlan('active', null);
        $this->assertTrue($this->rule->appliesTo($plan, $this->user));
    }

    #[Test]
    public function appliesForActivePlanLastTestedTooLongAgo(): void
    {
        $plan = $this->buildPlan('active', new DateTimeImmutable('-2 years'));
        $this->assertTrue($this->rule->appliesTo($plan, $this->user));
    }

    #[Test]
    public function doesNotApplyForRecentTest(): void
    {
        $plan = $this->buildPlan('active', new DateTimeImmutable('-3 months'));
        $this->assertFalse($this->rule->appliesTo($plan, $this->user));
    }

    #[Test]
    public function doesNotApplyForDraftPlan(): void
    {
        $plan = $this->buildPlan('draft', null);
        $this->assertFalse($this->rule->appliesTo($plan, $this->user));
    }

    private function buildPlan(string $status, ?DateTimeImmutable $lastTested): BusinessContinuityPlan
    {
        $plan = new BusinessContinuityPlan();
        $plan->setStatus($status);
        $plan->setLastTested($lastTested);
        return $plan;
    }
}
