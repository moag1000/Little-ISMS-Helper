<?php

namespace App\Service;

use App\Entity\MfaToken;
use App\Entity\User;
use App\Repository\MfaTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use OTPHP\TOTP;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\TooManyRequestsException;

/**
 * MFA Service for NIS2 Compliance (Art. 21.2.b)
 * Handles TOTP, Backup Codes, and Token Management
 */
class MfaService
{
    private const BACKUP_CODES_COUNT = 10;
    private const BACKUP_CODE_LENGTH = 8;
    private const MAX_VERIFICATION_ATTEMPTS = 5;
    private const VERIFICATION_WINDOW = 300; // 5 minutes

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MfaTokenRepository $mfaTokenRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly string $appName = 'Little ISMS Helper',
    ) {
    }

    /**
     * Generate a new TOTP secret for a user
     */
    public function generateTotpSecret(User $user, string $deviceName = 'Authenticator App'): MfaToken
    {
        // Create TOTP instance with secure random secret
        $totp = TOTP::createFromSecret($this->generateSecureSecret());
        $totp->setLabel($user->getEmail());
        $totp->setIssuer($this->appName);

        // Create MFA token entity
        $mfaToken = new MfaToken();
        $mfaToken->setUser($user);
        $mfaToken->setTokenType('totp');
        $mfaToken->setDeviceName($deviceName);
        $mfaToken->setSecret($totp->getSecret());
        $mfaToken->setIsActive(false); // Will be activated after verification

        // Generate backup codes
        $backupCodes = $this->generateBackupCodes();
        $mfaToken->setBackupCodes($this->hashBackupCodes($backupCodes));

        $this->entityManager->persist($mfaToken);
        $this->entityManager->flush();

        $this->logger->info('TOTP secret generated', [
            'user_id' => $user->getId(),
            'user_email' => $user->getEmail(),
            'device_name' => $deviceName,
        ]);

        // Store unhashed backup codes temporarily for display (will be shown only once)
        $mfaToken->temporaryBackupCodes = $backupCodes;

        return $mfaToken;
    }

    /**
     * Generate QR code for TOTP setup
     */
    public function generateQrCode(MfaToken $mfaToken): string
    {
        if ($mfaToken->getTokenType() !== 'totp') {
            throw new \InvalidArgumentException('QR codes can only be generated for TOTP tokens');
        }

        $user = $mfaToken->getUser();
        $totp = TOTP::createFromSecret($mfaToken->getSecret());
        $totp->setLabel($user->getEmail());
        $totp->setIssuer($this->appName);

        $provisioningUri = $totp->getProvisioningUri();

        // Generate QR code
        $result = (new Builder(
            writer: new PngWriter(),
            writerOptions: [],
            data: $provisioningUri,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build();

        // Return base64 encoded PNG
        return base64_encode($result->getString());
    }

    /**
     * Verify TOTP token and activate MFA if first-time setup
     */
    public function verifyTotp(MfaToken $mfaToken, string $code, bool $isSetup = false): bool
    {
        if ($mfaToken->getTokenType() !== 'totp') {
            throw new \InvalidArgumentException('Can only verify TOTP tokens');
        }

        // Rate limiting check
        $this->checkRateLimit($mfaToken);

        $totp = TOTP::createFromSecret($mfaToken->getSecret());

        // Verify with time window (allows ±1 time step for clock drift)
        $isValid = $totp->verify($code, null, 1);

        if ($isValid) {
            // Activate token if this is initial setup
            if ($isSetup && !$mfaToken->isActive()) {
                $mfaToken->setIsActive(true);
                $this->logger->info('TOTP token activated', [
                    'user_id' => $mfaToken->getUser()->getId(),
                    'device_name' => $mfaToken->getDeviceName(),
                ]);

                $this->auditLogger->logCustom(
                    'mfa_totp_enabled',
                    'MfaToken',
                    $mfaToken->getId(),
                    null,
                    ['device_name' => $mfaToken->getDeviceName()],
                    sprintf('TOTP MFA enabled for user %s', $mfaToken->getUser()->getEmail())
                );
            }

            // Record successful use
            $mfaToken->recordUsage();
            $this->entityManager->flush();

            return true;
        }

        $this->logger->warning('TOTP verification failed', [
            'user_id' => $mfaToken->getUser()->getId(),
            'device_name' => $mfaToken->getDeviceName(),
        ]);

        return false;
    }

    /**
     * Verify backup code
     */
    public function verifyBackupCode(MfaToken $mfaToken, string $code): bool
    {
        $backupCodes = $mfaToken->getBackupCodes();

        if (!$backupCodes || empty($backupCodes)) {
            return false;
        }

        // Check if code matches any hashed backup code
        foreach ($backupCodes as $index => $hashedCode) {
            if (password_verify($code, $hashedCode)) {
                // Remove used backup code
                unset($backupCodes[$index]);
                $mfaToken->setBackupCodes(array_values($backupCodes));
                $mfaToken->recordUsage();
                $this->entityManager->flush();

                $this->logger->info('Backup code used', [
                    'user_id' => $mfaToken->getUser()->getId(),
                    'remaining_codes' => count($backupCodes),
                ]);

                $this->auditLogger->logCustom(
                    'mfa_backup_code_used',
                    'MfaToken',
                    $mfaToken->getId(),
                    null,
                    ['remaining_codes' => count($backupCodes)],
                    sprintf('Backup code used for user %s', $mfaToken->getUser()->getEmail())
                );

                // Warn if running low on backup codes
                if (count($backupCodes) <= 2) {
                    $this->logger->warning('Low backup codes', [
                        'user_id' => $mfaToken->getUser()->getId(),
                        'remaining' => count($backupCodes),
                    ]);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Regenerate backup codes
     */
    public function regenerateBackupCodes(MfaToken $mfaToken): array
    {
        $backupCodes = $this->generateBackupCodes();
        $mfaToken->setBackupCodes($this->hashBackupCodes($backupCodes));
        $this->entityManager->flush();

        $this->logger->info('Backup codes regenerated', [
            'user_id' => $mfaToken->getUser()->getId(),
        ]);

        $this->auditLogger->logCustom(
            'mfa_backup_codes_regenerated',
            'MfaToken',
            $mfaToken->getId(),
            null,
            null,
            sprintf('Backup codes regenerated for user %s', $mfaToken->getUser()->getEmail())
        );

        return $backupCodes;
    }

    /**
     * Get active MFA tokens for user
     */
    public function getUserMfaTokens(User $user): array
    {
        return $this->mfaTokenRepository->findBy(
            ['user' => $user, 'isActive' => true],
            ['enrolledAt' => 'DESC']
        );
    }

    /**
     * Check if user has MFA enabled
     */
    public function userHasMfaEnabled(User $user): bool
    {
        return count($this->getUserMfaTokens($user)) > 0;
    }

    /**
     * Disable MFA token
     */
    public function disableMfaToken(MfaToken $mfaToken): void
    {
        $mfaToken->setIsActive(false);
        $this->entityManager->flush();

        $this->logger->info('MFA token disabled', [
            'user_id' => $mfaToken->getUser()->getId(),
            'token_type' => $mfaToken->getTokenType(),
        ]);

        $this->auditLogger->logCustom(
            'mfa_token_disabled',
            'MfaToken',
            $mfaToken->getId(),
            ['is_active' => true],
            ['is_active' => false],
            sprintf('MFA token disabled for user %s', $mfaToken->getUser()->getEmail())
        );
    }

    /**
     * Generate secure random secret for TOTP
     */
    private function generateSecureSecret(): string
    {
        // Generate 160-bit (20 byte) secret for SHA1 TOTP
        return random_bytes(20);
    }

    /**
     * Generate backup codes
     */
    private function generateBackupCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::BACKUP_CODES_COUNT; $i++) {
            $codes[] = $this->generateBackupCode();
        }

        return $codes;
    }

    /**
     * Generate single backup code
     */
    private function generateBackupCode(): string
    {
        $characters = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // Removed ambiguous chars (I, O)
        $code = '';

        for ($i = 0; $i < self::BACKUP_CODE_LENGTH; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        // Format as XXXX-XXXX for readability
        return substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }

    /**
     * Hash backup codes for secure storage
     */
    private function hashBackupCodes(array $codes): array
    {
        return array_map(fn($code) => password_hash($code, PASSWORD_ARGON2ID), $codes);
    }

    /**
     * Rate limiting check for verification attempts
     */
    private function checkRateLimit(MfaToken $mfaToken): void
    {
        // This is a simplified rate limiter
        // In production, use Symfony Rate Limiter component or Redis

        $lastUsed = $mfaToken->getLastUsedAt();

        if ($lastUsed) {
            $now = new \DateTimeImmutable();
            $diff = $now->getTimestamp() - $lastUsed->getTimestamp();

            // If last attempt was < 2 seconds ago, rate limit
            if ($diff < 2) {
                throw new TooManyRequestsException('Too many verification attempts. Please wait.');
            }
        }
    }
}
