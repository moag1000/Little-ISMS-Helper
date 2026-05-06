<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\ProcessingActivity\DpiaRequiredRule;
use App\Entity\ProcessingActivity;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DpiaRequiredRuleTest extends TestCase
{
    private DpiaRequiredRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new DpiaRequiredRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenAutomatedDecisionMakingAndNoDpia(): void
    {
        $pa = new ProcessingActivity();
        $pa->setHasAutomatedDecisionMaking(true);
        $pa->setDpiaCompleted(false);

        $this->assertTrue($this->rule->appliesTo($pa, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenDpiaCompleted(): void
    {
        $pa = new ProcessingActivity();
        $pa->setHasAutomatedDecisionMaking(true);
        $pa->setDpiaCompleted(true);

        $this->assertFalse($this->rule->appliesTo($pa, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenDpiaNotRequired(): void
    {
        $pa = new ProcessingActivity();
        $pa->setHasAutomatedDecisionMaking(false);
        $pa->setProcessesSpecialCategories(false);
        $pa->setIsHighRisk(false);
        $pa->setDpiaCompleted(false);

        $this->assertFalse($this->rule->appliesTo($pa, $this->user));
    }
}
