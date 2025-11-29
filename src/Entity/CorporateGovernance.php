<?php

namespace App\Entity;

use DateTimeImmutable;
use App\Enum\GovernanceModel;
use App\Repository\CorporateGovernanceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Defines governance model for specific scopes (controls, processes, etc.)
 * in a corporate structure.
 *
 * This allows different governance models for different areas:
 * - Control A.5.1: Hierarchical (corporate standard)
 * - Control A.8.1: Shared responsibility
 * - Control A.9.1: Independent (subsidiary responsible)
 */
#[ORM\Entity(repositoryClass: CorporateGovernanceRepository::class)]
#[ORM\Table(name: 'corporate_governance')]
#[ORM\Index(name: 'idx_tenant_scope', columns: ['tenant_id', 'scope', 'scope_id'])]
#[ORM\UniqueConstraint(name: 'uniq_tenant_scope', columns: ['tenant_id', 'scope', 'scope_id'])]
#[ORM\HasLifecycleCallbacks]
class CorporateGovernance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $parent = null;

    /**
     * Scope type: 'control', 'isms_context', 'risk', 'asset', 'process', 'document', 'default'
     */
    #[ORM\Column(length: 50)]
    private ?string $scope = null;

    /**
     * ID within the scope (e.g., Control ID like 'A.5.1', Risk ID)
     * NULL means it applies to all items in that scope (default for scope)
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $scopeId = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: GovernanceModel::class)]
    private ?GovernanceModel $governanceModel = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true)]
    private ?User $user = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
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

    public function getParent(): ?Tenant
    {
        return $this->parent;
    }

    public function setParent(?Tenant $tenant): static
    {
        $this->parent = $tenant;
        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(string $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function getScopeId(): ?string
    {
        return $this->scopeId;
    }

    public function setScopeId(?string $scopeId): static
    {
        $this->scopeId = $scopeId;
        return $this;
    }

    public function getGovernanceModel(): ?GovernanceModel
    {
        return $this->governanceModel;
    }

    public function setGovernanceModel(GovernanceModel $governanceModel): static
    {
        $this->governanceModel = $governanceModel;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
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

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->user;
    }

    public function setCreatedBy(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Get a human-readable label for the scope
     */
    public function getScopeLabel(): string
    {
        return match($this->scope) {
            'control' => 'ISO Control',
            'isms_context' => 'ISMS Kontext',
            'risk' => 'Risikomanagement',
            'asset' => 'Asset Management',
            'process' => 'Prozess',
            'document' => 'Dokument',
            'default' => 'Standard (Alle)',
            default => $this->scope,
        };
    }

    /**
     * Get full scope description
     */
    public function getScopeDescription(): string
    {
        $label = $this->getScopeLabel();

        if ($this->scopeId) {
            return $label . ': ' . $this->scopeId;
        }

        return $label . ' (Alle)';
    }
}
