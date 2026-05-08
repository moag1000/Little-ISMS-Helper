<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TenantPolicySettingRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Hierarchy-aware tenant settings store for the Policy-Wizard.
 *
 * Each row is a single namespaced setting (e.g.
 * `isms.scope_statement`, `risk.appetite_tier`) stored as JSON. The
 * `inheritedFromTenant_id` FK identifies the ancestor whose value
 * the current tenant inherits (null when own value). `overrideMode`
 * carries the matrix rule applied at WRITE time so subsidiaries
 * cannot relax parent restrictions. See `05-architecture.md` §7.
 */
#[ORM\Entity(repositoryClass: TenantPolicySettingRepository::class)]
#[ORM\Table(name: 'tenant_policy_setting')]
#[ORM\UniqueConstraint(name: 'uq_tenant_policy_setting_tenant_key', columns: ['tenant_id', 'key_name'])]
#[ORM\Index(name: 'idx_tenant_policy_setting_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_tenant_policy_setting_key', columns: ['key_name'])]
class TenantPolicySetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * Namespaced setting key. Stored under `key_name` because `key`
     * is reserved in MySQL.
     */
    #[ORM\Column(name: 'key_name', length: 191)]
    private ?string $key = null;

    /**
     * Typed JSON value. Shape depends on the key.
     *
     * @var array<string, mixed>|array<int, mixed>|scalar|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private mixed $value = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'inherited_from_tenant_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Tenant $inheritedFromTenant = null;

    /**
     * Override mode. Allowed values per ISB-Practitioner naming:
     *   forbidden_to_change | forbidden_to_relax |
     *   floor_only | ceiling_only | free
     */
    #[ORM\Column(length: 32, options: ['default' => 'free'])]
    private string $overrideMode = 'free';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $updatedByUser = null;

    public function __construct()
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = $value;
        return $this;
    }

    public function getInheritedFromTenant(): ?Tenant
    {
        return $this->inheritedFromTenant;
    }

    public function setInheritedFromTenant(?Tenant $inheritedFromTenant): static
    {
        $this->inheritedFromTenant = $inheritedFromTenant;
        return $this;
    }

    public function getOverrideMode(): string
    {
        return $this->overrideMode;
    }

    public function setOverrideMode(string $overrideMode): static
    {
        $this->overrideMode = $overrideMode;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getUpdatedByUser(): ?User
    {
        return $this->updatedByUser;
    }

    public function setUpdatedByUser(?User $updatedByUser): static
    {
        $this->updatedByUser = $updatedByUser;
        return $this;
    }
}
