<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\CrisisTeam\TrainingOverdueRule;
use App\Entity\CrisisTeam;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TrainingOverdueRuleTest extends TestCase
{
    private TrainingOverdueRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new TrainingOverdueRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenEntityReportsOverdue(): void
    {
        $team = $this->createMock(CrisisTeam::class);
        $team->method('isTrainingOverdue')->willReturn(true);
        $this->assertTrue($this->rule->appliesTo($team, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenEntityReportsCurrent(): void
    {
        $team = $this->createMock(CrisisTeam::class);
        $team->method('isTrainingOverdue')->willReturn(false);
        $this->assertFalse($this->rule->appliesTo($team, $this->user));
    }
}
