<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\ControlRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ControlRepository::class)]
#[ORM\Index(columns: ['control_id'], name: 'idx_control_control_id')]
#[ORM\Index(columns: ['category'], name: 'idx_control_category')]
#[ORM\Index(columns: ['implementation_status'], name: 'idx_control_impl_status')]
#[ORM\Index(columns: ['target_date'], name: 'idx_control_target_date')]
#[ORM\Index(columns: ['applicable'], name: 'idx_control_applicable')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_control_tenant')]
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['control:read']],
    denormalizationContext: ['groups' => ['control:write']],
    paginationItemsPerPage: 30
)]
#[ApiFilter(SearchFilter::class, properties: ['controlId' => 'exact', 'name' => 'partial', 'category' => 'exact', 'implementationStatus' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['applicable'])]
#[ApiFilter(OrderFilter::class, properties: ['controlId', 'category', 'implementationPercentage', 'targetDate'])]
#[ApiFilter(DateFilter::class, properties: ['targetDate', 'lastReviewDate'])]
class Control
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['control:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['control:read'])]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 20)]
    #[Groups(['control:read', 'control:write', 'risk:read'])]
    #[Assert\NotBlank(message: 'Control ID is required')]
    #[Assert\Length(max: 20, maxMessage: 'Control ID cannot exceed {{ limit }} characters')]
    #[Assert\Regex(
        pattern: '/^\d+\.\d+(\.\d+)?$/',
        message: 'Control ID must follow ISO 27001 format (e.g., 5.1, 8.3)'
    )]
    private ?string $controlId = null;

    #[ORM\Column(length: 255)]
    #[Groups(['control:read', 'control:write'])]
    #[Assert\NotBlank(message: 'Control name is required')]
    #[Assert\Length(max: 255, maxMessage: 'Control name cannot exceed {{ limit }} characters')]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['control:read', 'control:write'])]
    #[Assert\NotBlank(message: 'Control description is required')]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    #[Groups(['control:read', 'control:write'])]
    #[Assert\NotBlank(message: 'Control category is required')]
    #[Assert\Length(max: 100, maxMessage: 'Category cannot exceed {{ limit }} characters')]
    private ?string $category = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['control:read', 'control:write'])]
    #[Assert\NotNull(message: 'Applicable flag is required')]
    private ?bool $applicable = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['control:read', 'control:write'])]
    private ?string $justification = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['control:read', 'control:write'])]
    private ?string $implementationNotes = null;

    #[ORM\Column(length: 50)]
    #[Groups(['control:read', 'control:write'])]
    #[Assert\NotBlank(message: 'Implementation status is required')]
    #[Assert\Choice(
        choices: ['not_started', 'planned', 'in_progress', 'implemented', 'verified'],
        message: 'Implementation status must be one of: {{ choices }}'
    )]
    private ?string $implementationStatus = 'not_started';

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['control:read', 'control:write'])]
    #[Assert\Range(
        min: 0,
        max: 100,
        notInRangeMessage: 'Implementation percentage must be between {{ min }} and {{ max }}'
    )]
    private ?int $implementationPercentage = 0;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['control:read', 'control:write'])]
    #[Assert\Length(max: 100, maxMessage: 'Responsible person cannot exceed {{ limit }} characters')]
    private ?string $responsiblePerson = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['control:read', 'control:write'])]
    private ?\DateTimeInterface $targetDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['control:read', 'control:write'])]
    private ?\DateTimeInterface $lastReviewDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['control:read', 'control:write'])]
    private ?\DateTimeInterface $nextReviewDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['control:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['control:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Risk>
     */
    #[ORM\ManyToMany(targetEntity: Risk::class, inversedBy: 'controls')]
    #[Groups(['control:read'])]
    #[MaxDepth(1)]
    private Collection $risks;

    /**
     * @var Collection<int, Incident>
     */
    #[ORM\ManyToMany(targetEntity: Incident::class, mappedBy: 'relatedControls')]
    #[Groups(['control:read'])]
    #[MaxDepth(1)]
    private Collection $incidents;

    /**
     * @var Collection<int, Asset>
     */
    #[ORM\ManyToMany(targetEntity: Asset::class, inversedBy: 'protectingControls')]
    #[ORM\JoinTable(name: 'control_asset')]
    #[Groups(['control:read'])]
    #[MaxDepth(1)]
    private Collection $protectedAssets;

    /**
     * @var Collection<int, Training>
     */
    #[ORM\ManyToMany(targetEntity: Training::class, mappedBy: 'coveredControls')]
    #[Groups(['control:read'])]
    #[MaxDepth(1)]
    private Collection $trainings;

    public function __construct()
    {
        $this->risks = new ArrayCollection();
        $this->incidents = new ArrayCollection();
        $this->protectedAssets = new ArrayCollection();
        $this->trainings = new ArrayCollection();
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

    public function getControlId(): ?string
    {
        return $this->controlId;
    }

    public function setControlId(string $controlId): static
    {
        $this->controlId = $controlId;
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

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function isApplicable(): ?bool
    {
        return $this->applicable;
    }

    public function setApplicable(bool $applicable): static
    {
        $this->applicable = $applicable;
        return $this;
    }

    public function getJustification(): ?string
    {
        return $this->justification;
    }

    public function setJustification(?string $justification): static
    {
        $this->justification = $justification;
        return $this;
    }

    public function getImplementationNotes(): ?string
    {
        return $this->implementationNotes;
    }

    public function setImplementationNotes(?string $implementationNotes): static
    {
        $this->implementationNotes = $implementationNotes;
        return $this;
    }

    public function getImplementationStatus(): ?string
    {
        return $this->implementationStatus;
    }

    public function setImplementationStatus(string $implementationStatus): static
    {
        $this->implementationStatus = $implementationStatus;
        return $this;
    }

    public function getImplementationPercentage(): ?int
    {
        return $this->implementationPercentage;
    }

    public function setImplementationPercentage(?int $implementationPercentage): static
    {
        $this->implementationPercentage = $implementationPercentage;
        return $this;
    }

    public function getResponsiblePerson(): ?string
    {
        return $this->responsiblePerson;
    }

    public function setResponsiblePerson(?string $responsiblePerson): static
    {
        $this->responsiblePerson = $responsiblePerson;
        return $this;
    }

    public function getTargetDate(): ?\DateTimeInterface
    {
        return $this->targetDate;
    }

    public function setTargetDate(?\DateTimeInterface $targetDate): static
    {
        $this->targetDate = $targetDate;
        return $this;
    }

    public function getLastReviewDate(): ?\DateTimeInterface
    {
        return $this->lastReviewDate;
    }

    public function setLastReviewDate(?\DateTimeInterface $lastReviewDate): static
    {
        $this->lastReviewDate = $lastReviewDate;
        return $this;
    }

    public function getNextReviewDate(): ?\DateTimeInterface
    {
        return $this->nextReviewDate;
    }

    public function setNextReviewDate(?\DateTimeInterface $nextReviewDate): static
    {
        $this->nextReviewDate = $nextReviewDate;
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
        }
        return $this;
    }

    public function removeRisk(Risk $risk): static
    {
        $this->risks->removeElement($risk);
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
            $incident->addRelatedControl($this);
        }
        return $this;
    }

    public function removeIncident(Incident $incident): static
    {
        if ($this->incidents->removeElement($incident)) {
            $incident->removeRelatedControl($this);
        }
        return $this;
    }

    /**
     * @return Collection<int, Asset>
     */
    public function getProtectedAssets(): Collection
    {
        return $this->protectedAssets;
    }

    public function addProtectedAsset(Asset $asset): static
    {
        if (!$this->protectedAssets->contains($asset)) {
            $this->protectedAssets->add($asset);
        }
        return $this;
    }

    public function removeProtectedAsset(Asset $asset): static
    {
        $this->protectedAssets->removeElement($asset);
        return $this;
    }

    /**
     * Get total value of protected assets
     * Data Reuse: Aggregates asset CIA values to show control importance
     */
    #[Groups(['control:read'])]
    public function getProtectedAssetValue(): int
    {
        $total = 0;
        foreach ($this->protectedAssets as $asset) {
            $total += $asset->getTotalValue();
        }
        return $total;
    }

    /**
     * Get count of high-risk assets protected
     * Data Reuse: Uses Asset risk scoring
     */
    #[Groups(['control:read'])]
    public function getHighRiskAssetCount(): int
    {
        return $this->protectedAssets->filter(fn($asset) => $asset->isHighRisk())->count();
    }

    /**
     * Calculate control effectiveness based on incidents
     * Data Reuse: Compare protected assets' incidents before/after control
     */
    #[Groups(['control:read'])]
    public function getEffectivenessScore(): float
    {
        if ($this->implementationPercentage < 100) {
            return 0; // Not fully implemented yet
        }

        // Count incidents on protected assets after control implementation
        $incidentsAfterControl = 0;
        $implementationDate = $this->lastReviewDate ?? $this->createdAt;

        foreach ($this->protectedAssets as $asset) {
            foreach ($asset->getIncidents() as $incident) {
                if ($incident->getDetectedAt() >= $implementationDate) {
                    $incidentsAfterControl++;
                }
            }
        }

        // Fewer incidents = higher effectiveness
        $assetCount = $this->protectedAssets->count();
        if ($assetCount === 0) {
            return 100; // No assets to protect
        }

        // Score: 100 - (incidents per asset * 20)
        $incidentsPerAsset = $incidentsAfterControl / $assetCount;
        return max(0, min(100, 100 - ($incidentsPerAsset * 20)));
    }

    /**
     * Check if control needs review based on recent incidents
     * Data Reuse: Automatic review trigger from incident data
     */
    #[Groups(['control:read'])]
    public function isReviewNeeded(): bool
    {
        // Check if there are recent incidents affecting protected assets
        $threeMonthsAgo = new \DateTime('-3 months');

        foreach ($this->protectedAssets as $asset) {
            foreach ($asset->getIncidents() as $incident) {
                if ($incident->getDetectedAt() >= $threeMonthsAgo) {
                    return true; // Recent incident = needs review
                }
            }
        }

        // Also check regular review schedule
        if ($this->nextReviewDate && $this->nextReviewDate < new \DateTime()) {
            return true;
        }

        return false;
    }

    /**
     * @return Collection<int, Training>
     */
    public function getTrainings(): Collection
    {
        return $this->trainings;
    }

    public function addTraining(Training $training): static
    {
        if (!$this->trainings->contains($training)) {
            $this->trainings->add($training);
            $training->addCoveredControl($this);
        }
        return $this;
    }

    public function removeTraining(Training $training): static
    {
        if ($this->trainings->removeElement($training)) {
            $training->removeCoveredControl($this);
        }
        return $this;
    }

    /**
     * Check if control has adequate training coverage
     * Data Reuse: Training status affects control implementation
     */
    #[Groups(['control:read'])]
    public function hasTrainingCoverage(): bool
    {
        foreach ($this->trainings as $training) {
            if ($training->getStatus() === 'completed') {
                return true;
            }
        }
        return false;
    }

    /**
     * Get training gap analysis
     * Data Reuse: Identifies missing or outdated training
     */
    #[Groups(['control:read'])]
    public function getTrainingStatus(): string
    {
        if ($this->trainings->isEmpty()) {
            return 'no_training';
        }

        $hasCompleted = false;
        $hasPlanned = false;
        $mostRecentDate = null;

        foreach ($this->trainings as $training) {
            if ($training->getStatus() === 'completed') {
                $hasCompleted = true;
                $completionDate = $training->getCompletionDate();
                if ($completionDate && (!$mostRecentDate || $completionDate > $mostRecentDate)) {
                    $mostRecentDate = $completionDate;
                }
            } elseif ($training->getStatus() === 'planned') {
                $hasPlanned = true;
            }
        }

        if ($hasCompleted) {
            // Check if training is outdated (>1 year old)
            $oneYearAgo = new \DateTime('-1 year');
            if ($mostRecentDate && $mostRecentDate < $oneYearAgo) {
                return 'training_outdated';
            }
            return 'training_current';
        }

        if ($hasPlanned) {
            return 'training_planned';
        }

        return 'training_incomplete';
    }
}
