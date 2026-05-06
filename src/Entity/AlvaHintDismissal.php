<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AlvaHintDismissalRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-user dismissal record for an Alva-Fee proactive hint.
 *
 * Persisted in the DB rather than localStorage so the dismissed state
 * follows the user across devices. Tied to (user, hintKey,
 * entityType, entityId): the same hint key on a different entity
 * stays visible.
 */
#[ORM\Entity(repositoryClass: AlvaHintDismissalRepository::class)]
#[ORM\Table(name: 'alva_hint_dismissal')]
#[ORM\UniqueConstraint(
    name: 'uq_alva_hint_dismissal',
    columns: ['user_id', 'tenant_id', 'hint_key', 'entity_type', 'entity_id'],
)]
#[ORM\Index(name: 'idx_alva_hint_dismissal_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_alva_hint_dismissal_tenant', columns: ['tenant_id'])]
class AlvaHintDismissal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Tenant scope. A user dismissing the same hint key + entity_id
     * across tenants stays a per-tenant decision; nullable lets
     * tenant-less hints (super-admin views) work too.
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * Stable hint identifier from the corresponding HintRule
     * (e.g. "asset.protection_inheritance", "incident.gdpr_72h").
     */
    #[ORM\Column(length: 100)]
    private ?string $hintKey = null;

    /**
     * Short class name of the related entity ("Asset", "Incident", ...).
     * Empty string for tenant-wide hints with no entity scope.
     */
    #[ORM\Column(length: 100)]
    private string $entityType = '';

    /**
     * Numeric ID of the related entity, 0 for tenant-wide hints.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $entityId = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $dismissedAt = null;

    /**
     * When set, the dismissal expires and the hint reappears for the
     * user. Null means "dismissed forever" (default behaviour).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $dismissedUntil = null;

    public function __construct()
    {
        $this->dismissedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): self { $this->tenant = $tenant; return $this; }

    public function getHintKey(): ?string { return $this->hintKey; }
    public function setHintKey(string $hintKey): self { $this->hintKey = $hintKey; return $this; }

    public function getEntityType(): string { return $this->entityType; }
    public function setEntityType(string $entityType): self { $this->entityType = $entityType; return $this; }

    public function getEntityId(): int { return $this->entityId; }
    public function setEntityId(int $entityId): self { $this->entityId = $entityId; return $this; }

    public function getDismissedAt(): ?DateTimeInterface { return $this->dismissedAt; }
    public function setDismissedAt(DateTimeInterface $dismissedAt): self { $this->dismissedAt = $dismissedAt; return $this; }

    public function getDismissedUntil(): ?DateTimeInterface { return $this->dismissedUntil; }
    public function setDismissedUntil(?DateTimeInterface $dismissedUntil): self { $this->dismissedUntil = $dismissedUntil; return $this; }
}
