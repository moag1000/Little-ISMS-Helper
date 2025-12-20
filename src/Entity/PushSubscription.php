<?php

namespace App\Entity;

use App\Repository\PushSubscriptionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Push Notification Subscription Entity
 *
 * Stores Web Push API subscription data for users.
 * Used for sending push notifications to installed PWA instances.
 */
#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscriptions')]
#[ORM\Index(name: 'idx_push_subscription_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_push_subscription_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'unique_endpoint', columns: ['endpoint_hash'])]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * The push subscription endpoint URL
     */
    #[ORM\Column(type: Types::TEXT)]
    private ?string $endpoint = null;

    /**
     * SHA256 hash of the endpoint for unique constraint
     */
    #[ORM\Column(length: 64)]
    private ?string $endpointHash = null;

    /**
     * The public key for encryption (p256dh)
     */
    #[ORM\Column(type: Types::TEXT)]
    private ?string $publicKey = null;

    /**
     * The authentication secret
     */
    #[ORM\Column(type: Types::TEXT)]
    private ?string $authToken = null;

    /**
     * User agent string for device identification
     */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $userAgent = null;

    /**
     * Device/browser name (e.g., "Chrome on Windows")
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $deviceName = null;

    /**
     * Whether this subscription is active
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    /**
     * Last time a notification was successfully sent
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastUsedAt = null;

    /**
     * Number of failed delivery attempts
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $failureCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
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

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;
        $this->endpointHash = hash('sha256', $endpoint);
        return $this;
    }

    public function getEndpointHash(): ?string
    {
        return $this->endpointHash;
    }

    public function getPublicKey(): ?string
    {
        return $this->publicKey;
    }

    public function setPublicKey(string $publicKey): static
    {
        $this->publicKey = $publicKey;
        return $this;
    }

    public function getAuthToken(): ?string
    {
        return $this->authToken;
    }

    public function setAuthToken(string $authToken): static
    {
        $this->authToken = $authToken;
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

    public function getDeviceName(): ?string
    {
        return $this->deviceName;
    }

    public function setDeviceName(?string $deviceName): static
    {
        $this->deviceName = $deviceName;
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

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?DateTimeImmutable $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function markAsUsed(): static
    {
        $this->lastUsedAt = new DateTimeImmutable();
        $this->failureCount = 0;
        return $this;
    }

    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    public function incrementFailureCount(): static
    {
        $this->failureCount++;
        if ($this->failureCount >= 3) {
            $this->isActive = false;
        }
        return $this;
    }

    public function resetFailureCount(): static
    {
        $this->failureCount = 0;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Get subscription data for Web Push API
     */
    public function getSubscriptionData(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys' => [
                'p256dh' => $this->publicKey,
                'auth' => $this->authToken,
            ],
        ];
    }
}
