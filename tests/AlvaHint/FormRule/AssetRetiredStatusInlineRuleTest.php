<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\AssetRetiredStatusInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AssetRetiredStatusInlineRuleTest extends TestCase
{
    private AssetRetiredStatusInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new AssetRetiredStatusInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsRetiredStatus(): void
    {
        self::assertTrue($this->rule->supports(['status' => 'retired'], $this->user));
    }

    #[Test]
    public function supportsDisposedStatus(): void
    {
        self::assertTrue($this->rule->supports(['status' => 'disposed'], $this->user));
    }

    #[Test]
    public function doesNotSupportActiveOrInUse(): void
    {
        self::assertFalse($this->rule->supports(['status' => 'active'], $this->user));
        self::assertFalse($this->rule->supports(['status' => 'in_use'], $this->user));
        self::assertFalse($this->rule->supports(['status' => 'draft'], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenStatusMissingOrEmpty(): void
    {
        self::assertFalse($this->rule->supports([], $this->user));
        self::assertFalse($this->rule->supports(['status' => ''], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnStatusField(): void
    {
        $hint = $this->rule->evaluate(['status' => 'retired'], $this->user);

        self::assertSame('asset.form.retired_needs_disposal_evidence', $hint->key);
        self::assertSame('status', $hint->field);
        self::assertSame('warning', $hint->tier);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('asset', $this->rule->entityType());
        self::assertSame(['assets'], $this->rule->requiredModules());
    }
}
