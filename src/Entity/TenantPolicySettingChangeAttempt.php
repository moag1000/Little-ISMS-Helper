<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantPolicySettingChangeAttemptRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit log of TenantPolicySetting writes that were blocked by the
 * HierarchyOverrideValidator (architecture §7.3). Captures the
 * attempted value, the override mode that was in force, and the
 * structured reason so the UI can show "ask Konzern-CISO to relax X".
 */
#[ORM\Entity(repositoryClass: TenantPolicySettingChangeAttemptRepository::class)]
#[ORM\Table(name: 'tenant_policy_setting_change_attempt')]
#[ORM\Index(
    name: 'idx_tps_change_attempt_tenant_key_at',
    columns: ['tenant_id', 'key_name', 'attempted_at'],
)]
#[ORM\Index(name: 'idx_tps_change_attempt_tenant', columns: ['tenant_id'])]
class TenantPolicySettingChangeAttempt
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * Setting key under attempt. Stored under `key_name` because
     * `key` is reserved in MySQL.
     */
    #[ORM\Column(name: 'key_name', length: 191)]
    private ?string $key = null;

    /**
     * The value the user tried to set, captured as JSON for full
     * forensic detail.
     *
     * @var array<string, mixed>|array<int, mixed>|scalar|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $attemptedValue = null;

    /**
     * Short machine-readable code describing the rejection reason
     * (e.g. `floor_only_violated`, `forbidden_to_change_at_parent`).
     */
    #[ORM\Column(length: 100)]
    private ?string $blockedReason = null;

    /**
     * Override mode in force at attempt time. Allowed:
     *   forbidden_to_change | forbidden_to_relax |
     *   floor_only | ceiling_only | free
     */
    #[ORM\Column(length: 32)]
    private ?string $overrideMode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $attemptedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'attempted_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $attemptedByUser = null;

    public function __construct()
    {
        $this->attemptedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTenantId(): ?int
    {
        return $this->tenant?->getId();
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function setKey(string $key): static
    {
        $this->key = $key;
        return $this;
    }

    public function getAttemptedValue(): mixed
    {
        return $this->attemptedValue;
    }

    public function setAttemptedValue(mixed $attemptedValue): static
    {
        $this->attemptedValue = $attemptedValue;
        return $this;
    }

    public function getBlockedReason(): ?string
    {
        return $this->blockedReason;
    }

    public function setBlockedReason(string $blockedReason): static
    {
        $this->blockedReason = $blockedReason;
        return $this;
    }

    public function getOverrideMode(): ?string
    {
        return $this->overrideMode;
    }

    public function setOverrideMode(string $overrideMode): static
    {
        $this->overrideMode = $overrideMode;
        return $this;
    }

    public function getAttemptedAt(): ?DateTimeInterface
    {
        return $this->attemptedAt;
    }

    public function setAttemptedAt(DateTimeInterface $attemptedAt): static
    {
        $this->attemptedAt = $attemptedAt;
        return $this;
    }

    public function getAttemptedByUser(): ?User
    {
        return $this->attemptedByUser;
    }

    public function setAttemptedByUser(?User $attemptedByUser): static
    {
        $this->attemptedByUser = $attemptedByUser;
        return $this;
    }
}
