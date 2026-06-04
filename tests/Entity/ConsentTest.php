<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Consent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * GDPR Art. 7(3) — withdrawal consistency (audit finding M-1).
 */
class ConsentTest extends TestCase
{
    #[Test]
    public function testActiveConsentWithoutWithdrawalIsValid(): void
    {
        $consent = new Consent();
        $consent->setStatus('active');

        $this->assertTrue($consent->isValid());
        $this->assertFalse($consent->isRevoked());
        $this->assertFalse($consent->isWithdrawn());
    }

    #[Test]
    public function testRecordWithdrawalSetsBothFieldGroupsAtomically(): void
    {
        $consent = new Consent();
        $consent->setStatus('active');

        $consent->recordWithdrawal('email', 'No longer needed');

        // Revocation group
        $this->assertTrue($consent->isRevoked());
        $this->assertInstanceOf(DateTimeImmutable::class, $consent->getRevokedAt());
        $this->assertSame('email', $consent->getRevocationMethod());

        // Withdrawal group — same timestamp/channel, no divergence possible
        $this->assertTrue($consent->isWithdrawn());
        $this->assertSame($consent->getRevokedAt(), $consent->getWithdrawnAt());
        $this->assertSame('email', $consent->getWithdrawalChannel());
        $this->assertSame('No longer needed', $consent->getWithdrawalReason());

        // And it is no longer valid
        $this->assertFalse($consent->isValid());
    }

    #[Test]
    public function testIsValidReturnsFalseWhenOnlyWithdrawnAtSet(): void
    {
        // Defense-in-depth: a legacy row with only the withdrawal timestamp set
        // (and isRevoked still false) must NOT count as valid.
        $consent = new Consent();
        $consent->setStatus('active');
        $consent->setWithdrawnAt(new DateTimeImmutable());

        $this->assertFalse($consent->isRevoked());
        $this->assertTrue($consent->isWithdrawn());
        $this->assertFalse($consent->isValid());
    }
}
