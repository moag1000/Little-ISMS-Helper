<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\ComplianceFramework;

use App\AlvaHint\Rule\ComplianceFramework\LowRequirementCoverageRule;
use App\Entity\ComplianceFramework;
use App\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class LowRequirementCoverageRuleTest extends TestCase
{
    private LowRequirementCoverageRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new LowRequirementCoverageRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenActiveFrameworkHasLowCoverage(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn(true);
        $framework->method('getRequirements')->willReturn(new ArrayCollection([new \stdClass()]));
        $framework->method('getCompliancePercentage')->willReturn(30.0);

        $this->assertTrue($this->rule->appliesTo($framework, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenCoverageIsAboveThreshold(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn(true);
        $framework->method('getRequirements')->willReturn(new ArrayCollection([new \stdClass()]));
        $framework->method('getCompliancePercentage')->willReturn(75.0);

        $this->assertFalse($this->rule->appliesTo($framework, $this->user));
    }

    #[Test]
    public function doesNotApplyForInactiveFramework(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn(false);

        $this->assertFalse($this->rule->appliesTo($framework, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenNoRequirements(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn(true);
        $framework->method('getRequirements')->willReturn(new ArrayCollection());

        $this->assertFalse($this->rule->appliesTo($framework, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonFrameworkEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKey(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('getId')->willReturn(2);
        $framework->method('getName')->willReturn('ISO 27001');
        $framework->method('getCompliancePercentage')->willReturn(25.0);

        $hint = $this->rule->build($framework, $this->user);
        $this->assertSame('compliance_framework.low_coverage', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertSame('warning', $hint->variant);
    }

    #[Test]
    public function moduleGateIsCompliance(): void
    {
        $this->assertSame(['compliance'], $this->rule->requiredModules());
    }
}
