<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\RiskAcceptanceWithoutJustificationInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RiskAcceptanceWithoutJustificationInlineRuleTest extends TestCase
{
    private RiskAcceptanceWithoutJustificationInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new RiskAcceptanceWithoutJustificationInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsAcceptStrategyWithoutJustification(): void
    {
        self::assertTrue($this->rule->supports(['treatmentStrategy' => 'accept'], $this->user));
        self::assertTrue($this->rule->supports([
            'treatmentStrategy' => 'accept',
            'acceptanceJustification' => '',
        ], $this->user));
        self::assertTrue($this->rule->supports([
            'treatmentStrategy' => 'accept',
            'acceptanceJustification' => '   ',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportNonAcceptStrategy(): void
    {
        self::assertFalse($this->rule->supports([
            'treatmentStrategy' => 'mitigate',
            'acceptanceJustification' => '',
        ], $this->user));
        self::assertFalse($this->rule->supports(['treatmentStrategy' => 'transfer'], $this->user));
        self::assertFalse($this->rule->supports(['treatmentStrategy' => 'avoid'], $this->user));
    }

    #[Test]
    public function doesNotSupportAcceptWithJustification(): void
    {
        self::assertFalse($this->rule->supports([
            'treatmentStrategy' => 'accept',
            'acceptanceJustification' => 'Within risk appetite per CISO sign-off 2026-05',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenStrategyMissing(): void
    {
        self::assertFalse($this->rule->supports([], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnJustificationField(): void
    {
        $hint = $this->rule->evaluate(['treatmentStrategy' => 'accept'], $this->user);

        self::assertSame('risk.form.acceptance_without_justification', $hint->key);
        self::assertSame('acceptanceJustification', $hint->field);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('risk', $this->rule->entityType());
        self::assertSame(['risks'], $this->rule->requiredModules());
    }
}
