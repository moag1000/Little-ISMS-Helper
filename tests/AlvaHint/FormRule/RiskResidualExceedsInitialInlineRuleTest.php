<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\RiskResidualExceedsInitialInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RiskResidualExceedsInitialInlineRuleTest extends TestCase
{
    private RiskResidualExceedsInitialInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new RiskResidualExceedsInitialInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsWhenResidualGreaterThanInitial(): void
    {
        // Initial 3*3=9; Residual 4*4=16 → fires.
        self::assertTrue($this->rule->supports([
            'probability' => 3,
            'impact' => 3,
            'residualProbability' => 4,
            'residualImpact' => 4,
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenResidualEqualsInitial(): void
    {
        self::assertFalse($this->rule->supports([
            'probability' => 3,
            'impact' => 3,
            'residualProbability' => 3,
            'residualImpact' => 3,
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenResidualLowerThanInitial(): void
    {
        self::assertFalse($this->rule->supports([
            'probability' => 4,
            'impact' => 4,
            'residualProbability' => 2,
            'residualImpact' => 2,
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenAnyFieldMissing(): void
    {
        self::assertFalse($this->rule->supports([
            'probability' => 3,
            'impact' => 3,
            'residualProbability' => 4,
        ], $this->user));
        self::assertFalse($this->rule->supports([
            'residualProbability' => 4,
            'residualImpact' => 4,
        ], $this->user));
        self::assertFalse($this->rule->supports([], $this->user));
    }

    #[Test]
    public function evaluateExposesScoresAsBodyParams(): void
    {
        $hint = $this->rule->evaluate([
            'probability' => 3,
            'impact' => 3,
            'residualProbability' => 4,
            'residualImpact' => 5,
        ], $this->user);

        self::assertSame('risk.form.residual_exceeds_initial', $hint->key);
        self::assertSame('residualImpact', $hint->field);
        self::assertSame(['%initial%' => 9, '%residual%' => 20], $hint->bodyParams);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('risk', $this->rule->entityType());
        self::assertSame(['risks'], $this->rule->requiredModules());
    }
}
