<?php

declare(strict_types=1);

namespace App\Entity\Notification;

use App\Entity\Tenant;
use App\Repository\Notification\NotificationTemplateRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationTemplateRepository::class)]
#[ORM\Table(name: 'notification_template')]
#[ORM\UniqueConstraint(name: 'uniq_template_key_tenant', columns: ['template_key', 'tenant_id'])]
#[ORM\Index(name: 'idx_notif_tpl_category', columns: ['category'])]
#[ORM\HasLifecycleCallbacks]
class NotificationTemplate
{
    public const CATEGORY_INCIDENT   = 'incident';
    public const CATEGORY_COMPLIANCE = 'compliance';
    public const CATEGORY_SLA        = 'sla';
    public const CATEGORY_PRIVACY    = 'privacy';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Null = global template available to all tenants */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(name: 'template_key', length: 80)]
    private string $templateKey = '';

    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(name: 'default_event_type', length: 80)]
    private string $defaultEventType = '';

    /** @var array<int, array<string, mixed>> */
    #[ORM\Column(name: 'default_conditions', type: Types::JSON)]
    private array $defaultConditions = [];

    /** @var array<int, array<string, mixed>> */
    #[ORM\Column(name: 'default_channels', type: Types::JSON)]
    private array $defaultChannels = [];

    #[ORM\Column(length: 40)]
    private string $category = '';

    #[ORM\Column(name: 'created_at')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at')]
    private ?DateTimeImmutable $updatedAt = null;

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

    public function getTemplateKey(): string { return $this->templateKey; }
    public function setTemplateKey(string $templateKey): static { $this->templateKey = $templateKey; return $this; }

    public function getName(): string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDefaultEventType(): string { return $this->defaultEventType; }
    public function setDefaultEventType(string $defaultEventType): static { $this->defaultEventType = $defaultEventType; return $this; }

    /** @return array<int, array<string, mixed>> */
    public function getDefaultConditions(): array { return $this->defaultConditions; }

    /** @param array<int, array<string, mixed>> $defaultConditions */
    public function setDefaultConditions(array $defaultConditions): static { $this->defaultConditions = $defaultConditions; return $this; }

    /** @return array<int, array<string, mixed>> */
    public function getDefaultChannels(): array { return $this->defaultChannels; }

    /** @param array<int, array<string, mixed>> $defaultChannels */
    public function setDefaultChannels(array $defaultChannels): static { $this->defaultChannels = $defaultChannels; return $this; }

    public function getCategory(): string { return $this->category; }
    public function setCategory(string $category): static { $this->category = $category; return $this; }

    public function getCreatedAt(): ?DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTimeImmutable { return $this->updatedAt; }

    public function isGlobal(): bool { return $this->tenant === null; }
}
