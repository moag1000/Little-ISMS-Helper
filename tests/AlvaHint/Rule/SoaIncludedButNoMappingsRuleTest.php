<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Control\SoaIncludedButNoMappingsRule;
use App\Entity\Control;
use App\Entity\Risk;
use App\Entity\User;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class SoaIncludedButNoMappingsRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Test]
    public function appliesForApplicableControlWithRisksButNoMappings(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn(true);
        $control->method('getRisks')->willReturn(new ArrayCollection([new Risk()]));
        $control->method('getId')->willReturn(1);

        $repo = $this->createMock(ComplianceRequirementRepository::class);
        $repo->method('findByControl')->willReturn([]);

        $rule = new SoaIncludedButNoMappingsRule($repo);
        $this->assertTrue($rule->appliesTo($control, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenNotApplicable(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn(false);

        $repo = $this->createMock(ComplianceRequirementRepository::class);
        $rule = new SoaIncludedButNoMappingsRule($repo);
        $this->assertFalse($rule->appliesTo($control, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenMappingsExist(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn(true);
        $control->method('getRisks')->willReturn(new ArrayCollection([new Risk()]));
        $control->method('getId')->willReturn(1);

        $repo = $this->createMock(ComplianceRequirementRepository::class);
        $repo->method('findByControl')->willReturn([new \stdClass()]);

        $rule = new SoaIncludedButNoMappingsRule($repo);
        $this->assertFalse($rule->appliesTo($control, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenNoRisksLinked(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('isApplicable')->willReturn(true);
        $control->method('getRisks')->willReturn(new ArrayCollection());

        $repo = $this->createMock(ComplianceRequirementRepository::class);
        $rule = new SoaIncludedButNoMappingsRule($repo);
        $this->assertFalse($rule->appliesTo($control, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonControlEntity(): void
    {
        $repo = $this->createMock(ComplianceRequirementRepository::class);
        $rule = new SoaIncludedButNoMappingsRule($repo);
        $this->assertFalse($rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKey(): void
    {
        $control = $this->createMock(Control::class);
        $control->method('getId')->willReturn(2);

        $repo = $this->createMock(ComplianceRequirementRepository::class);
        $rule = new SoaIncludedButNoMappingsRule($repo);

        $hint = $rule->build($control, $this->user);
        $this->assertSame('control.soa_no_mappings', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
    }

    #[Test]
    public function moduleGateIsControlsAndCompliance(): void
    {
        $repo = $this->createMock(ComplianceRequirementRepository::class);
        $rule = new SoaIncludedButNoMappingsRule($repo);
        $this->assertSame(['controls', 'compliance'], $rule->requiredModules());
    }
}
