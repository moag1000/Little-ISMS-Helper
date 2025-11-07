<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\AssetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: AssetRepository::class)]
#[ORM\Index(columns: ['asset_type'], name: 'idx_asset_type')]
#[ORM\Index(columns: ['status'], name: 'idx_asset_status')]
#[ORM\Index(columns: ['created_at'], name: 'idx_asset_created_at')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_asset_tenant')]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['asset:read']],
    denormalizationContext: ['groups' => ['asset:write']],
    paginationItemsPerPage: 30
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'assetType' => 'exact', 'owner' => 'partial', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'assetType', 'createdAt'])]
class Asset
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['asset:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['asset:read'])]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    #[Groups(['asset:read', 'asset:write', 'risk:read'])]
    #[Assert\NotBlank(message: 'Asset name is required')]
    #[Assert\Length(max: 255, maxMessage: 'Asset name cannot exceed {{ limit }} characters')]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\NotBlank(message: 'Asset type is required')]
    #[Assert\Length(max: 100, maxMessage: 'Asset type cannot exceed {{ limit }} characters')]
    private ?string $assetType = null;

    #[ORM\Column(length: 100)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\NotBlank(message: 'Asset owner is required')]
    #[Assert\Length(max: 100, maxMessage: 'Owner cannot exceed {{ limit }} characters')]
    private ?string $owner = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\Length(max: 100, maxMessage: 'Location cannot exceed {{ limit }} characters')]
    private ?string $location = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\NotNull(message: 'Confidentiality value is required')]
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'Confidentiality value must be between {{ min }} and {{ max }}')]
    private ?int $confidentialityValue = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\NotNull(message: 'Integrity value is required')]
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'Integrity value must be between {{ min }} and {{ max }}')]
    private ?int $integrityValue = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\NotNull(message: 'Availability value is required')]
    #[Assert\Range(min: 1, max: 5, notInRangeMessage: 'Availability value must be between {{ min }} and {{ max }}')]
    private ?int $availabilityValue = null;

    #[ORM\Column(length: 50)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\NotBlank(message: 'Status is required')]
    #[Assert\Choice(
        choices: ['active', 'inactive', 'retired', 'disposed'],
        message: 'Status must be one of: {{ choices }}'
    )]
    private ?string $status = 'active';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['asset:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['asset:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Risk>
     */
    #[ORM\OneToMany(targetEntity: Risk::class, mappedBy: 'asset')]
    #[Groups(['asset:read'])]
    #[MaxDepth(1)]
    private Collection $risks;

    /**
     * @var Collection<int, Incident>
     */
    #[ORM\ManyToMany(targetEntity: Incident::class, mappedBy: 'affectedAssets')]
    #[Groups(['asset:read'])]
    #[MaxDepth(1)]
    private Collection $incidents;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class, mappedBy: 'protectedAssets')]
    #[Groups(['asset:read'])]
    #[MaxDepth(1)]
    private Collection $protectingControls;

    public function __construct()
    {
        $this->risks = new ArrayCollection();
        $this->incidents = new ArrayCollection();
        $this->protectingControls = new ArrayCollection();
        $this->createdAt = new \DateTime();
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

    public function getName(): ?string
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

    public function getAssetType(): ?string
    {
        return $this->assetType;
    }

    public function setAssetType(string $assetType): static
    {
        $this->assetType = $assetType;
        return $this;
    }

    public function getOwner(): ?string
    {
        return $this->owner;
    }

    public function setOwner(string $owner): static
    {
        $this->owner = $owner;
        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;
        return $this;
    }

    public function getConfidentialityValue(): ?int
    {
        return $this->confidentialityValue;
    }

    public function setConfidentialityValue(int $confidentialityValue): static
    {
        $this->confidentialityValue = $confidentialityValue;
        return $this;
    }

    public function getIntegrityValue(): ?int
    {
        return $this->integrityValue;
    }

    public function setIntegrityValue(int $integrityValue): static
    {
        $this->integrityValue = $integrityValue;
        return $this;
    }

    public function getAvailabilityValue(): ?int
    {
        return $this->availabilityValue;
    }

    public function setAvailabilityValue(int $availabilityValue): static
    {
        $this->availabilityValue = $availabilityValue;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, Risk>
     */
    public function getRisks(): Collection
    {
        return $this->risks;
    }

    public function addRisk(Risk $risk): static
    {
        if (!$this->risks->contains($risk)) {
            $this->risks->add($risk);
            $risk->setAsset($this);
        }
        return $this;
    }

    public function removeRisk(Risk $risk): static
    {
        if ($this->risks->removeElement($risk)) {
            if ($risk->getAsset() === $this) {
                $risk->setAsset(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Incident>
     */
    public function getIncidents(): Collection
    {
        return $this->incidents;
    }

    public function addIncident(Incident $incident): static
    {
        if (!$this->incidents->contains($incident)) {
            $this->incidents->add($incident);
            $incident->addAffectedAsset($this);
        }
        return $this;
    }

    public function removeIncident(Incident $incident): static
    {
        if ($this->incidents->removeElement($incident)) {
            $incident->removeAffectedAsset($this);
        }
        return $this;
    }

    /**
     * @return Collection<int, Control>
     */
    public function getProtectingControls(): Collection
    {
        return $this->protectingControls;
    }

    public function addProtectingControl(Control $control): static
    {
        if (!$this->protectingControls->contains($control)) {
            $this->protectingControls->add($control);
            $control->addProtectedAsset($this);
        }
        return $this;
    }

    public function removeProtectingControl(Control $control): static
    {
        if ($this->protectingControls->removeElement($control)) {
            $control->removeProtectedAsset($this);
        }
        return $this;
    }

    #[Groups(['asset:read'])]
    public function getTotalValue(): int
    {
        return max($this->confidentialityValue, $this->integrityValue, $this->availabilityValue);
    }

    /**
     * Get Asset Risk Score based on multiple factors
     * Data Reuse: Combines Risks, Incidents, Control Coverage
     */
    #[Groups(['asset:read'])]
    public function getRiskScore(): float
    {
        $score = 0;

        // Base score from CIA values
        $score += $this->getTotalValue() * 10;

        // Risks impact
        $activeRisks = $this->risks->filter(fn($r) => $r->getStatus() === 'active')->count();
        $score += $activeRisks * 5;

        // Incidents impact (recent incidents = higher risk)
        $recentIncidents = $this->incidents->filter(function($i) {
            $sixMonthsAgo = new \DateTime('-6 months');
            return $i->getDetectedAt() >= $sixMonthsAgo;
        })->count();
        $score += $recentIncidents * 10;

        // Control coverage (more controls = lower risk)
        $controlCount = $this->protectingControls->count();
        $score -= min($controlCount * 3, 30); // Max 30 points reduction

        return max(0, min(100, $score));
    }

    /**
     * Check if asset is high-risk (many incidents, high value)
     * Data Reuse: Automated risk classification
     */
    #[Groups(['asset:read'])]
    public function isHighRisk(): bool
    {
        return $this->getRiskScore() >= 70;
    }

    /**
     * Get protection status
     * Data Reuse: Shows if asset is adequately protected
     */
    #[Groups(['asset:read'])]
    public function getProtectionStatus(): string
    {
        $controlCount = $this->protectingControls->count();
        $riskCount = $this->risks->filter(fn($r) => $r->getStatus() === 'active')->count();

        if ($controlCount === 0 && $riskCount > 0) {
            return 'unprotected';
        } elseif ($controlCount < $riskCount) {
            return 'under_protected';
        } elseif ($controlCount >= $riskCount) {
            return 'adequately_protected';
        }

        return 'unknown';
    }
}
