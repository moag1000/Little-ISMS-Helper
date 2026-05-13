<?php

declare(strict_types=1);

namespace App\Entity\Notification;

use App\Entity\Tenant;
use App\Repository\Notification\NotificationDeliveryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationDeliveryRepository::class)]
#[ORM\Table(name: 'notification_delivery')]
#[ORM\Index(name: 'idx_notif_delivery_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_notif_delivery_status', columns: ['status'])]
#[ORM\Index(name: 'idx_notif_delivery_rule', columns: ['rule_id'])]
#[ORM\Index(name: 'idx_notif_delivery_attempted', columns: ['attempted_at'])]
class NotificationDelivery
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_SENT     = 'sent';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_RETRYING = 'retrying';

    public const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_RETRYING,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: NotificationRule::class)]
    #[ORM\JoinColumn(name: 'rule_id', nullable: false, onDelete: 'CASCADE')]
    private ?NotificationRule $rule = null;

    #[ORM\ManyToOne(targetEntity: NotificationChannel::class)]
    #[ORM\JoinColumn(name: 'channel_id', nullable: false, onDelete: 'CASCADE')]
    private ?NotificationChannel $channel = null;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column]
    private int $retries = 0;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'response_payload', type: Types::JSON, nullable: true)]
    private ?array $responsePayload = null;

    #[ORM\Column(name: 'attempted_at')]
    private ?DateTimeImmutable $attemptedAt = null;

    #[ORM\Column(name: 'delivered_at', nullable: true)]
    private ?DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    public function getId(): ?int { return $this->id; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }

    public function getRule(): ?NotificationRule { return $this->rule; }
    public function setRule(?NotificationRule $rule): static { $this->rule = $rule; return $this; }

    public function getChannel(): ?NotificationChannel { return $this->channel; }
    public function setChannel(?NotificationChannel $channel): static { $this->channel = $channel; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getRetries(): int { return $this->retries; }
    public function setRetries(int $retries): static { $this->retries = $retries; return $this; }
    public function incrementRetries(): static { $this->retries++; return $this; }

    /** @return array<string, mixed>|null */
    public function getResponsePayload(): ?array { return $this->responsePayload; }

    /** @param array<string, mixed>|null $responsePayload */
    public function setResponsePayload(?array $responsePayload): static { $this->responsePayload = $responsePayload; return $this; }

    public function getAttemptedAt(): ?DateTimeImmutable { return $this->attemptedAt; }
    public function setAttemptedAt(?DateTimeImmutable $attemptedAt): static { $this->attemptedAt = $attemptedAt; return $this; }

    public function getDeliveredAt(): ?DateTimeImmutable { return $this->deliveredAt; }
    public function setDeliveredAt(?DateTimeImmutable $deliveredAt): static { $this->deliveredAt = $deliveredAt; return $this; }

    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $errorMessage): static { $this->errorMessage = $errorMessage; return $this; }

    public function markSent(array $responsePayload = []): static
    {
        $this->status = self::STATUS_SENT;
        $this->deliveredAt = new DateTimeImmutable();
        $this->responsePayload = $responsePayload;
        return $this;
    }

    public function markFailed(string $errorMessage, array $responsePayload = []): static
    {
        $this->status = self::STATUS_FAILED;
        $this->errorMessage = $errorMessage;
        $this->responsePayload = $responsePayload;
        return $this;
    }
}
