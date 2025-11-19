<?php

namespace App\Tests\Entity;

use App\Entity\MfaToken;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class MfaTokenTest extends TestCase
{
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

    public function testUserRelationship(): void
    {
        $token = new MfaToken();
        $user = new User();
        $user->setEmail('test@example.com');

        $token->setUser($user);

        $this->assertSame($user, $token->getUser());
    }

    public function testTokenType(): void
    {
        $token = new MfaToken();

        $token->setTokenType('totp');
        $this->assertEquals('totp', $token->getTokenType());

        $token->setTokenType('webauthn');
        $this->assertEquals('webauthn', $token->getTokenType());
    }

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

    public function testDeviceName(): void
    {
        $token = new MfaToken();

        $this->assertNull($token->getDeviceName());

        $token->setDeviceName('iPhone 13');
        $this->assertEquals('iPhone 13', $token->getDeviceName());
    }

    public function testTotpSecret(): void
    {
        $token = new MfaToken();

        $this->assertNull($token->getSecret());

        $token->setSecret('encrypted_totp_secret');
        $this->assertEquals('encrypted_totp_secret', $token->getSecret());
    }

    public function testBackupCodes(): void
    {
        $token = new MfaToken();

        $this->assertNull($token->getBackupCodes());

        $codes = ['hashed_code_1', 'hashed_code_2'];
        $token->setBackupCodes($codes);

        $this->assertEquals($codes, $token->getBackupCodes());
    }

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

    public function testIncrementCounter(): void
    {
        $token = new MfaToken();

        $this->assertEquals(0, $token->getCounter());

        $token->incrementCounter();
        $this->assertEquals(1, $token->getCounter());

        $token->incrementCounter();
        $this->assertEquals(2, $token->getCounter());
    }

    public function testPhoneNumber(): void
    {
        $token = new MfaToken();

        $this->assertNull($token->getPhoneNumber());

        $token->setPhoneNumber('+49123456789');
        $this->assertEquals('+49123456789', $token->getPhoneNumber());
    }

    public function testIsActiveAndSetIsActive(): void
    {
        $token = new MfaToken();

        $this->assertTrue($token->isActive()); // Default

        $token->setIsActive(false);
        $this->assertFalse($token->isActive());
    }

    public function testIsPrimaryAndSetIsPrimary(): void
    {
        $token = new MfaToken();

        $this->assertFalse($token->isPrimary()); // Default

        $token->setIsPrimary(true);
        $this->assertTrue($token->isPrimary());
    }

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

    public function testIsExpiredWhenNoExpirationSet(): void
    {
        $token = new MfaToken();

        $this->assertNull($token->getExpiresAt());
        $this->assertFalse($token->isExpired());
    }

    public function testIsExpiredWithFutureExpiration(): void
    {
        $token = new MfaToken();
        $futureDate = (new \DateTimeImmutable())->modify('+1 day');

        $token->setExpiresAt($futureDate);

        $this->assertFalse($token->isExpired());
    }

    public function testIsExpiredWithPastExpiration(): void
    {
        $token = new MfaToken();
        $pastDate = (new \DateTimeImmutable())->modify('-1 day');

        $token->setExpiresAt($pastDate);

        $this->assertTrue($token->isExpired());
    }

    public function testIsValidWhenActiveAndNotExpired(): void
    {
        $token = new MfaToken();
        $token->setIsActive(true);

        $this->assertTrue($token->isValid());
    }

    public function testIsValidWhenInactive(): void
    {
        $token = new MfaToken();
        $token->setIsActive(false);

        $this->assertFalse($token->isValid());
    }

    public function testIsValidWhenExpired(): void
    {
        $token = new MfaToken();
        $token->setIsActive(true);
        $pastDate = (new \DateTimeImmutable())->modify('-1 day');
        $token->setExpiresAt($pastDate);

        $this->assertFalse($token->isValid());
    }

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
