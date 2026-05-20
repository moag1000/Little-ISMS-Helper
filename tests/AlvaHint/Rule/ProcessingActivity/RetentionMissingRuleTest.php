<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\ProcessingActivity;

use App\AlvaHint\Rule\ProcessingActivity\DpiaMissingOnHighRiskRule;
use App\Entity\ProcessingActivity;
use App\Entity\User;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class RetentionMissingRuleTest extends TestCase
{
    private DpiaMissingOnHighRiskRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new DpiaMissingOnHighRiskRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenRetentionPeriodIsNull(): void
    {
        $activity = $this->createMock(ProcessingActivity::class);
        $activity->method('getRetentionPeriod')->willReturn(null);

        $this->assertTrue($this->rule->appliesTo($activity, $this->user));
    }

    #[Test]
    public function appliesWhenRetentionPeriodIsEmptyString(): void
    {
        $activity = $this->createMock(ProcessingActivity::class);
        $activity->method('getRetentionPeriod')->willReturn('   ');

        $this->assertTrue($this->rule->appliesTo($activity, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenRetentionPeriodIsSet(): void
    {
        $activity = $this->createMock(ProcessingActivity::class);
        $activity->method('getRetentionPeriod')->willReturn('7 years');

        $this->assertFalse($this->rule->appliesTo($activity, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonProcessingActivityEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsHintWithCorrectKey(): void
    {
        $activity = $this->createMock(ProcessingActivity::class);
        $activity->method('getId')->willReturn(7);
        $activity->method('getRetentionPeriod')->willReturn(null);

        $hint = $this->rule->build($activity, $this->user);
        $this->assertSame('processing_activity.retention_missing', $hint->key);
        $this->assertSame(2, $hint->priorityTier);
        $this->assertTrue($hint->dismissible);
    }

    #[Test]
    public function moduleGateIsPrivacy(): void
    {
        $this->assertSame(['privacy'], $this->rule->requiredModules());
    }
}
