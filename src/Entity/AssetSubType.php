<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssetSubTypeRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Tenant-configurable Asset Sub-Type (S18 B2).
 *
 * 6 ISO-conform top-level types (Hardware/Software/Datenbank/Personal/Standort/
 * Dienstleistung) are too generic for ISO 9001-migrated users — "Server" is not
 * intuitively found under "Hardware". This entity adds an additive, tenant-
 * scoped sub-type layer that can be seeded from industry presets (BSI IT-
 * Grundschutz, TISAX, production-DE-Mittelstand) and refined locally.
 *
 * Workshop-Effekt: 2-3d inventory workshop → 0.5-1d with seed preset + 2-3
 * tenant-local sub-types.
 *
 * Anti-pattern guard: existing Asset.type (top-level) stays untouched — the
 * sub-type FK is purely additive. NO data migration of existing rows.
 */
#[ORM\Entity(repositoryClass: AssetSubTypeRepository::class)]
#[ORM\Table(name: 'asset_sub_type')]
#[ORM\UniqueConstraint(name: 'uniq_subtype_per_tenant', columns: ['tenant_id', 'top_type', 'name'])]
#[ORM\Index(name: 'idx_asset_sub_type_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_asset_sub_type_top_type', columns: ['top_type'])]
class AssetSubType
{
    /**
     * Canonical top-level types — ISO 27001 A.5.9 / BSI IT-Grundschutz Zielobjekt-Klassen.
     */
    public const TOP_TYPES = [
        'Hardware',
        'Software',
        'Datenbank',
        'Personal',
        'Standort',
        'Dienstleistung',
    ];

    public const SOURCE_CUSTOM = 'custom';
    public const SOURCE_BSI = 'seed-bsi-grundschutz';
    public const SOURCE_TISAX = 'seed-tisax';
    public const SOURCE_PRODUCTION_DE = 'seed-production-de-mittelstand';

    public const SOURCES = [
        self::SOURCE_CUSTOM,
        self::SOURCE_BSI,
        self::SOURCE_TISAX,
        self::SOURCE_PRODUCTION_DE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Tenant $tenant = null;

    /**
     * Asset.assetType top-level value — must be one of self::TOP_TYPES.
     */
    #[ORM\Column(name: 'top_type', type: Types::STRING, length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::TOP_TYPES, message: 'asset_sub_type.validation.top_type_invalid')]
    private string $topType = '';

    #[ORM\Column(type: Types::STRING, length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Source preset for traceability ('custom' | 'seed-bsi-grundschutz' |
     * 'seed-tisax' | 'seed-production-de-mittelstand').
     */
    #[ORM\Column(type: Types::STRING, length: 40, nullable: true, options: ['default' => self::SOURCE_CUSTOM])]
    #[Assert\Choice(choices: self::SOURCES, message: 'asset_sub_type.validation.source_invalid')]
    private ?string $source = self::SOURCE_CUSTOM;

    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

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

    public function getTopType(): string
    {
        return $this->topType;
    }

    public function setTopType(string $topType): static
    {
        $this->topType = $topType;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): static
    {
        $this->source = $source;
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return sprintf('%s → %s', $this->topType, $this->name);
    }
}
