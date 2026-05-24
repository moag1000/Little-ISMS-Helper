<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\AssetCriticalConfidentialityInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetCriticalConfidentialityInlineRuleTest extends TestCase
{
    private AssetCriticalConfidentialityInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new AssetCriticalConfidentialityInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsWhenConfidentialityValueIsFive(): void
    {
        self::assertTrue($this->rule->supports(['confidentialityValue' => 5], $this->user));
        self::assertTrue($this->rule->supports(['confidentialityValue' => '5'], $this->user));
    }

    #[Test]
    public function doesNotSupportBelowCriticalThreshold(): void
    {
        self::assertFalse($this->rule->supports(['confidentialityValue' => 4], $this->user));
        self::assertFalse($this->rule->supports(['confidentialityValue' => 1], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenFieldMissingOrEmpty(): void
    {
        self::assertFalse($this->rule->supports([], $this->user));
        self::assertFalse($this->rule->supports(['confidentialityValue' => ''], $this->user));
        self::assertFalse($this->rule->supports(['confidentialityValue' => null], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnConfidentialityValueField(): void
    {
        $hint = $this->rule->evaluate(['confidentialityValue' => 5], $this->user);

        self::assertSame('asset.form.critical_confidentiality_needs_mfa', $hint->key);
        self::assertSame('confidentialityValue', $hint->field);
        self::assertSame('warning', $hint->tier);
        self::assertSame('alva_hint.form.asset_critical_confidentiality.title', $hint->titleTranslationKey);
        self::assertSame('alva', $hint->translationDomain);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('asset', $this->rule->entityType());
        self::assertSame(['assets'], $this->rule->requiredModules());
        self::assertSame([], $this->rule->requiredRoles());
    }
}
