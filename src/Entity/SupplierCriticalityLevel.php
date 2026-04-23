<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SupplierCriticalityLevelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Phase 8QW-5 — Tenant-erweiterbare Kritikalitätsstufen für Lieferanten.
 *
 * Jeder Tenant kann seine eigene Kritikalitätsskala definieren.
 * Neue Tenants bekommen 4 Default-Records (critical/high/medium/low)
 * via TenantCreatedSeedListener.
 *
 * DORA-ICT-Kritikalität bleibt separat (Supplier.ictCriticality) und
 * wird nicht mit diesem Entity verquickt.
 */
#[ORM\Entity(repositoryClass: SupplierCriticalityLevelRepository::class)]
#[ORM\Table(name: 'supplier_criticality_level')]
#[ORM\Index(name: 'idx_scl_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_scl_sort', columns: ['tenant_id', 'sort_order'])]
#[ORM\Index(name: 'idx_scl_active', columns: ['tenant_id', 'is_active'])]
#[ORM\UniqueConstraint(name: 'uniq_scl_tenant_code', columns: ['tenant_id', 'code'])]
class SupplierCriticalityLevel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * Machine-readable code used in Supplier.criticality string field.
     * Example: 'critical', 'high', 'medium', 'low', 'strategic'.
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    #[Assert\Regex('/^[a-z0-9_]+$/', message: 'Code must be lowercase alphanumeric/underscore only')]
    private ?string $code = null;

    /**
     * German label shown in DE locale.
     */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $labelDe = null;

    /**
     * English label shown in EN locale.
     */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private ?string $labelEn = null;

    /**
     * Display order (lower = first). Used in dropdowns and reports.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\Range(min: 0, max: 999)]
    private int $sortOrder = 50;

    /**
     * Bootstrap color variant for badges/chips.
     * Example: 'danger', 'warning', 'info', 'secondary'.
     */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $color = null;

    /**
     * Whether this level is pre-selected in new Supplier forms.
     * Only one record per tenant should have isDefault=true.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isDefault = false;

    /**
     * Inactive levels are hidden from dropdowns but kept for legacy records.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
        return $this;
    }

    public function getLabelDe(): ?string
    {
        return $this->labelDe;
    }

    public function setLabelDe(string $labelDe): static
    {
        $this->labelDe = $labelDe;
        return $this;
    }

    public function getLabelEn(): ?string
    {
        return $this->labelEn;
    }

    public function setLabelEn(string $labelEn): static
    {
        $this->labelEn = $labelEn;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;
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

    /**
     * Returns the localized label based on the given locale.
     * Falls back to EN if DE not set.
     */
    public function getLabel(string $locale = 'de'): string
    {
        if ($locale === 'de' && $this->labelDe !== null) {
            return $this->labelDe;
        }
        return $this->labelEn ?? $this->code ?? '';
    }
}
