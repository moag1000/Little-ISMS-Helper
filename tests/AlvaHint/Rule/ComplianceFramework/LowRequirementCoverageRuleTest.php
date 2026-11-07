<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\ComplianceFramework;

use App\AlvaHint\Rule\ComplianceFramework\LowRequirementCoverageRule;
use App\Entity\ComplianceFramework;
use App\Entity\User;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class LowRequirementCoverageRuleTest extends TestCase
{
    private LowRequirementCoverageRule $rule;
    private User $user;
    private ComplianceRequirementFulfillmentRepository&\PHPUnit\Framework\MockObject\MockObject $repo;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(ComplianceRequirementFulfillmentRepository::class);
        $this->rule = new LowRequirementCoverageRule($this->repo);
        $this->user = (new User())->setTenant(new Tenant());
    }

    #[Test]
    public function appliesWhenActiveFrameworkHasLowCoverage(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn(true);
        $framework->method('getRequirements')->willReturn(new ArrayCollection([new \stdClass()]));
        $framework->method('getId')->willReturn(7);
        $this->repo->method('fulfilledShareByFramework')->willReturn([7 => 30.0]);

        $this->assertTrue($this->rule->appliesTo($framework, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenCoverageIsAboveThreshold(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn(true);
        $framework->method('getRequirements')->willReturn(new ArrayCollection([new \stdClass()]));
        $framework->method('getId')->willReturn(7);
        $this->repo->method('fulfilledShareByFramework')->willReturn([7 => 75.0]);

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
        $this->repo->method('fulfilledShareByFramework')->willReturn([2 => 25.0]);

        $hint = $this->rule->build($framework, $this->user);
        $this->assertSame('compliance_framework.low_coverage', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertSame('warning', $hint->variant);
    }

    #[Test]
    public function noHintWithoutOrganisation(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn(true);
        $framework->method('getRequirements')->willReturn(new ArrayCollection([new \stdClass()]));
        $this->repo->expects($this->never())->method('fulfilledShareByFramework');

        $this->assertFalse($this->rule->appliesTo($framework, new User()));
    }

    #[Test]
    public function moduleGateIsCompliance(): void
    {
        $this->assertSame(['compliance'], $this->rule->requiredModules());
    }

    #[Test]
    public function percentageIsComputedOnceAcrossAppliesToAndBuild(): void
    {
        $framework = $this->createMock(ComplianceFramework::class);
        $framework->method('isActive')->willReturn(true);
        $framework->method('getRequirements')->willReturn(new ArrayCollection([new \stdClass()]));
        $framework->method('getName')->willReturn('X');
        $framework->method('getId')->willReturn(7);
        $this->repo->expects($this->once())->method('fulfilledShareByFramework')->willReturn([7 => 30.0]);

        $this->assertTrue($this->rule->appliesTo($framework, $this->user));
        $this->rule->build($framework, $this->user);
    }
}
