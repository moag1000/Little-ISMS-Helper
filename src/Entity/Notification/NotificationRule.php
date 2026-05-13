<?php

declare(strict_types=1);

namespace App\Entity\Notification;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Notification\NotificationRuleRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRuleRepository::class)]
#[ORM\Table(name: 'notification_rule')]
#[ORM\Index(name: 'idx_notif_rule_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_notif_rule_event_type', columns: ['event_type'])]
#[ORM\Index(name: 'idx_notif_rule_active', columns: ['is_active'])]
#[ORM\HasLifecycleCallbacks]
class NotificationRule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(name: 'event_type', length: 80)]
    private string $eventType = '';

    /** @var array<int, array<string, mixed>> */
    #[ORM\Column(type: Types::JSON)]
    private array $conditions = [];

    #[ORM\Column(name: 'severity_filter', length: 32, nullable: true)]
    private ?string $severityFilter = null;

    #[ORM\Column(name: 'is_active')]
    private bool $isActive = true;

    #[ORM\Column(name: 'evaluation_count')]
    private int $evaluationCount = 0;

    #[ORM\Column(name: 'last_evaluated_at', nullable: true)]
    private ?DateTimeImmutable $lastEvaluatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true)]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at')]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at')]
    private ?DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, NotificationChannel> */
    #[ORM\ManyToMany(targetEntity: NotificationChannel::class, inversedBy: 'rules')]
    #[ORM\JoinTable(
        name: 'notification_rule_channel',
        joinColumns: [new ORM\JoinColumn(name: 'rule_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'channel_id', referencedColumnName: 'id', onDelete: 'CASCADE')],
    )]
    private Collection $channels;

    public function __construct()
    {
        $this->channels = new ArrayCollection();
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

    public function getEventType(): string { return $this->eventType; }
    public function setEventType(string $eventType): static { $this->eventType = $eventType; return $this; }

    /** @return array<int, array<string, mixed>> */
    public function getConditions(): array { return $this->conditions; }

    /** @param array<int, array<string, mixed>> $conditions */
    public function setConditions(array $conditions): static { $this->conditions = $conditions; return $this; }

    public function getSeverityFilter(): ?string { return $this->severityFilter; }
    public function setSeverityFilter(?string $severityFilter): static { $this->severityFilter = $severityFilter; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getEvaluationCount(): int { return $this->evaluationCount; }
    public function setEvaluationCount(int $evaluationCount): static { $this->evaluationCount = $evaluationCount; return $this; }
    public function incrementEvaluationCount(): static { $this->evaluationCount++; return $this; }

    public function getLastEvaluatedAt(): ?DateTimeImmutable { return $this->lastEvaluatedAt; }
    public function setLastEvaluatedAt(?DateTimeImmutable $lastEvaluatedAt): static { $this->lastEvaluatedAt = $lastEvaluatedAt; return $this; }

    public function getCreatedBy(): ?User { return $this->createdBy; }
    public function setCreatedBy(?User $createdBy): static { $this->createdBy = $createdBy; return $this; }

    public function getCreatedAt(): ?DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, NotificationChannel> */
    public function getChannels(): Collection { return $this->channels; }

    public function addChannel(NotificationChannel $channel): static
    {
        if (!$this->channels->contains($channel)) {
            $this->channels->add($channel);
        }
        return $this;
    }

    public function removeChannel(NotificationChannel $channel): static
    {
        $this->channels->removeElement($channel);
        return $this;
    }
}
