<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Patch\DowntimeNeedsChangeRequestRule;
use App\Entity\Patch;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DowntimeNeedsChangeRequestRuleTest extends TestCase
{
    private DowntimeNeedsChangeRequestRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new DowntimeNeedsChangeRequestRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForCriticalDowntimePatchDueSoon(): void
    {
        $patch = $this->buildPatch('critical', true, new DateTimeImmutable('+3 days'));
        $this->assertTrue($this->rule->appliesTo($patch, $this->user));
    }

    #[Test]
    public function doesNotApplyForLowPriority(): void
    {
        $patch = $this->buildPatch('medium', true, new DateTimeImmutable('+3 days'));
        $this->assertFalse($this->rule->appliesTo($patch, $this->user));
    }

    #[Test]
    public function doesNotApplyWithoutDowntime(): void
    {
        $patch = $this->buildPatch('critical', false, new DateTimeImmutable('+3 days'));
        $this->assertFalse($this->rule->appliesTo($patch, $this->user));
    }

    #[Test]
    public function doesNotApplyOutsideSevenDayWindow(): void
    {
        $patch = $this->buildPatch('critical', true, new DateTimeImmutable('+30 days'));
        $this->assertFalse($this->rule->appliesTo($patch, $this->user));
    }

    private function buildPatch(string $priority, bool $downtime, DateTimeImmutable $deadline): Patch
    {
        $patch = new Patch();
        $patch->setPriority($priority);
        $patch->setRequiresDowntime($downtime);
        $patch->setDeploymentDeadline($deadline);
        return $patch;
    }
}
