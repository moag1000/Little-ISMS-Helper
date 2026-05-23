<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\RiskCriticalSeverityInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RiskCriticalSeverityInlineRuleTest extends TestCase
{
    private RiskCriticalSeverityInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new RiskCriticalSeverityInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsWhenScoreReachesCriticalThreshold(): void
    {
        // 4 * 5 = 20 (threshold).
        self::assertTrue($this->rule->supports(['probability' => 4, 'impact' => 5], $this->user));
        // 5 * 5 = 25 (above threshold).
        self::assertTrue($this->rule->supports(['probability' => 5, 'impact' => 5], $this->user));
    }

    #[Test]
    public function supportsAcceptsStringNumerics(): void
    {
        // Symfony IntegerType field values arrive as strings via HTTP POST.
        self::assertTrue($this->rule->supports(['probability' => '4', 'impact' => '5'], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenScoreBelowThreshold(): void
    {
        // 3 * 5 = 15
        self::assertFalse($this->rule->supports(['probability' => 3, 'impact' => 5], $this->user));
        // 3 * 3 = 9
        self::assertFalse($this->rule->supports(['probability' => 3, 'impact' => 3], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenEitherFieldMissing(): void
    {
        self::assertFalse($this->rule->supports(['probability' => 5], $this->user));
        self::assertFalse($this->rule->supports(['impact' => 5], $this->user));
        self::assertFalse($this->rule->supports([], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenFieldsBlank(): void
    {
        self::assertFalse($this->rule->supports(['probability' => '', 'impact' => '5'], $this->user));
        self::assertFalse($this->rule->supports(['probability' => '5', 'impact' => ''], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnImpactFieldAndIncludesScoreParam(): void
    {
        $hint = $this->rule->evaluate(['probability' => 5, 'impact' => 5], $this->user);

        self::assertSame('risk.form.critical_severity_needs_board_approval', $hint->key);
        self::assertSame('impact', $hint->field);
        self::assertSame('warning', $hint->tier);
        self::assertSame('alva_hint.form.risk_critical_severity.title', $hint->titleTranslationKey);
        self::assertSame('alva_hint.form.risk_critical_severity.body', $hint->bodyTranslationKey);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame(['%score%' => 25], $hint->bodyParams);
    }

    #[Test]
    public function entityTypeIsRisk(): void
    {
        self::assertSame('risk', $this->rule->entityType());
    }

    #[Test]
    public function requiresRisksModule(): void
    {
        self::assertSame(['risks'], $this->rule->requiredModules());
    }
}
