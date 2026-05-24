<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\BcpStaleExerciseInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BcpStaleExerciseInlineRuleTest extends TestCase
{
    private BcpStaleExerciseInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new BcpStaleExerciseInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsWhenLastTestedIsEmpty(): void
    {
        self::assertTrue($this->rule->supports(['lastTested' => ''], $this->user));
        self::assertTrue($this->rule->supports(['lastTested' => null], $this->user));
    }

    #[Test]
    public function supportsWhenLastTestedOlderThan12Months(): void
    {
        $thirteenMonthsAgo = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P400D'))->format('Y-m-d');
        self::assertTrue($this->rule->supports(['lastTested' => $thirteenMonthsAgo], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenLastTestedRecent(): void
    {
        $threeMonthsAgo = (new \DateTimeImmutable('today'))->sub(new \DateInterval('P90D'))->format('Y-m-d');
        self::assertFalse($this->rule->supports(['lastTested' => $threeMonthsAgo], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenFieldMissingFromPayload(): void
    {
        // Form may simply not expose the field — stay quiet.
        self::assertFalse($this->rule->supports([], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenDateUnparseable(): void
    {
        self::assertFalse($this->rule->supports(['lastTested' => 'definitely-not-a-date'], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnLastTestedField(): void
    {
        $hint = $this->rule->evaluate(['lastTested' => null], $this->user);

        self::assertSame('bcp.form.last_tested_stale_or_missing', $hint->key);
        self::assertSame('lastTested', $hint->field);
        self::assertSame('warning', $hint->tier);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('business_continuity_plan', $this->rule->entityType());
        self::assertSame(['bcm'], $this->rule->requiredModules());
    }
}
