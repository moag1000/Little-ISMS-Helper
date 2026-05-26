<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DoraSubcontractorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * DORA Art. 28 Subcontractor — RT_04 (Subcontractor-Chain) entity.
 *
 * Captures the chain of subcontractors that a primary ICT provider (Supplier)
 * uses to deliver its service. Models the 4th- / 5th-party risk DORA cares
 * about: each `DoraSubcontractor` row sits below a prime `Supplier`
 * (`parentSupplier`) and optionally below another subcontractor in the same
 * chain (`parentSubcontractor`), forming an arbitrarily deep tree.
 *
 * The ESA RoI XBRL exporter walks this tree recursively to emit RT_04
 * detail rows (one per node) — see {@see \App\Service\Authority\DoraRoiXbrlExporter}.
 *
 * Module gate: `nis2_dora` (enforced in controller, not on the entity).
 */
#[ORM\Entity(repositoryClass: DoraSubcontractorRepository::class)]
#[ORM\Table(name: 'dora_subcontractor')]
#[ORM\Index(name: 'idx_dora_subcontractor_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_dora_subcontractor_parent_supplier', columns: ['parent_supplier_id'])]
#[ORM\Index(name: 'idx_dora_subcontractor_parent_sub', columns: ['parent_subcontractor_id'])]
#[ORM\Index(name: 'idx_dora_subcontractor_criticality', columns: ['criticality'])]
class DoraSubcontractor
{
    public const array CRITICALITY_CHOICES = ['critical', 'important', 'standard'];
    public const array SUBSTITUTABILITY_CHOICES = ['high', 'medium', 'low'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * Prime supplier (1st-party) the chain hangs off. Always set; even nested
     * subcontractors (tier ≥ 3) point at the root prime so a single JOIN
     * yields the whole chain for a given supplier.
     */
    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'dora_subcontractor.validation.parent_supplier_required')]
    private ?Supplier $parentSupplier = null;

    /**
     * Optional parent subcontractor in the chain. NULL when this row is a
     * direct subcontractor of the prime (tier 2). For tier ≥ 3 this points
     * at the immediate parent in the tree.
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DoraSubcontractor $parentSubcontractor = null;

    /**
     * Child subcontractors (next-tier sub-subs).
     *
     * @var Collection<int, DoraSubcontractor>
     */
    #[ORM\OneToMany(mappedBy: 'parentSubcontractor', targetEntity: self::class)]
    private Collection $children;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'dora_subcontractor.validation.name_required')]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    /**
     * Legal Entity Identifier (ISO 17442 — 20 char alphanumeric).
     * Optional but strongly recommended — GLEIF-issued.
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(exactly: 20, exactMessage: 'dora_subcontractor.validation.lei_length')]
    #[Assert\Regex(
        pattern: '/^[A-Z0-9]{20}$/',
        message: 'dora_subcontractor.validation.lei_format',
    )]
    private ?string $leiCode = null;

    /**
     * ISO 3166-1 alpha-2 country code (head office / registration jurisdiction).
     */
    #[ORM\Column(length: 2, nullable: true)]
    #[Assert\Length(exactly: 2, exactMessage: 'dora_subcontractor.validation.country_length')]
    #[Assert\Regex(
        pattern: '/^[A-Z]{2}$/',
        message: 'dora_subcontractor.validation.country_format',
    )]
    private ?string $country = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $serviceDescription = null;

    /**
     * Tier in the subcontractor chain.
     * 2 = direct subcontractor of the prime, 3 = sub-sub-contractor, ...
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(
        min: 2,
        max: 5,
        notInRangeMessage: 'dora_subcontractor.validation.tier_range',
    )]
    private int $tier = 2;

    /**
     * Criticality — DORA Art. 30(2)(g) classification.
     */
    #[ORM\Column(length: 20)]
    #[Assert\Choice(
        choices: self::CRITICALITY_CHOICES,
        message: 'dora_subcontractor.validation.criticality_invalid',
    )]
    private string $criticality = 'standard';

    /**
     * Substitutability — operator's assessment of how easily this subcontractor
     * could be swapped out. `low` = lock-in risk, escalates concentration risk.
     */
    #[ORM\Column(length: 20)]
    #[Assert\Choice(
        choices: self::SUBSTITUTABILITY_CHOICES,
        message: 'dora_subcontractor.validation.substitutability_invalid',
    )]
    private string $substitutability = 'medium';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
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

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getParentSupplier(): ?Supplier
    {
        return $this->parentSupplier;
    }

    public function setParentSupplier(?Supplier $parentSupplier): self
    {
        $this->parentSupplier = $parentSupplier;

        return $this;
    }

    public function getParentSubcontractor(): ?DoraSubcontractor
    {
        return $this->parentSubcontractor;
    }

    public function setParentSubcontractor(?DoraSubcontractor $parentSubcontractor): self
    {
        // Defensive: never point at self — would create a cycle.
        if ($parentSubcontractor === $this) {
            $parentSubcontractor = null;
        }
        $this->parentSubcontractor = $parentSubcontractor;

        return $this;
    }

    /**
     * @return Collection<int, DoraSubcontractor>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(DoraSubcontractor $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParentSubcontractor($this);
        }

        return $this;
    }

    public function removeChild(DoraSubcontractor $child): self
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParentSubcontractor() === $this) {
                $child->setParentSubcontractor(null);
            }
        }

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getLeiCode(): ?string
    {
        return $this->leiCode;
    }

    public function setLeiCode(?string $leiCode): self
    {
        $this->leiCode = $leiCode !== null && $leiCode !== ''
            ? strtoupper($leiCode)
            : null;

        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country !== null && $country !== ''
            ? strtoupper($country)
            : null;

        return $this;
    }

    public function getServiceDescription(): ?string
    {
        return $this->serviceDescription;
    }

    public function setServiceDescription(?string $serviceDescription): self
    {
        $this->serviceDescription = $serviceDescription;

        return $this;
    }

    public function getTier(): int
    {
        return $this->tier;
    }

    public function setTier(int $tier): self
    {
        $this->tier = $tier;

        return $this;
    }

    public function getCriticality(): string
    {
        return $this->criticality;
    }

    public function setCriticality(string $criticality): self
    {
        $this->criticality = $criticality;

        return $this;
    }

    public function getSubstitutability(): string
    {
        return $this->substitutability;
    }

    public function setSubstitutability(string $substitutability): self
    {
        $this->substitutability = $substitutability;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    /**
     * Resolves the chain root (always a Supplier — the prime contractor).
     * Convenience for exporters / tree-renderers.
     */
    public function getRootSupplier(): ?Supplier
    {
        return $this->parentSupplier;
    }
}
