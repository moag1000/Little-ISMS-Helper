<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SourceConversionConfigRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-tenant, per-source auto-conversion configuration for the resource-planning
 * collector hub. Without an enabled row a source is NOT auto-converted
 * (off-by-default — spam protection). Configures the due-date offset and the
 * default planned effort for items created from that source.
 */
#[ORM\Entity(repositoryClass: SourceConversionConfigRepository::class)]
#[ORM\Table(name: 'source_conversion_configs')]
#[ORM\UniqueConstraint(name: 'uniq_source_conv_tenant_slug', columns: ['tenant_id', 'source_slug'])]
#[ORM\Index(name: 'idx_source_conv_tenant', columns: ['tenant_id'])]
class SourceConversionConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 40)]
    private ?string $sourceSlug = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $enabled = false;

    /** Days added to the source deadline when setting the ActionItem dueDate. */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $dueOffsetDays = 0;

    /** Default planned effort (PT) for items created from this source. */
    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    private ?string $defaultEffortPt = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceSlug(): ?string
    {
        return $this->sourceSlug;
    }

    public function setSourceSlug(?string $sourceSlug): static
    {
        $this->sourceSlug = $sourceSlug;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getDueOffsetDays(): int
    {
        return $this->dueOffsetDays;
    }

    public function setDueOffsetDays(int $dueOffsetDays): static
    {
        $this->dueOffsetDays = $dueOffsetDays;
        return $this;
    }

    public function getDefaultEffortPt(): ?string
    {
        return $this->defaultEffortPt;
    }

    public function setDefaultEffortPt(?string $defaultEffortPt): static
    {
        $this->defaultEffortPt = $defaultEffortPt;
        return $this;
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
}
