<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MfaToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MfaTokenTest extends TestCase
{
    #[Test]
    public function testConstructor(): void
    {
        $token = new MfaToken();

        $this->assertNotNull($token->getCreatedAt());
        $this->assertNotNull($token->getEnrolledAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $token->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $token->getEnrolledAt());
        $this->assertTrue($token->isActive()); // Default active
        $this->assertFalse($token->isPrimary()); // Default not primary
        $this->assertEquals(0, $token->getUsageCount());
    }

    #[Test]
    public function testUserRelationship(): void
    {
        $token = new MfaToken();
        $user = new User();
        $user->setEmail('test@example.com');

        $token->setUser($user);

        $this->assertSame($user, $token->getUser());
    }

    #[Test]
    public function testTokenType(): void
    {
        $token = new MfaToken();

        $token->setTokenType('totp');
        $this->assertEquals('totp', $token->getTokenType());

        $token->setTokenType('webauthn');
        $this->assertEquals('webauthn', $token->getTokenType());
    }

    #[Test]
    public function testGetTokenTypeName(): void
    {
        $token = new MfaToken();

        $token->setTokenType('totp');
        $this->assertEquals('Authenticator App (TOTP)', $token->getTokenTypeName());

        $token->setTokenType('webauthn');
        $this->assertEquals('Security Key (WebAuthn/FIDO2)', $token->getTokenTypeName());

        $token->setTokenType('sms');
        $this->assertEquals('SMS Verification', $token->getTokenTypeName());

        $token->setTokenType('hardware');
        $this->assertEquals('Hardware Token', $token->getTokenTypeName());

        $token->setTokenType('backup');
        $this->assertEquals('Backup Codes', $token->getTokenTypeName());

        $token->setTokenType('unknown_type');
        $this->assertEquals('Unknown', $token->getTokenTypeName());
    }

    #[Test]
    public function testDeviceName(): void
    {
        $token = new MfaToken();

        $this->assertNull($token->getDeviceName());

        $token->setDeviceName('iPhone 13');
        $this->assertEquals('iPhone 13', $token->getDeviceName());
    }

    #[Test]
    public function testTotpSecret(): void
    {
        $token = new MfaToken();

        $this->assertNull($token->getSecret());

        $token->setSecret('encrypted_totp_secret');
        $this->assertEquals('encrypted_totp_secret', $token->getSecret());
    }

    #[Test]
    public function testBackupCodes(): void
    {
        $token = new MfaToken();

        $this->assertNull($token->getBackupCodes());

        $codes = ['hashed_code_1', 'hashed_code_2'];
        $token->setBackupCodes($codes);

        $this->assertEquals($codes, $token->getBackupCodes());
    }

    #[Test]
    public function testWebAuthnFields(): void
    {
        $token = new MfaToken();

        $token->setCredentialId('credential_123');
        $this->assertEquals('credential_123', $token->getCredentialId());

        $token->setPublicKey('public_key_data');
        $this->assertEquals('public_key_data', $token->getPublicKey());

        $this->assertEquals(0, $token->getCounter());

        $token->setCounter(5);
        $this->assertEquals(5, $token->getCounter());
    }

    #[Test]
    public function testIncrementCounter(): void
    {
        $token = new MfaToken();

        $this->assertEquals(0, $token->getCounter());

        $token->incrementCounter();
        $this->assertEquals(1, $token->getCounter());

        $token->incrementCounter();
        $this->assertEquals(2, $token->getCounter());
    }

    #[Test]
    public function testPhoneNumber(): void
    {
        $token = new MfaToken();

        $this->assertNull($token->getPhoneNumber());

        $token->setPhoneNumber('+49123456789');
        $this->assertEquals('+49123456789', $token->getPhoneNumber());
    }

    #[Test]
    public function testIsActiveAndSetIsActive(): void
    {
        $token = new MfaToken();

        $this->assertTrue($token->isActive()); // Default

        $token->setIsActive(false);
        $this->assertFalse($token->isActive());
    }

    #[Test]
    public function testIsPrimaryAndSetIsPrimary(): void
    {
        $token = new MfaToken();

        $this->assertFalse($token->isPrimary()); // Default

        $token->setIsPrimary(true);
        $this->assertTrue($token->isPrimary());
    }

    #[Test]
    public function testRecordUsage(): void
    {
        $token = new MfaToken();

        $this->assertEquals(0, $token->getUsageCount());
        $this->assertNull($token->getLastUsedAt());

        $token->recordUsage();

        $this->assertEquals(1, $token->getUsageCount());
        $this->assertNotNull($token->getLastUsedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $token->getLastUsedAt());

        $token->recordUsage();
        $this->assertEquals(2, $token->getUsageCount());
    }

    #[Test]
    public function testIsExpiredWhenNoExpirationSet(): void
    {
        $token = new MfaToken();

        $this->assertNull($token->getExpiresAt());
        $this->assertFalse($token->isExpired());
    }

    #[Test]
    public function testIsExpiredWithFutureExpiration(): void
    {
        $token = new MfaToken();
        $futureDate = (new \DateTimeImmutable())->modify('+1 day');

        $token->setExpiresAt($futureDate);

        $this->assertFalse($token->isExpired());
    }

    #[Test]
    public function testIsExpiredWithPastExpiration(): void
    {
        $token = new MfaToken();
        $pastDate = (new \DateTimeImmutable())->modify('-1 day');

        $token->setExpiresAt($pastDate);

        $this->assertTrue($token->isExpired());
    }

    #[Test]
    public function testIsValidWhenActiveAndNotExpired(): void
    {
        $token = new MfaToken();
        $token->setIsActive(true);

        $this->assertTrue($token->isValid());
    }

    #[Test]
    public function testIsValidWhenInactive(): void
    {
        $token = new MfaToken();
        $token->setIsActive(false);

        $this->assertFalse($token->isValid());
    }

    #[Test]
    public function testIsValidWhenExpired(): void
    {
        $token = new MfaToken();
        $token->setIsActive(true);
        $pastDate = (new \DateTimeImmutable())->modify('-1 day');
        $token->setExpiresAt($pastDate);

        $this->assertFalse($token->isValid());
    }

    #[Test]
    public function testTimestamps(): void
    {
        $token = new MfaToken();

        // createdAt set in constructor
        $this->assertNotNull($token->getCreatedAt());

        // enrolledAt set in constructor
        $this->assertNotNull($token->getEnrolledAt());

        // updatedAt initially null
        $this->assertNull($token->getUpdatedAt());

        $now = new \DateTimeImmutable();
        $token->setUpdatedAt($now);
        $this->assertEquals($now, $token->getUpdatedAt());
    }
}
