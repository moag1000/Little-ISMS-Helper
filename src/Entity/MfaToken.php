<?php

namespace App\Entity;

use App\Repository\MfaTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * MFA Token Entity for NIS2 Compliance (Art. 21.2.b)
 * Supports TOTP, WebAuthn, SMS, and Hardware Tokens
 */
#[ORM\Entity(repositoryClass: MfaTokenRepository::class)]
#[ORM\Table(name: 'mfa_tokens')]
#[ORM\Index(columns: ['user_id'], name: 'idx_mfa_user')]
#[ORM\Index(columns: ['token_type'], name: 'idx_mfa_type')]
#[ORM\Index(columns: ['is_active'], name: 'idx_mfa_active')]
class MfaToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Type of MFA token
     * - totp: Time-based One-Time Password (Google Authenticator, Authy)
     * - webauthn: WebAuthn/FIDO2 (YubiKey, Windows Hello)
     * - sms: SMS-based verification
     * - hardware: Hardware token (RSA SecurID)
     * - backup: Backup codes
     */
    #[ORM\Column(length: 20)]
    private ?string $tokenType = null;

    /**
     * Device name or identifier
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $deviceName = null;

    /**
     * Secret for TOTP tokens (encrypted)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $secret = null;

    /**
     * Backup codes (encrypted, JSON array)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $backupCodes = null;

    /**
     * WebAuthn credential ID
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $credentialId = null;

    /**
     * WebAuthn public key
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $publicKey = null;

    /**
     * Counter for WebAuthn (prevents replay attacks)
     */
    #[ORM\Column(nullable: true)]
    private ?int $counter = 0;

    /**
     * Phone number for SMS tokens
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phoneNumber = null;

    /**
     * Is this token currently active?
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    /**
     * Is this the primary MFA method?
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isPrimary = false;

    /**
     * Last time this token was used
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    /**
     * Number of times this token has been used
     */
    #[ORM\Column]
    private int $usageCount = 0;

    /**
     * When this token was enrolled
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $enrolledAt = null;

    /**
     * When this token expires (optional)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->enrolledAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getTokenType(): ?string
    {
        return $this->tokenType;
    }

    public function setTokenType(string $tokenType): static
    {
        $this->tokenType = $tokenType;
        return $this;
    }

    public function getDeviceName(): ?string
    {
        return $this->deviceName;
    }

    public function setDeviceName(?string $deviceName): static
    {
        $this->deviceName = $deviceName;
        return $this;
    }

    public function getSecret(): ?string
    {
        return $this->secret;
    }

    public function setSecret(?string $secret): static
    {
        $this->secret = $secret;
        return $this;
    }

    public function getBackupCodes(): ?array
    {
        return $this->backupCodes;
    }

    public function setBackupCodes(?array $backupCodes): static
    {
        $this->backupCodes = $backupCodes;
        return $this;
    }

    public function getCredentialId(): ?string
    {
        return $this->credentialId;
    }

    public function setCredentialId(?string $credentialId): static
    {
        $this->credentialId = $credentialId;
        return $this;
    }

    public function getPublicKey(): ?string
    {
        return $this->publicKey;
    }

    public function setPublicKey(?string $publicKey): static
    {
        $this->publicKey = $publicKey;
        return $this;
    }

    public function getCounter(): ?int
    {
        return $this->counter;
    }

    public function setCounter(?int $counter): static
    {
        $this->counter = $counter;
        return $this;
    }

    public function incrementCounter(): static
    {
        $this->counter++;
        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function setIsPrimary(bool $isPrimary): static
    {
        $this->isPrimary = $isPrimary;
        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?\DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function recordUsage(): static
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        $this->usageCount++;
        return $this;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    public function setUsageCount(int $usageCount): static
    {
        $this->usageCount = $usageCount;
        return $this;
    }

    public function getEnrolledAt(): ?\DateTimeImmutable
    {
        return $this->enrolledAt;
    }

    public function setEnrolledAt(\DateTimeImmutable $enrolledAt): static
    {
        $this->enrolledAt = $enrolledAt;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return $this->expiresAt < new \DateTimeImmutable();
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Get human-readable token type name
     */
    public function getTokenTypeName(): string
    {
        return match($this->tokenType) {
            'totp' => 'Authenticator App (TOTP)',
            'webauthn' => 'Security Key (WebAuthn/FIDO2)',
            'sms' => 'SMS Verification',
            'hardware' => 'Hardware Token',
            'backup' => 'Backup Codes',
            default => 'Unknown',
        };
    }

    /**
     * Check if token is valid (active and not expired)
     */
    public function isValid(): bool
    {
        return $this->isActive && !$this->isExpired();
    }
}
