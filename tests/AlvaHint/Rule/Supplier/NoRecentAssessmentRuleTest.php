<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\Supplier;

use App\AlvaHint\Rule\Supplier\NoRecentAssessmentRule;
use App\Entity\Supplier;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class NoRecentAssessmentRuleTest extends TestCase
{
    private NoRecentAssessmentRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new NoRecentAssessmentRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForHighCriticalitySupplierWithNoAssessment(): void
    {
        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getCriticality')->willReturn('critical');
        $supplier->method('getLastSecurityAssessment')->willReturn(null);

        $this->assertTrue($this->rule->appliesTo($supplier, $this->user));
    }

    #[Test]
    public function appliesForHighCriticalitySupplierWithStaleAssessment(): void
    {
        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getCriticality')->willReturn('high');
        $supplier->method('getLastSecurityAssessment')->willReturn(new DateTimeImmutable('-15 months'));

        $this->assertTrue($this->rule->appliesTo($supplier, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenAssessmentIsRecent(): void
    {
        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getCriticality')->willReturn('critical');
        $supplier->method('getLastSecurityAssessment')->willReturn(new DateTimeImmutable('-3 months'));

        $this->assertFalse($this->rule->appliesTo($supplier, $this->user));
    }

    #[Test]
    public function doesNotApplyForLowCriticalitySupplier(): void
    {
        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getCriticality')->willReturn('low');

        $this->assertFalse($this->rule->appliesTo($supplier, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonSupplierEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithBodyNeverWhenNoAssessmentDate(): void
    {
        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getId')->willReturn(8);
        $supplier->method('getCriticality')->willReturn('critical');
        $supplier->method('getLastSecurityAssessment')->willReturn(null);

        $hint = $this->rule->build($supplier, $this->user);
        $this->assertSame('supplier.no_recent_assessment', $hint->key);
        $this->assertSame('supplier.no_recent_assessment.body_never', $hint->bodyTranslationKey);
        $this->assertSame(2, $hint->priorityTier);
    }

    #[Test]
    public function buildReturnsHintWithBodyWithLastWhenDateExists(): void
    {
        $supplier = $this->createMock(Supplier::class);
        $supplier->method('getId')->willReturn(8);
        $supplier->method('getCriticality')->willReturn('high');
        $supplier->method('getLastSecurityAssessment')->willReturn(new DateTimeImmutable('-15 months'));

        $hint = $this->rule->build($supplier, $this->user);
        $this->assertSame('supplier.no_recent_assessment.body_with_last', $hint->bodyTranslationKey);
    }

    #[Test]
    public function moduleGateIsSuppliers(): void
    {
        $this->assertSame(['suppliers'], $this->rule->requiredModules());
    }
}
