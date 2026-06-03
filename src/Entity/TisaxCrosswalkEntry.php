<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TisaxCrosswalkEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A tenant-scoped, confirmed legacy-id → canonical VDA-ISA control-number
 * crosswalk entry (e.g. ACC-2.1 → 1.1.1).
 *
 * Why tenant-scoped + DB (not the shared YAML fixture): the canonical 1.1.1
 * numbering is derived from the tenant's OWN licensed VDA-ISA workbook (via the
 * ISO-anchor bridge). It is licensed catalogue content and must NOT be committed
 * to a version-controlled fixture — but storing it in the tenant's own database,
 * like the imported assessment itself, is fine. Persisting it here means the
 * 46-ish ISO-anchor-derived mappings are confirmed ONCE per tenant and survive
 * re-runs / the next ISA revision, instead of being re-derived every engagement.
 */
#[ORM\Entity(repositoryClass: TisaxCrosswalkEntryRepository::class)]
#[ORM\Table(name: 'tisax_crosswalk_entry')]
#[ORM\Index(name: 'idx_tce_tenant', columns: ['tenant_id'])]
#[ORM\UniqueConstraint(name: 'uniq_tce_tenant_legacy', columns: ['tenant_id', 'legacy_id'])]
class TisaxCrosswalkEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /** Legacy ad-hoc requirement id, e.g. "ACC-2.1" / "INF-1.1". */
    #[ORM\Column(length: 64)]
    private string $legacyId = '';

    /** Canonical VDA-ISA control number, e.g. "1.1.1". */
    #[ORM\Column(length: 32)]
    private string $canonicalId = '';

    /** 'derived' (ISO-anchor bridge, unique match) or 'confirmed' (human-verified). */
    #[ORM\Column(length: 16)]
    private string $confidence = 'derived';

    /** Provenance, e.g. 'iso_anchor_bridge'. */
    #[ORM\Column(length: 64)]
    private string $source = 'iso_anchor_bridge';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getLegacyId(): string
    {
        return $this->legacyId;
    }

    public function setLegacyId(string $legacyId): static
    {
        $this->legacyId = $legacyId;
        return $this;
    }

    public function getCanonicalId(): string
    {
        return $this->canonicalId;
    }

    public function setCanonicalId(string $canonicalId): static
    {
        $this->canonicalId = $canonicalId;
        return $this;
    }

    public function getConfidence(): string
    {
        return $this->confidence;
    }

    public function setConfidence(string $confidence): static
    {
        $this->confidence = $confidence;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
