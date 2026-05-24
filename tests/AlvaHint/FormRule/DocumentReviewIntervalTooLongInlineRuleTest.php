<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\DocumentReviewIntervalTooLongInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentReviewIntervalTooLongInlineRuleTest extends TestCase
{
    private DocumentReviewIntervalTooLongInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new DocumentReviewIntervalTooLongInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsIntervalAbove24Months(): void
    {
        self::assertTrue($this->rule->supports(['reviewIntervalMonths' => 36], $this->user));
        self::assertTrue($this->rule->supports(['reviewIntervalMonths' => '60'], $this->user));
    }

    #[Test]
    public function doesNotSupportIntervalAtBoundary(): void
    {
        // 24 months exactly is still acceptable.
        self::assertFalse($this->rule->supports(['reviewIntervalMonths' => 24], $this->user));
    }

    #[Test]
    public function doesNotSupportAnnualOrShorterIntervals(): void
    {
        self::assertFalse($this->rule->supports(['reviewIntervalMonths' => 12], $this->user));
        self::assertFalse($this->rule->supports(['reviewIntervalMonths' => 6], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenFieldMissingOrInvalid(): void
    {
        self::assertFalse($this->rule->supports([], $this->user));
        self::assertFalse($this->rule->supports(['reviewIntervalMonths' => 0], $this->user));
        self::assertFalse($this->rule->supports(['reviewIntervalMonths' => ''], $this->user));
    }

    #[Test]
    public function evaluateExposesMonthsAsBodyParam(): void
    {
        $hint = $this->rule->evaluate(['reviewIntervalMonths' => 48], $this->user);

        self::assertSame('document.form.review_interval_too_long', $hint->key);
        self::assertSame('reviewIntervalMonths', $hint->field);
        self::assertSame(['%months%' => 48], $hint->bodyParams);
    }

    #[Test]
    public function entityTypeIsDocumentWithNoModuleGate(): void
    {
        self::assertSame('document', $this->rule->entityType());
        self::assertSame([], $this->rule->requiredModules());
    }
}
