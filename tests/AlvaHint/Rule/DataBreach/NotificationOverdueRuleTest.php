<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule\DataBreach;

use App\AlvaHint\Rule\DataBreach\NotificationOverdueRule;
use App\Entity\DataBreach;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class NotificationOverdueRuleTest extends TestCase
{
    private NotificationOverdueRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new NotificationOverdueRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenHighSeverityBreachDeadlinePassedWithoutNotification(): void
    {
        $breach = $this->createMock(DataBreach::class);
        $breach->method('getSeverity')->willReturn('high');
        $breach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(null);
        $breach->method('getDetectedAt')->willReturn(new DateTimeImmutable('-80 hours'));

        $this->assertTrue($this->rule->appliesTo($breach, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenDeadlineHasNotPassedYet(): void
    {
        $breach = $this->createMock(DataBreach::class);
        $breach->method('getSeverity')->willReturn('critical');
        $breach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(null);
        $breach->method('getDetectedAt')->willReturn(new DateTimeImmutable('-30 hours'));

        $this->assertFalse($this->rule->appliesTo($breach, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenAlreadyNotified(): void
    {
        $breach = $this->createMock(DataBreach::class);
        $breach->method('getSeverity')->willReturn('high');
        $breach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(new DateTimeImmutable('-10 hours'));

        $this->assertFalse($this->rule->appliesTo($breach, $this->user));
    }

    #[Test]
    public function doesNotApplyForLowSeverity(): void
    {
        $breach = $this->createMock(DataBreach::class);
        $breach->method('getSeverity')->willReturn('low');
        $breach->method('getSupervisoryAuthorityNotifiedAt')->willReturn(null);

        $this->assertFalse($this->rule->appliesTo($breach, $this->user));
    }

    #[Test]
    public function doesNotApplyToNonDataBreachEntity(): void
    {
        $this->assertFalse($this->rule->appliesTo(new \stdClass(), $this->user));
    }

    #[Test]
    public function buildReturnsNonDismissibleTier1Hint(): void
    {
        $breach = $this->createMock(DataBreach::class);
        $breach->method('getId')->willReturn(9);
        $breach->method('getDetectedAt')->willReturn(new DateTimeImmutable('-80 hours'));

        $hint = $this->rule->build($breach, $this->user);
        $this->assertSame('data_breach.notification_overdue', $hint->key);
        $this->assertSame(1, $hint->priorityTier);
        $this->assertFalse($hint->dismissible);
        $this->assertSame('danger', $hint->variant);
    }

    #[Test]
    public function moduleGateIsCompliance(): void
    {
        $this->assertSame(['compliance'], $this->rule->requiredModules());
    }
}
