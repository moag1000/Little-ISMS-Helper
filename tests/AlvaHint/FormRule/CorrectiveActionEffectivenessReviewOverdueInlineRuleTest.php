<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\CorrectiveActionEffectivenessReviewOverdueInlineRule;
use App\Entity\CorrectiveAction;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CorrectiveActionEffectivenessReviewOverdueInlineRuleTest extends TestCase
{
    private CorrectiveActionEffectivenessReviewOverdueInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new CorrectiveActionEffectivenessReviewOverdueInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsOverdueReviewWithUnverifiedStatus(): void
    {
        $yesterday = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P1D'))->format('Y-m-d');
        self::assertTrue($this->rule->supports([
            'status' => 'planned',
            'effectivenessReviewDate' => $yesterday,
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenStatusIsVerified(): void
    {
        $yesterday = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P1D'))->format('Y-m-d');
        self::assertFalse($this->rule->supports([
            'status' => CorrectiveAction::STATUS_VERIFIED,
            'effectivenessReviewDate' => $yesterday,
        ], $this->user));
        self::assertFalse($this->rule->supports([
            'status' => CorrectiveAction::STATUS_VERIFIED_EFFECTIVE,
            'effectivenessReviewDate' => $yesterday,
        ], $this->user));
        self::assertFalse($this->rule->supports([
            'status' => CorrectiveAction::STATUS_VERIFIED_INEFFECTIVE,
            'effectivenessReviewDate' => $yesterday,
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportFutureReviewDate(): void
    {
        $tomorrow = (new \DateTimeImmutable('today'))->add(new \DateInterval('P1D'))->format('Y-m-d');
        self::assertFalse($this->rule->supports([
            'status' => 'planned',
            'effectivenessReviewDate' => $tomorrow,
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenFieldsMissing(): void
    {
        self::assertFalse($this->rule->supports([], $this->user));
        self::assertFalse($this->rule->supports(['status' => 'planned'], $this->user));
        self::assertFalse($this->rule->supports([
            'status' => 'planned',
            'effectivenessReviewDate' => '',
        ], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnReviewDateField(): void
    {
        $yesterday = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P1D'))->format('Y-m-d');
        $hint = $this->rule->evaluate([
            'status' => 'planned',
            'effectivenessReviewDate' => $yesterday,
        ], $this->user);

        self::assertSame('corrective_action.form.effectiveness_review_overdue', $hint->key);
        self::assertSame('effectivenessReviewDate', $hint->field);
    }

    #[Test]
    public function entityTypeIsCorrectiveActionWithNoModuleGate(): void
    {
        self::assertSame('corrective_action', $this->rule->entityType());
        self::assertSame([], $this->rule->requiredModules());
    }
}
