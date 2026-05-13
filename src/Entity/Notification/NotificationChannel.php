<?php

declare(strict_types=1);

namespace App\Entity\Notification;

use App\Entity\Tenant;
use App\Repository\Notification\NotificationChannelRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationChannelRepository::class)]
#[ORM\Table(name: 'notification_channel')]
#[ORM\Index(name: 'idx_notif_channel_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_notif_channel_type', columns: ['type'])]
#[ORM\HasLifecycleCallbacks]
class NotificationChannel
{
    public const TYPE_EMAIL   = 'email';
    public const TYPE_WEBHOOK = 'webhook';
    public const TYPE_IN_APP  = 'in_app';

    public const VALID_TYPES = [self::TYPE_EMAIL, self::TYPE_WEBHOOK, self::TYPE_IN_APP];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    /** One of: email, webhook, in_app */
    #[ORM\Column(length: 32)]
    private string $type = self::TYPE_EMAIL;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    #[ORM\Column(name: 'secret_encrypted', type: Types::TEXT, nullable: true)]
    private ?string $secretEncrypted = null;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(name: 'verified_at', nullable: true)]
    private ?DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(name: 'created_at')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at')]
    private ?DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, NotificationRule> */
    #[ORM\ManyToMany(targetEntity: NotificationRule::class, mappedBy: 'channels')]
    private Collection $rules;

    public function __construct()
    {
        $this->rules = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getType(): string { return $this->type; }
    public function setType(string $type): static { $this->type = $type; return $this; }

    /** @return array<string, mixed> */
    public function getConfig(): array { return $this->config; }

    /** @param array<string, mixed> $config */
    public function setConfig(array $config): static { $this->config = $config; return $this; }

    public function getSecretEncrypted(): ?string { return $this->secretEncrypted; }
    public function setSecretEncrypted(?string $secretEncrypted): static { $this->secretEncrypted = $secretEncrypted; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getVerifiedAt(): ?DateTimeImmutable { return $this->verifiedAt; }
    public function setVerifiedAt(?DateTimeImmutable $verifiedAt): static { $this->verifiedAt = $verifiedAt; return $this; }

    public function getCreatedAt(): ?DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, NotificationRule> */
    public function getRules(): Collection { return $this->rules; }
}
