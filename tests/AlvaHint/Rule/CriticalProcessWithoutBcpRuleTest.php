<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\BusinessProcess\CriticalProcessWithoutBcpRule;
use App\Entity\BusinessProcess;
use App\Entity\User;
use App\Repository\BusinessContinuityPlanRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CriticalProcessWithoutBcpRuleTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User();
    }

    #[Test]
    public function appliesForCriticalProcessWithoutBcp(): void
    {
        $process = new BusinessProcess();
        $process->setCriticality('critical');

        $repo = $this->createMock(BusinessContinuityPlanRepository::class);
        $repo->method('findBy')->willReturn([]);

        $rule = new CriticalProcessWithoutBcpRule($repo);
        $this->assertTrue($rule->appliesTo($process, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenBcpExists(): void
    {
        $process = new BusinessProcess();
        $process->setCriticality('high');

        $repo = $this->createMock(BusinessContinuityPlanRepository::class);
        $repo->method('findBy')->willReturn([new \stdClass()]);

        $rule = new CriticalProcessWithoutBcpRule($repo);
        $this->assertFalse($rule->appliesTo($process, $this->user));
    }

    #[Test]
    public function doesNotApplyForLowCriticality(): void
    {
        $process = new BusinessProcess();
        $process->setCriticality('low');

        $repo = $this->createMock(BusinessContinuityPlanRepository::class);

        $rule = new CriticalProcessWithoutBcpRule($repo);
        $this->assertFalse($rule->appliesTo($process, $this->user));
    }
}
