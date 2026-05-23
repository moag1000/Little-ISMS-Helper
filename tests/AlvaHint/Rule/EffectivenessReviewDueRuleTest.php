<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\CorrectiveAction\EffectivenessReviewDueRule;
use App\Entity\CorrectiveAction;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Junior-ISB-Audit C4-03 — Tier-2 reminder when the effectiveness review
 * is due within 7 days and the CAPA is still in `completed`
 * (ISO 27001 Cl. 10.1 d + Cl. 9.1.1).
 */
class EffectivenessReviewDueRuleTest extends TestCase
{
    #[Test]
    public function appliesOnlyToCorrectiveActionEntities(): void
    {
        $rule = new EffectivenessReviewDueRule();
        $this->assertFalse($rule->appliesTo(new \stdClass(), new User()));
    }

    #[Test]
    public function doesNotApplyWithoutReviewDate(): void
    {
        $rule = new EffectivenessReviewDueRule();
        $capa = new CorrectiveAction();
        $capa->setStatus(CorrectiveAction::STATUS_COMPLETED);

        $this->assertFalse($rule->appliesTo($capa, new User()));
    }

    #[Test]
    public function doesNotApplyWhenReviewDateFarInFuture(): void
    {
        $rule = new EffectivenessReviewDueRule();
        $capa = new CorrectiveAction();
        $capa->setStatus(CorrectiveAction::STATUS_COMPLETED);
        $capa->setEffectivenessReviewDate(new DateTimeImmutable('+30 days'));

        $this->assertFalse($rule->appliesTo($capa, new User()));
    }

    #[Test]
    public function doesNotApplyWhenAlreadyVerifiedEffective(): void
    {
        $rule = new EffectivenessReviewDueRule();
        $capa = new CorrectiveAction();
        $capa->setStatus(CorrectiveAction::STATUS_VERIFIED_EFFECTIVE);
        $capa->setEffectivenessReviewDate(new DateTimeImmutable('+2 days'));

        $this->assertFalse($rule->appliesTo($capa, new User()));
    }

    #[Test]
    public function doesNotApplyWhenAlreadyVerifiedIneffective(): void
    {
        $rule = new EffectivenessReviewDueRule();
        $capa = new CorrectiveAction();
        $capa->setStatus(CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE);
        $capa->setEffectivenessReviewDate(new DateTimeImmutable('+2 days'));

        $this->assertFalse($rule->appliesTo($capa, new User()));
    }

    #[Test]
    public function appliesWhenReviewDateWithinSevenDaysAndStatusCompleted(): void
    {
        $rule = new EffectivenessReviewDueRule();
        $capa = new CorrectiveAction();
        $capa->setStatus(CorrectiveAction::STATUS_COMPLETED);
        $capa->setEffectivenessReviewDate(new DateTimeImmutable('+3 days'));

        $this->assertTrue($rule->appliesTo($capa, new User()));
    }

    #[Test]
    public function appliesWhenReviewDateAlreadyPast(): void
    {
        $rule = new EffectivenessReviewDueRule();
        $capa = new CorrectiveAction();
        $capa->setStatus(CorrectiveAction::STATUS_COMPLETED);
        $capa->setEffectivenessReviewDate(new DateTimeImmutable('-1 day'));

        // Past dates fall inside the "within +7 days" window
        // because they satisfy `<= now()+7d`.
        $this->assertTrue($rule->appliesTo($capa, new User()));
    }

    #[Test]
    public function buildsTier2WarningHintWithEditCta(): void
    {
        $rule = new EffectivenessReviewDueRule();
        $capa = new CorrectiveAction();
        $idProp = (new \ReflectionClass($capa))->getProperty('id');
        $idProp->setValue($capa, 17);
        $capa->setStatus(CorrectiveAction::STATUS_COMPLETED);
        $capa->setTitle('Logging gap remediation');
        $capa->setEffectivenessReviewDate(new DateTimeImmutable('+2 days'));

        $hint = $rule->build($capa, new User());

        $this->assertSame(2, $hint->priorityTier);
        $this->assertTrue($hint->dismissible);
        $this->assertSame('warning', $hint->variant);
        $this->assertSame('app_corrective_action_edit', $hint->actionRoute);
        $this->assertSame(['id' => 17], $hint->actionRouteParams);
        $this->assertSame('GET', $hint->actionMethod);
        $this->assertContains('ROLE_MANAGER', $hint->requiredRoles);
        $this->assertArrayHasKey('%daysUntil%', $hint->bodyTranslationParams);
        $this->assertArrayHasKey('%date%', $hint->bodyTranslationParams);
        $this->assertArrayHasKey('%title%', $hint->bodyTranslationParams);
    }

    #[Test]
    public function ruleRequiresAuditsModule(): void
    {
        $rule = new EffectivenessReviewDueRule();
        $this->assertSame(['audits'], $rule->requiredModules());
    }
}
