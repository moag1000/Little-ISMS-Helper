<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\CorrectiveAction\IneffectiveFollowUpRequiredRule;
use App\Entity\CorrectiveAction;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S3 P0-30 — Tier-1 hint fires when CAPA is verified_ineffective.
 */
class IneffectiveFollowUpRequiredRuleTest extends TestCase
{
    #[Test]
    public function appliesOnlyToCorrectiveActionEntities(): void
    {
        $rule = new IneffectiveFollowUpRequiredRule();
        $this->assertFalse($rule->appliesTo(new \stdClass(), new User()));
    }

    #[Test]
    public function doesNotApplyWhenCapaStillPlanned(): void
    {
        $rule = new IneffectiveFollowUpRequiredRule();
        $capa = new CorrectiveAction();
        $capa->setStatus(CorrectiveAction::STATUS_PLANNED);

        $this->assertFalse($rule->appliesTo($capa, new User()));
    }

    #[Test]
    public function doesNotApplyWhenCapaVerifiedEffective(): void
    {
        $rule = new IneffectiveFollowUpRequiredRule();
        $capa = new CorrectiveAction();
        $capa->setStatus(CorrectiveAction::STATUS_VERIFIED_EFFECTIVE);

        $this->assertFalse($rule->appliesTo($capa, new User()));
    }

    #[Test]
    public function appliesWhenCapaVerifiedIneffective(): void
    {
        $rule = new IneffectiveFollowUpRequiredRule();
        $capa = new CorrectiveAction();
        $capa->setStatus(CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE);
        $capa->setTitle('Original CAPA');

        $this->assertTrue($rule->appliesTo($capa, new User()));
    }

    #[Test]
    public function buildsTier1NonDismissibleDangerHint(): void
    {
        $rule = new IneffectiveFollowUpRequiredRule();
        $capa = new CorrectiveAction();
        $capa->setStatus(CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE);
        $capa->setTitle('Failed CAPA');

        $hint = $rule->build($capa, new User());

        $this->assertSame(1, $hint->priorityTier);
        $this->assertFalse($hint->dismissible);
        $this->assertSame('danger', $hint->variant);
        $this->assertSame('app_corrective_action_new', $hint->actionRoute);
        $this->assertArrayHasKey('from_ineffective_capa', $hint->actionRouteParams);
        $this->assertSame('GET', $hint->actionMethod);
        $this->assertContains('ROLE_MANAGER', $hint->requiredRoles);
    }

    #[Test]
    public function ruleRequiresAuditsModule(): void
    {
        $rule = new IneffectiveFollowUpRequiredRule();
        $this->assertSame(['audits'], $rule->requiredModules());
    }
}
