<?php

namespace App\Entity;

use DateTimeImmutable;
use App\Repository\UserSessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * UserSession Entity - Tracks active user sessions for NIS2 compliance
 *
 * Enables:
 * - Session monitoring and audit trail
 * - Force logout functionality for administrators
 * - Concurrent session limits
 * - Session hijacking detection
 *
 * NIS2 Compliance: Art. 21.2.e (Incident detection, response, and recovery)
 * ISO 27001: A.9.2.5 (Review of user access rights)
 */
#[ORM\Entity(repositoryClass: UserSessionRepository::class)]
#[ORM\Table(name: 'user_sessions')]
#[ORM\Index(name: 'idx_session_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_session_id', columns: ['session_id'])]
#[ORM\Index(name: 'idx_session_active', columns: ['is_active'])]
#[ORM\Index(name: 'idx_session_activity', columns: ['last_activity_at'])]
class UserSession
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Symfony session ID
     */
    #[ORM\Column(length: 128, unique: true)]
    private ?string $sessionId = null;

    /**
     * IP address from which the session was initiated
     */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /**
     * User agent string
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    /**
     * Is this session currently active?
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    /**
     * When was this session created?
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    /**
     * When was the last activity in this session?
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $lastActivityAt = null;

    /**
     * When did this session end?
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endedAt = null;

    /**
     * How did this session end? (logout, timeout, forced, expired)
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $endReason = null;

    /**
     * Who terminated this session? (null = user logout, otherwise admin username)
     */
    #[ORM\Column(length: 180, nullable: true)]
    private ?string $terminatedBy = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->lastActivityAt = new DateTimeImmutable();
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

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }

    public function setSessionId(string $sessionId): static
    {
        $this->sessionId = $sessionId;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;
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

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(DateTimeImmutable $lastActivityAt): static
    {
        $this->lastActivityAt = $lastActivityAt;
        return $this;
    }

    public function updateActivity(): static
    {
        $this->lastActivityAt = new DateTimeImmutable();
        return $this;
    }

    public function getEndedAt(): ?DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?DateTimeImmutable $endedAt): static
    {
        $this->endedAt = $endedAt;
        return $this;
    }

    public function getEndReason(): ?string
    {
        return $this->endReason;
    }

    public function setEndReason(?string $endReason): static
    {
        $this->endReason = $endReason;
        return $this;
    }

    public function getTerminatedBy(): ?string
    {
        return $this->terminatedBy;
    }

    public function setTerminatedBy(?string $terminatedBy): static
    {
        $this->terminatedBy = $terminatedBy;
        return $this;
    }

    /**
     * Terminate this session
     */
    public function terminate(string $reason = 'forced', ?string $terminatedBy = null): static
    {
        $this->isActive = false;
        $this->endedAt = new DateTimeImmutable();
        $this->endReason = $reason;
        $this->terminatedBy = $terminatedBy;
        return $this;
    }

    /**
     * Check if this session is expired based on last activity
     */
    public function isExpired(int $maxLifetime = 3600): bool
    {
        if (!$this->isActive) {
            return true;
        }

        $now = new DateTimeImmutable();
        $diff = $now->getTimestamp() - $this->lastActivityAt->getTimestamp();

        return $diff > $maxLifetime;
    }

    /**
     * Get session duration in seconds
     */
    public function getDuration(): int
    {
        $endTime = $this->endedAt ?? new DateTimeImmutable();
        return $endTime->getTimestamp() - $this->createdAt->getTimestamp();
    }

    /**
     * Get human-readable session duration
     */
    public function getFormattedDuration(): string
    {
        $seconds = $this->getDuration();

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        }

        return sprintf('%dm', $minutes);
    }
}
