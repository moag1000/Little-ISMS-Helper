<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\IncidentNis2EarlyWarningPendingInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IncidentNis2EarlyWarningPendingInlineRuleTest extends TestCase
{
    private IncidentNis2EarlyWarningPendingInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new IncidentNis2EarlyWarningPendingInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsHighSeverityWithEmptyEarlyWarning(): void
    {
        self::assertTrue($this->rule->supports([
            'severity' => 'high',
            'earlyWarningReportedAt' => '',
        ], $this->user));
    }

    #[Test]
    public function supportsCriticalSeverityWithEmptyEarlyWarning(): void
    {
        self::assertTrue($this->rule->supports([
            'severity' => 'critical',
            'earlyWarningReportedAt' => '',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportLowSeverity(): void
    {
        self::assertFalse($this->rule->supports([
            'severity' => 'low',
            'earlyWarningReportedAt' => '',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenEarlyWarningFilled(): void
    {
        self::assertFalse($this->rule->supports([
            'severity' => 'high',
            'earlyWarningReportedAt' => '2026-05-22T10:00',
        ], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenFieldNotInPayload(): void
    {
        // Form-section not yet exposed — silent.
        self::assertFalse($this->rule->supports(['severity' => 'high'], $this->user));
    }

    #[Test]
    public function evaluateAnchorsOnEarlyWarningField(): void
    {
        $hint = $this->rule->evaluate([
            'severity' => 'critical',
            'earlyWarningReportedAt' => '',
        ], $this->user);

        self::assertSame('incident.form.nis2_early_warning_pending', $hint->key);
        self::assertSame('earlyWarningReportedAt', $hint->field);
    }

    #[Test]
    public function entityTypeAndModulesAreCorrect(): void
    {
        self::assertSame('incident', $this->rule->entityType());
        self::assertSame(['incidents', 'nis2_dora'], $this->rule->requiredModules());
    }
}
