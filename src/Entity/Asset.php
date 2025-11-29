<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
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
#[ORM\Index(name: 'idx_asset_type', columns: ['asset_type'])]
#[ORM\Index(name: 'idx_asset_status', columns: ['status'])]
#[ORM\Index(name: 'idx_asset_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_asset_tenant', columns: ['tenant_id'])]
#[ApiResource(
    operations: [
        new Get(
            description: 'Retrieve a specific asset by ID',
            security: "is_granted('ROLE_USER')"
        ),
        new GetCollection(
            description: 'Retrieve the collection of assets with pagination and filtering',
            security: "is_granted('ROLE_USER')"
        ),
        new Post(
            description: 'Create a new asset with protection requirements',
            security: "is_granted('ROLE_USER')"
        ),
        new Put(
            description: 'Update an existing asset',
            security: "is_granted('ROLE_USER')"
        ),
        new Delete(
            description: 'Delete an asset (Admin only)',
            security: "is_granted('ROLE_ADMIN')"
        ),
    ],
    normalizationContext: ['groups' => ['asset:read']],
    denormalizationContext: ['groups' => ['asset:write']]
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

    // New relationship for data reuse
    #[ORM\ManyToOne(targetEntity: Location::class, inversedBy: 'assets')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    private ?Location $physicalLocation = null;

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

    // Legacy field - kept for backward compatibility
    // @deprecated Use $physicalLocation instead
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\Length(max: 100, maxMessage: 'Location cannot exceed {{ limit }} characters')]
    private ?string $location = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    private ?string $acquisitionValue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    private ?string $currentValue = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\NotNull(message: 'Confidentiality value is required')]
    #[Assert\Range(notInRangeMessage: 'Confidentiality value must be between {{ min }} and {{ max }}', min: 1, max: 5)]
    private ?int $confidentialityValue = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\NotNull(message: 'Integrity value is required')]
    #[Assert\Range(notInRangeMessage: 'Integrity value must be between {{ min }} and {{ max }}', min: 1, max: 5)]
    private ?int $integrityValue = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\NotNull(message: 'Availability value is required')]
    #[Assert\Range(notInRangeMessage: 'Availability value must be between {{ min }} and {{ max }}', min: 1, max: 5)]
    private ?int $availabilityValue = null;

    // Phase 6F: ISO 27001 Compliance Fields

    /**
     * Monetary value of the asset for risk impact calculation.
     * ⚠️ SAFE GUARD: This field must ALWAYS be set manually by users.
     * NEVER auto-calculate from vulnerabilityScore or other sources to prevent circular dependencies.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\PositiveOrZero(message: 'Monetary value must be positive or zero')]
    private ?string $monetaryValue = null;

    /**
     * Data classification level for the asset.
     * Values: public, internal, confidential, restricted
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\Choice(
        choices: ['public', 'internal', 'confidential', 'restricted'],
        message: 'Data classification must be one of: {{ choices }}'
    )]
    private ?string $dataClassification = null;

    /**
     * Acceptable Use Policy reference or description for this asset.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    private ?string $acceptableUsePolicy = null;

    /**
     * Specific handling instructions for this asset (supports Markdown).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    private ?string $handlingInstructions = null;

    /**
     * Return date for assets that need to be returned (e.g., leased equipment).
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['asset:read', 'asset:write'])]
    private ?DateTimeInterface $returnDate = null;

    #[ORM\Column(length: 50)]
    #[Groups(['asset:read', 'asset:write'])]
    #[Assert\NotBlank(message: 'Status is required')]
    #[Assert\Choice(
        choices: ['active', 'inactive', 'in_use', 'returned', 'retired', 'disposed'],
        message: 'Status must be one of: {{ choices }}'
    )]
    private ?string $status = 'active';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['asset:read'])]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['asset:read'])]
    private ?DateTimeInterface $updatedAt = null;

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

    public function getAcquisitionValue(): ?string
    {
        return $this->acquisitionValue;
    }

    public function setAcquisitionValue(?string $acquisitionValue): static
    {
        $this->acquisitionValue = $acquisitionValue;
        return $this;
    }

    public function getCurrentValue(): ?string
    {
        return $this->currentValue;
    }

    public function setCurrentValue(?string $currentValue): static
    {
        $this->currentValue = $currentValue;
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

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
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
        if ($this->risks->removeElement($risk) && $risk->getAsset() === $this) {
            $risk->setAsset(null);
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

    // Note: Full risk calculation logic moved to AssetRiskCalculator service (Symfony best practice)
    // Computed properties (riskScore, protectionStatus) are added during serialization via AssetNormalizer

    /**
     * Simple high-risk check for entity filtering
     *
     * This method provides a quick high-risk classification for use in Collection filtering
     * (e.g., Control::getHighRiskAssetCount(), Incident::hasCriticalAssetsAffected()).
     *
     * For full risk score calculation, use AssetRiskCalculator service.
     *
     * Threshold: Total CIA value >= 4 OR has active risks
     */
    public function isHighRisk(): bool
    {
        // High CIA value assets are considered high-risk
        if ($this->getTotalValue() >= 4) {
            return true;
        }

        // Assets with active risks are high-risk
        $activeRisks = $this->risks->filter(fn($r): bool => $r->getStatus() === 'active')->count();
        return $activeRisks > 0;
    }

    // Getter/Setter for Phase 6F ISO 27001 Compliance Fields

    public function getMonetaryValue(): ?string
    {
        return $this->monetaryValue;
    }

    public function setMonetaryValue(?string $monetaryValue): static
    {
        $this->monetaryValue = $monetaryValue;
        return $this;
    }

    public function getDataClassification(): ?string
    {
        return $this->dataClassification;
    }

    public function setDataClassification(?string $dataClassification): static
    {
        $this->dataClassification = $dataClassification;
        return $this;
    }

    public function getAcceptableUsePolicy(): ?string
    {
        return $this->acceptableUsePolicy;
    }

    public function setAcceptableUsePolicy(?string $acceptableUsePolicy): static
    {
        $this->acceptableUsePolicy = $acceptableUsePolicy;
        return $this;
    }

    public function getHandlingInstructions(): ?string
    {
        return $this->handlingInstructions;
    }

    public function setHandlingInstructions(?string $handlingInstructions): static
    {
        $this->handlingInstructions = $handlingInstructions;
        return $this;
    }

    public function getReturnDate(): ?DateTimeInterface
    {
        return $this->returnDate;
    }

    public function setReturnDate(?DateTimeInterface $returnDate): static
    {
        $this->returnDate = $returnDate;
        return $this;
    }

    public function getPhysicalLocation(): ?Location
    {
        return $this->physicalLocation;
    }

    public function setPhysicalLocation(?Location $physicalLocation): static
    {
        $this->physicalLocation = $physicalLocation;

        // Sync legacy field for backward compatibility
        if ($physicalLocation instanceof Location) {
            $this->location = $physicalLocation->getName();
        }

        return $this;
    }

    /**
     * Get effective location (from Location entity or legacy field)
     */
    public function getEffectiveLocation(): ?string
    {
        return $this->physicalLocation?->getName() ?? $this->location;
    }
}
