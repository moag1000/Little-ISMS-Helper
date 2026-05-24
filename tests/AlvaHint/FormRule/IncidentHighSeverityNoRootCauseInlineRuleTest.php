<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\IncidentHighSeverityNoRootCauseInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IncidentHighSeverityNoRootCauseInlineRuleTest extends TestCase
{
    private IncidentHighSeverityNoRootCauseInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new IncidentHighSeverityNoRootCauseInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsHighSeverityClosingWithoutRootCause(): void
    {
        self::assertTrue($this->rule->supports([
            'severity' => 'high',
            'status' => 'resolved',
            'rootCause' => '',
        ], $this->user));
        self::assertTrue($this->rule->supports([
            'severity' => 'critical',
            'status' => 'closed',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportLowSeverity(): void
    {
        self::assertFalse($this->rule->supports([
            'severity' => 'low',
            'status' => 'closed',
            'rootCause' => '',
        ], $this->user));
        self::assertFalse($this->rule->supports([
            'severity' => 'medium',
            'status' => 'resolved',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportNonClosingStatuses(): void
    {
        self::assertFalse($this->rule->supports([
            'severity' => 'critical',
            'status' => 'in_investigation',
            'rootCause' => '',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenRootCausePopulated(): void
    {
        self::assertFalse($this->rule->supports([
            'severity' => 'high',
            'status' => 'closed',
            'rootCause' => 'Documented analysis here',
        ], $this->user));
    }

    #[Test]
    public function whitespaceOnlyRootCauseIsTreatedAsEmpty(): void
    {
        self::assertTrue($this->rule->supports([
            'severity' => 'critical',
            'status' => 'resolved',
            'rootCause' => '   ',
        ], $this->user));
    }

    #[Test]
    public function evaluateExposesSeverityAndStatusAsBodyParams(): void
    {
        $hint = $this->rule->evaluate([
            'severity' => 'critical',
            'status' => 'closed',
        ], $this->user);

        self::assertSame('rootCause', $hint->field);
        self::assertSame('critical', $hint->bodyParams['%severity%']);
        self::assertSame('closed', $hint->bodyParams['%status%']);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('incident', $this->rule->entityType());
        self::assertSame(['incidents'], $this->rule->requiredModules());
    }
}
