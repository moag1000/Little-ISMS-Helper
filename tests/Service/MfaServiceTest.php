<?php

namespace App\Tests\Service;

use App\Entity\MfaToken;
use App\Entity\User;
use App\Repository\MfaTokenRepository;
use App\Service\AuditLogger;
use App\Service\MfaService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\TooManyRequestsException;

class MfaServiceTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $mfaTokenRepository;
    private MockObject $auditLogger;
    private MockObject $logger;
    private MfaService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->mfaTokenRepository = $this->createMock(MfaTokenRepository::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new MfaService(
            $this->entityManager,
            $this->mfaTokenRepository,
            $this->auditLogger,
            $this->logger,
            'Test ISMS'
        );
    }

    public function testGenerateTotpSecretCreatesToken(): void
    {
        $user = $this->createUser(1, 'user@example.com');

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(MfaToken::class));

        $this->entityManager->expects($this->once())->method('flush');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('TOTP secret generated', $this->anything());

        $token = $this->service->generateTotpSecret($user, 'My Phone');

        $this->assertInstanceOf(MfaToken::class, $token);
        $this->assertSame($user, $token->getUser());
        $this->assertSame('totp', $token->getTokenType());
        $this->assertSame('My Phone', $token->getDeviceName());
        $this->assertFalse($token->isActive());
        $this->assertNotEmpty($token->getSecret());
        $this->assertCount(10, $token->getBackupCodes());
        $this->assertCount(10, $token->temporaryBackupCodes);
    }

    public function testGenerateTotpSecretWithDefaultDeviceName(): void
    {
        $user = $this->createUser(1, 'user@example.com');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $token = $this->service->generateTotpSecret($user);

        $this->assertSame('Authenticator App', $token->getDeviceName());
    }

    public function testGenerateTotpSecretBackupCodesAreHashed(): void
    {
        $user = $this->createUser(1, 'user@example.com');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $token = $this->service->generateTotpSecret($user);

        $hashedCodes = $token->getBackupCodes();
        $plainCodes = $token->temporaryBackupCodes;

        // Verify hashed codes are different from plain codes
        foreach ($hashedCodes as $index => $hashedCode) {
            $this->assertNotSame($plainCodes[$index], $hashedCode);
            $this->assertTrue(password_verify($plainCodes[$index], $hashedCode));
        }
    }

    public function testGenerateTotpSecretBackupCodeFormat(): void
    {
        $user = $this->createUser(1, 'user@example.com');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $token = $this->service->generateTotpSecret($user);

        foreach ($token->temporaryBackupCodes as $code) {
            // Format: XXXX-XXXX
            $this->assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}$/', $code);
            // Should not contain ambiguous characters I and O
            $this->assertStringNotContainsString('I', $code);
            $this->assertStringNotContainsString('O', $code);
        }
    }

    public function testGenerateQrCodeForTotpToken(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $token = $this->createMfaToken(1, $user, 'totp', 'JBSWY3DPEHPK3PXP');

        $qrCode = $this->service->generateQrCode($token);

        $this->assertNotEmpty($qrCode);
        // QR code should be base64 encoded PNG
        $decoded = base64_decode($qrCode, true);
        $this->assertNotFalse($decoded);
        // PNG magic bytes
        $this->assertStringStartsWith("\x89PNG", $decoded);
    }

    public function testGenerateQrCodeThrowsForNonTotpToken(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $token = $this->createMfaToken(1, $user, 'webauthn', 'secret');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('QR codes can only be generated for TOTP tokens');

        $this->service->generateQrCode($token);
    }

    public function testVerifyTotpThrowsForNonTotpToken(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $token = $this->createMfaToken(1, $user, 'backup_code', 'secret');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Can only verify TOTP tokens');

        $this->service->verifyTotp($token, '123456');
    }

    public function testVerifyTotpRateLimiting(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $token = $this->createMfaToken(1, $user, 'totp', 'JBSWY3DPEHPK3PXP');

        // Last used 1 second ago (should trigger rate limit)
        $lastUsed = new \DateTimeImmutable('-1 second');
        $token->method('getLastUsedAt')->willReturn($lastUsed);

        $this->expectException(TooManyRequestsException::class);

        $this->service->verifyTotp($token, '123456');
    }

    public function testVerifyTotpNoRateLimitAfterSufficientTime(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $token = $this->createMfaToken(1, $user, 'totp', 'JBSWY3DPEHPK3PXP');

        // Last used 5 seconds ago (should not trigger rate limit)
        $lastUsed = new \DateTimeImmutable('-5 seconds');
        $token->method('getLastUsedAt')->willReturn($lastUsed);

        // Invalid code but should not rate limit
        $this->logger->expects($this->once())->method('warning');

        $result = $this->service->verifyTotp($token, '000000');

        $this->assertFalse($result);
    }

    public function testVerifyBackupCodeSuccess(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $plainCode = 'ABCD-1234';
        $hashedCode = password_hash($plainCode, PASSWORD_ARGON2ID);

        $token = $this->createMock(MfaToken::class);
        $token->method('getId')->willReturn(1);
        $token->method('getUser')->willReturn($user);
        $token->method('getBackupCodes')->willReturn([$hashedCode, 'other_hash']);

        $token->expects($this->once())
            ->method('setBackupCodes')
            ->with($this->callback(fn($codes) => count($codes) === 1));

        $token->expects($this->once())->method('recordUsage');

        $this->entityManager->expects($this->once())->method('flush');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Backup code used', $this->anything());

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with('mfa_backup_code_used', 'MfaToken', 1);

        $result = $this->service->verifyBackupCode($token, $plainCode);

        $this->assertTrue($result);
    }

    public function testVerifyBackupCodeFailure(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $hashedCode = password_hash('ABCD-1234', PASSWORD_ARGON2ID);

        $token = $this->createMock(MfaToken::class);
        $token->method('getBackupCodes')->willReturn([$hashedCode]);
        $token->expects($this->never())->method('setBackupCodes');

        $result = $this->service->verifyBackupCode($token, 'WRONG-CODE');

        $this->assertFalse($result);
    }

    public function testVerifyBackupCodeEmptyCodes(): void
    {
        $token = $this->createMock(MfaToken::class);
        $token->method('getBackupCodes')->willReturn([]);

        $result = $this->service->verifyBackupCode($token, 'ABCD-1234');

        $this->assertFalse($result);
    }

    public function testVerifyBackupCodeNullCodes(): void
    {
        $token = $this->createMock(MfaToken::class);
        $token->method('getBackupCodes')->willReturn(null);

        $result = $this->service->verifyBackupCode($token, 'ABCD-1234');

        $this->assertFalse($result);
    }

    public function testVerifyBackupCodeWarnsWhenLow(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $plainCode = 'ABCD-1234';
        $hashedCode = password_hash($plainCode, PASSWORD_ARGON2ID);

        $token = $this->createMock(MfaToken::class);
        $token->method('getId')->willReturn(1);
        $token->method('getUser')->willReturn($user);
        // Only 2 codes left after using this one
        $token->method('getBackupCodes')->willReturn([$hashedCode, 'hash1', 'hash2']);

        $token->expects($this->once())->method('setBackupCodes');
        $token->expects($this->once())->method('recordUsage');

        $this->entityManager->expects($this->once())->method('flush');

        $this->logger->expects($this->exactly(2))
            ->method($this->anything())
            ->withConsecutive(
                ['info', 'Backup code used', $this->anything()],
                ['warning', 'Low backup codes', $this->callback(fn($ctx) => $ctx['remaining'] === 2)]
            );

        $this->service->verifyBackupCode($token, $plainCode);
    }

    public function testRegenerateBackupCodes(): void
    {
        $user = $this->createUser(1, 'user@example.com');

        $token = $this->createMock(MfaToken::class);
        $token->method('getId')->willReturn(1);
        $token->method('getUser')->willReturn($user);

        $token->expects($this->once())
            ->method('setBackupCodes')
            ->with($this->callback(fn($codes) => count($codes) === 10));

        $this->entityManager->expects($this->once())->method('flush');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Backup codes regenerated');

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with('mfa_backup_codes_regenerated', 'MfaToken', 1);

        $newCodes = $this->service->regenerateBackupCodes($token);

        $this->assertCount(10, $newCodes);
        foreach ($newCodes as $code) {
            $this->assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}$/', $code);
        }
    }

    public function testGetUserMfaTokensReturnsActiveTokens(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $tokens = [
            $this->createMock(MfaToken::class),
            $this->createMock(MfaToken::class),
        ];

        $this->mfaTokenRepository->expects($this->once())
            ->method('findBy')
            ->with(
                ['user' => $user, 'isActive' => true],
                ['enrolledAt' => 'DESC']
            )
            ->willReturn($tokens);

        $result = $this->service->getUserMfaTokens($user);

        $this->assertSame($tokens, $result);
    }

    public function testUserHasMfaEnabledTrue(): void
    {
        $user = $this->createUser(1, 'user@example.com');

        $this->mfaTokenRepository->method('findBy')
            ->willReturn([$this->createMock(MfaToken::class)]);

        $this->assertTrue($this->service->userHasMfaEnabled($user));
    }

    public function testUserHasMfaEnabledFalse(): void
    {
        $user = $this->createUser(1, 'user@example.com');

        $this->mfaTokenRepository->method('findBy')
            ->willReturn([]);

        $this->assertFalse($this->service->userHasMfaEnabled($user));
    }

    public function testDisableMfaToken(): void
    {
        $user = $this->createUser(1, 'user@example.com');

        $token = $this->createMock(MfaToken::class);
        $token->method('getId')->willReturn(1);
        $token->method('getUser')->willReturn($user);
        $token->method('getTokenType')->willReturn('totp');

        $token->expects($this->once())
            ->method('setIsActive')
            ->with(false);

        $this->entityManager->expects($this->once())->method('flush');

        $this->logger->expects($this->once())
            ->method('info')
            ->with('MFA token disabled');

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                'mfa_token_disabled',
                'MfaToken',
                1,
                ['is_active' => true],
                ['is_active' => false]
            );

        $this->service->disableMfaToken($token);
    }

    public function testGenerateTotpSecretCreatesUniqueSecrets(): void
    {
        $user = $this->createUser(1, 'user@example.com');

        $this->entityManager->expects($this->exactly(2))->method('persist');
        $this->entityManager->expects($this->exactly(2))->method('flush');

        $token1 = $this->service->generateTotpSecret($user);
        $token2 = $this->service->generateTotpSecret($user);

        $this->assertNotSame($token1->getSecret(), $token2->getSecret());
    }

    public function testBackupCodesAreUnique(): void
    {
        $user = $this->createUser(1, 'user@example.com');

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $token = $this->service->generateTotpSecret($user);
        $codes = $token->temporaryBackupCodes;

        // All codes should be unique
        $this->assertCount(10, array_unique($codes));
    }

    private function createUser(int $id, string $email): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getEmail')->willReturn($email);
        return $user;
    }

    private function createMfaToken(int $id, User $user, string $type, string $secret): MockObject
    {
        $token = $this->createMock(MfaToken::class);
        $token->method('getId')->willReturn($id);
        $token->method('getUser')->willReturn($user);
        $token->method('getTokenType')->willReturn($type);
        $token->method('getSecret')->willReturn($secret);
        $token->method('isActive')->willReturn(false);
        $token->method('getLastUsedAt')->willReturn(null);
        return $token;
    }
}
