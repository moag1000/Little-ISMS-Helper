<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\Consent\ExpiringSoonRule;
use App\Entity\Consent;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ExpiringSoonRuleTest extends TestCase
{
    private ExpiringSoonRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new ExpiringSoonRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesForActiveConsentExpiringWithinThirtyDays(): void
    {
        $consent = $this->buildConsent('active', false, new DateTimeImmutable('+15 days'));
        $this->assertTrue($this->rule->appliesTo($consent, $this->user));
    }

    #[Test]
    public function doesNotApplyForRevokedConsent(): void
    {
        $consent = $this->buildConsent('active', true, new DateTimeImmutable('+15 days'));
        $this->assertFalse($this->rule->appliesTo($consent, $this->user));
    }

    #[Test]
    public function doesNotApplyBeyondThirtyDayWindow(): void
    {
        $consent = $this->buildConsent('active', false, new DateTimeImmutable('+90 days'));
        $this->assertFalse($this->rule->appliesTo($consent, $this->user));
    }

    #[Test]
    public function doesNotApplyAlreadyExpired(): void
    {
        $consent = $this->buildConsent('active', false, new DateTimeImmutable('-1 day'));
        $this->assertFalse($this->rule->appliesTo($consent, $this->user));
    }

    private function buildConsent(string $status, bool $revoked, DateTimeImmutable $expires): Consent
    {
        $consent = new Consent();
        $consent->setStatus($status);
        $consent->setIsRevoked($revoked);
        $consent->setExpiresAt($expires);
        return $consent;
    }
}
