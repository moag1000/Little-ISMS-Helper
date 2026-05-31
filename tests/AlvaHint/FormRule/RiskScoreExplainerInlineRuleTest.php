<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\RiskScoreExplainerInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RiskScoreExplainerInlineRuleTest extends TestCase
{
    private RiskScoreExplainerInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new RiskScoreExplainerInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsWhenBothFieldsSetAndScoreBelowCritical(): void
    {
        // 3 * 5 = 15 (< 20 critical cutoff).
        self::assertTrue($this->rule->supports(['probability' => 3, 'impact' => 5], $this->user));
        // 1 * 1 = 1 (minimum).
        self::assertTrue($this->rule->supports(['probability' => 1, 'impact' => 1], $this->user));
    }

    #[Test]
    public function supportsAcceptsStringNumerics(): void
    {
        self::assertTrue($this->rule->supports(['probability' => '2', 'impact' => '4'], $this->user));
    }

    #[Test]
    public function standsDownAtOrAboveCriticalThreshold(): void
    {
        // 4 * 5 = 20 — owned by RiskCriticalSeverityInlineRule, no double hint.
        self::assertFalse($this->rule->supports(['probability' => 4, 'impact' => 5], $this->user));
        // 5 * 5 = 25.
        self::assertFalse($this->rule->supports(['probability' => 5, 'impact' => 5], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenEitherFieldMissingOrBlank(): void
    {
        self::assertFalse($this->rule->supports(['probability' => 3], $this->user));
        self::assertFalse($this->rule->supports(['impact' => 3], $this->user));
        self::assertFalse($this->rule->supports([], $this->user));
        self::assertFalse($this->rule->supports(['probability' => '', 'impact' => '3'], $this->user));
        self::assertFalse($this->rule->supports(['probability' => '3', 'impact' => ''], $this->user));
    }

    #[Test]
    public function evaluateEmitsInfoTierHintOnImpactFieldWithScoreParams(): void
    {
        $hint = $this->rule->evaluate(['probability' => 3, 'impact' => 5], $this->user);

        self::assertSame('risk.form.score_explainer', $hint->key);
        self::assertSame('impact', $hint->field);
        self::assertSame('info', $hint->tier);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame(3, $hint->bodyParams['%probability%']);
        self::assertSame(5, $hint->bodyParams['%impact%']);
        self::assertSame(15, $hint->bodyParams['%score%']);
    }

    #[Test]
    public function metadataMatchesRiskFormContract(): void
    {
        self::assertSame('risk', $this->rule->entityType());
        self::assertSame(['risks'], $this->rule->requiredModules());
        self::assertSame([], $this->rule->requiredRoles());
    }
}
