<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\FormRule;

use App\AlvaHint\FormRule\IncidentDataBreachInlineRule;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IncidentDataBreachInlineRuleTest extends TestCase
{
    private IncidentDataBreachInlineRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new IncidentDataBreachInlineRule();
        $this->user = new User();
    }

    #[Test]
    public function supportsWhenDataBreachOccurredIsBooleanTrue(): void
    {
        self::assertTrue($this->rule->supports(['dataBreachOccurred' => true], $this->user));
    }

    #[Test]
    public function supportsWhenDataBreachOccurredIsStringOne(): void
    {
        // Symfony form-submitted radios serialize as "1" / "0".
        self::assertTrue($this->rule->supports(['dataBreachOccurred' => '1'], $this->user));
    }

    #[Test]
    public function supportsWhenDataBreachOccurredIsIntegerOne(): void
    {
        self::assertTrue($this->rule->supports(['dataBreachOccurred' => 1], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenDataBreachOccurredFalse(): void
    {
        self::assertFalse($this->rule->supports(['dataBreachOccurred' => false], $this->user));
        self::assertFalse($this->rule->supports(['dataBreachOccurred' => '0'], $this->user));
        self::assertFalse($this->rule->supports(['dataBreachOccurred' => 0], $this->user));
    }

    #[Test]
    public function doesNotSupportWhenFieldMissing(): void
    {
        self::assertFalse($this->rule->supports([], $this->user));
        self::assertFalse($this->rule->supports(['title' => 'foo'], $this->user));
    }

    #[Test]
    public function evaluateProducesWarningHintAnchoredOnTriggerField(): void
    {
        $hint = $this->rule->evaluate(['dataBreachOccurred' => true], $this->user);

        self::assertSame('incident.form.data_breach_will_be_created', $hint->key);
        self::assertSame('dataBreachOccurred', $hint->field);
        self::assertSame('warning', $hint->tier);
        self::assertSame('alva_hint.form.incident_data_breach.title', $hint->titleTranslationKey);
        self::assertSame('alva_hint.form.incident_data_breach.body', $hint->bodyTranslationKey);
        self::assertSame('alva', $hint->translationDomain);
        self::assertSame('warning', $hint->mood);
    }

    #[Test]
    public function entityTypeIsIncident(): void
    {
        self::assertSame('incident', $this->rule->entityType());
    }

    #[Test]
    public function requiresIncidentsAndPrivacyModules(): void
    {
        // Mirrors the show-page RequiresDataBreachRule — privacy module
        // must be on for the hint to make sense (no DataBreach entity
        // exists without GDPR module).
        self::assertSame(['incidents', 'privacy'], $this->rule->requiredModules());
    }

    #[Test]
    public function requiresNoSpecificRole(): void
    {
        self::assertSame([], $this->rule->requiredRoles());
    }
}
