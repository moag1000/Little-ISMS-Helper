<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\BcpNoCrisisTeamInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BcpNoCrisisTeamInlineRuleTest extends TestCase
{
    private BcpNoCrisisTeamInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new BcpNoCrisisTeamInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsWhenBothFieldsExposedAndEmpty(): void
    {
        self::assertTrue($this->rule->supports([
            'responseTeamMembers' => '',
            'crisisTeams' => '',
        ], $this->user));
    }

    #[Test]
    public function supportsWhenOnlyOneFieldExposedAndEmpty(): void
    {
        self::assertTrue($this->rule->supports(['crisisTeams' => ''], $this->user));
        self::assertTrue($this->rule->supports(['responseTeamMembers' => null], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenResponseTeamMembersFilled(): void
    {
        self::assertFalse($this->rule->supports([
            'responseTeamMembers' => 'CISO, ISB, Legal',
            'crisisTeams' => '',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenCrisisTeamsHasEntries(): void
    {
        self::assertFalse($this->rule->supports([
            'responseTeamMembers' => '',
            'crisisTeams' => '42',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenNeitherFieldExposed(): void
    {
        self::assertFalse($this->rule->supports([], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnCrisisTeamsField(): void
    {
        $hint = $this->rule->evaluate(['crisisTeams' => ''], $this->user);

        self::assertSame('bcp.form.no_team_assigned', $hint->key);
        self::assertSame('crisisTeams', $hint->field);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('business_continuity_plan', $this->rule->entityType());
        self::assertSame(['bcm'], $this->rule->requiredModules());
    }
}
