<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Risk\TreatmentPlanOverdueRule;
use App\Entity\Risk;
use App\Entity\User;
use App\Repository\RiskTreatmentPlanRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TreatmentPlanOverdueRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Test]
    public function appliesForHighRiskCreatedOverThirtyDaysAgoWithoutPlan(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(15);
        $risk->method('getCreatedAt')->willReturn(new DateTimeImmutable('-45 days'));

        $repo = $this->createMock(RiskTreatmentPlanRepository::class);
        $repo->method('findByRisk')->willReturn([]);

        $rule = new TreatmentPlanOverdueRule($repo);
        $this->assertTrue($rule->appliesTo($risk, $this->user));
    }

    #[Test]
    public function doesNotApplyBelowRiskThreshold(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(10);
        $risk->method('getCreatedAt')->willReturn(new DateTimeImmutable('-45 days'));

        $repo = $this->createMock(RiskTreatmentPlanRepository::class);
        $repo->method('findByRisk')->willReturn([]);

        $rule = new TreatmentPlanOverdueRule($repo);
        $this->assertFalse($rule->appliesTo($risk, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenPlanExists(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(20);
        $risk->method('getCreatedAt')->willReturn(new DateTimeImmutable('-45 days'));

        $repo = $this->createMock(RiskTreatmentPlanRepository::class);
        $repo->method('findByRisk')->willReturn([new \stdClass()]);

        $rule = new TreatmentPlanOverdueRule($repo);
        $this->assertFalse($rule->appliesTo($risk, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenRiskIsRecentlyCreated(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(20);
        $risk->method('getCreatedAt')->willReturn(new DateTimeImmutable('-10 days'));

        $repo = $this->createMock(RiskTreatmentPlanRepository::class);
        $repo->method('findByRisk')->willReturn([]);

        $rule = new TreatmentPlanOverdueRule($repo);
        $this->assertFalse($rule->appliesTo($risk, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonRiskEntity(): void
    {
        $repo = $this->createMock(RiskTreatmentPlanRepository::class);
        $rule = new TreatmentPlanOverdueRule($repo);
        $this->assertFalse($rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKey(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(20);
        $risk->method('getCreatedAt')->willReturn(new DateTimeImmutable('-45 days'));
        $risk->method('getId')->willReturn(1);

        $repo = $this->createMock(RiskTreatmentPlanRepository::class);
        $repo->method('findByRisk')->willReturn([]);

        $rule = new TreatmentPlanOverdueRule($repo);
        $hint = $rule->build($risk, $this->user);

        $this->assertSame('risk.treatment_plan_overdue', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertTrue($hint->dismissible);
    }

    #[Test]
    public function moduleGateIsRisks(): void
    {
        $repo = $this->createMock(RiskTreatmentPlanRepository::class);
        $rule = new TreatmentPlanOverdueRule($repo);
        $this->assertSame(['risks'], $rule->requiredModules());
    }
}
