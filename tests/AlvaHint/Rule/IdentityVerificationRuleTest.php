<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint\Rule;

use App\AlvaHint\Rule\DataSubjectRequest\IdentityVerificationRule;
use App\Entity\DataSubjectRequest;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IdentityVerificationRuleTest extends TestCase
{
    private IdentityVerificationRule $rule;
    private User $user;

    protected function setUp(): void
    {
        $this->rule = new IdentityVerificationRule();
        $this->user = new User();
    }

    #[Test]
    public function appliesWhenMethodSetAndNotVerified(): void
    {
        $dsr = $this->buildDsr('email_link', false, 'received');
        $this->assertTrue($this->rule->appliesTo($dsr, $this->user));
    }

    #[Test]
    public function doesNotApplyWhenAlreadyVerified(): void
    {
        $dsr = $this->buildDsr('email_link', true, 'received');
        $this->assertFalse($this->rule->appliesTo($dsr, $this->user));
    }

    #[Test]
    public function doesNotApplyWithoutMethod(): void
    {
        $dsr = $this->buildDsr('', false, 'received');
        $this->assertFalse($this->rule->appliesTo($dsr, $this->user));
    }

    #[Test]
    public function doesNotApplyOnTerminalStatus(): void
    {
        $dsr = $this->buildDsr('email_link', false, 'completed');
        $this->assertFalse($this->rule->appliesTo($dsr, $this->user));
    }

    private function buildDsr(string $method, bool $verified, string $status): DataSubjectRequest
    {
        $dsr = new DataSubjectRequest();
        $dsr->setIdentityVerificationMethod($method === '' ? null : $method);
        $dsr->setIdentityVerified($verified);
        $dsr->setStatus($status);
        return $dsr;
    }
}
