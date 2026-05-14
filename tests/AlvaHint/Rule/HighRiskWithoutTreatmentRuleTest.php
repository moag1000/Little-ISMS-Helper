<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Risk\HighRiskWithoutTreatmentRule;
use App\Entity\Risk;
use App\Entity\User;
use App\Repository\RiskTreatmentPlanRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class HighRiskWithoutTreatmentRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Test]
    public function appliesForHighRiskWithoutPlan(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(20);

        $repo = $this->createMock(RiskTreatmentPlanRepository::class);
        $repo->method('findByRisk')->willReturn([]);

        $rule = new HighRiskWithoutTreatmentRule($repo);
        $this->assertTrue($rule->appliesTo($risk, $this->user));
    }

    #[Test]
    public function doesNotApplyBelowThreshold(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(10);

        $repo = $this->createMock(RiskTreatmentPlanRepository::class);

        $rule = new HighRiskWithoutTreatmentRule($repo);
        $this->assertFalse($rule->appliesTo($risk, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenPlanExists(): void
    {
        $risk = $this->createMock(Risk::class);
        $risk->method('getInherentRiskLevel')->willReturn(25);

        $repo = $this->createMock(RiskTreatmentPlanRepository::class);
        $repo->method('findByRisk')->willReturn([new \stdClass()]);

        $rule = new HighRiskWithoutTreatmentRule($repo);
        $this->assertFalse($rule->appliesTo($risk, $this->user));
    }
}
