<?php

namespace App\Entity;

use App\Repository\ComplianceRequirementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ComplianceRequirementRepository::class)]
class ComplianceRequirement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'requirements')]
    #[ORM\JoinColumn(nullable: false)]
    private ?ComplianceFramework $framework = null;

    #[ORM\Column(length: 50)]
    private ?string $requirementId = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 50)]
    private ?string $priority = null; // critical, high, medium, low

    #[ORM\Column(length: 50)]
    private string $requirementType = 'core'; // core, detailed, sub_requirement

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'detailedRequirements')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?ComplianceRequirement $parentRequirement = null;

    /**
     * @var Collection<int, ComplianceRequirement>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parentRequirement', cascade: ['persist', 'remove'])]
    private Collection $detailedRequirements;

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     * These fields will be removed in Phase 2B after full migration
     */
    #[ORM\Column]
    private ?bool $applicable = true;

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $applicabilityJustification = null;

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    #[ORM\Column]
    private int $fulfillmentPercentage = 0;

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fulfillmentNotes = null;

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evidenceDescription = null;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'compliance_requirement_control')]
    private Collection $mappedControls;

    /**
     * @var Collection<int, Training>
     * Phase 6K: Training ↔ ComplianceRequirement inverse relationship
     * Tracks which trainings fulfill this compliance requirement
     */
    #[ORM\ManyToMany(targetEntity: Training::class, mappedBy: 'complianceRequirements')]
    private Collection $trainings;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dataSourceMapping = null; // Maps to Asset, Risk, BCM, etc.

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $responsiblePerson = null;

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $targetDate = null;

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastAssessmentDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->mappedControls = new ArrayCollection();
        $this->trainings = new ArrayCollection();
        $this->detailedRequirements = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFramework(): ?ComplianceFramework
    {
        return $this->framework;
    }

    public function setFramework(?ComplianceFramework $framework): static
    {
        $this->framework = $framework;
        return $this;
    }

    public function getRequirementId(): ?string
    {
        return $this->requirementId;
    }

    public function setRequirementId(string $requirementId): static
    {
        $this->requirementId = $requirementId;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
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

    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getPriority(): ?string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function isApplicable(): ?bool
    {
        return $this->applicable;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function setApplicable(bool $applicable): static
    {
        $this->applicable = $applicable;
        return $this;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function getApplicabilityJustification(): ?string
    {
        return $this->applicabilityJustification;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function setApplicabilityJustification(?string $applicabilityJustification): static
    {
        $this->applicabilityJustification = $applicabilityJustification;
        return $this;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function getFulfillmentPercentage(): int
    {
        return $this->fulfillmentPercentage;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function setFulfillmentPercentage(int $fulfillmentPercentage): static
    {
        $this->fulfillmentPercentage = $fulfillmentPercentage;
        return $this;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function getFulfillmentNotes(): ?string
    {
        return $this->fulfillmentNotes;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function setFulfillmentNotes(?string $fulfillmentNotes): static
    {
        $this->fulfillmentNotes = $fulfillmentNotes;
        return $this;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function getEvidenceDescription(): ?string
    {
        return $this->evidenceDescription;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function setEvidenceDescription(?string $evidenceDescription): static
    {
        $this->evidenceDescription = $evidenceDescription;
        return $this;
    }

    /**
     * @return Collection<int, Control>
     */
    public function getMappedControls(): Collection
    {
        return $this->mappedControls;
    }

    public function addMappedControl(Control $mappedControl): static
    {
        if (!$this->mappedControls->contains($mappedControl)) {
            $this->mappedControls->add($mappedControl);
        }

        return $this;
    }

    public function removeMappedControl(Control $mappedControl): static
    {
        $this->mappedControls->removeElement($mappedControl);
        return $this;
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
            $training->addComplianceRequirement($this);
        }
        return $this;
    }

    public function removeTraining(Training $training): static
    {
        if ($this->trainings->removeElement($training)) {
            $training->removeComplianceRequirement($this);
        }
        return $this;
    }

    /**
     * Check if requirement has training coverage
     * Data Reuse: Training completion affects requirement fulfillment
     */
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
     * Get training coverage percentage
     * Data Reuse: Calculate how well this requirement is supported by trainings
     */
    public function getTrainingCoveragePercentage(): float
    {
        if ($this->trainings->isEmpty()) {
            return 0.0;
        }

        $completedCount = 0;
        foreach ($this->trainings as $training) {
            if ($training->getStatus() === 'completed') {
                $completedCount++;
            }
        }

        return round(($completedCount / $this->trainings->count()) * 100, 2);
    }

    public function getDataSourceMapping(): ?array
    {
        return $this->dataSourceMapping;
    }

    public function setDataSourceMapping(?array $dataSourceMapping): static
    {
        $this->dataSourceMapping = $dataSourceMapping;
        return $this;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function getResponsiblePerson(): ?string
    {
        return $this->responsiblePerson;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function setResponsiblePerson(?string $responsiblePerson): static
    {
        $this->responsiblePerson = $responsiblePerson;
        return $this;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function getTargetDate(): ?\DateTimeInterface
    {
        return $this->targetDate;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function setTargetDate(?\DateTimeInterface $targetDate): static
    {
        $this->targetDate = $targetDate;
        return $this;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function getLastAssessmentDate(): ?\DateTimeInterface
    {
        return $this->lastAssessmentDate;
    }

    /**
     * @deprecated Use ComplianceRequirementFulfillment instead
     */
    public function setLastAssessmentDate(?\DateTimeInterface $lastAssessmentDate): static
    {
        $this->lastAssessmentDate = $lastAssessmentDate;
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
     * Calculate fulfillment based on mapped controls
     */
    public function calculateFulfillmentFromControls(): int
    {
        if ($this->mappedControls->isEmpty()) {
            return 0;
        }

        $totalImplementation = 0;
        $implementedControls = 0;

        foreach ($this->mappedControls as $control) {
            if ($control->getImplementationStatus() === 'implemented') {
                $totalImplementation += $control->getImplementationPercentage() ?? 100;
                $implementedControls++;
            } elseif ($control->getImplementationStatus() === 'in_progress') {
                $totalImplementation += ($control->getImplementationPercentage() ?? 50);
            }
        }

        if ($this->mappedControls->count() === 0) {
            return 0;
        }

        return (int) round($totalImplementation / $this->mappedControls->count());
    }

    /**
     * Get fulfillment status badge class
     */
    public function getFulfillmentStatusBadge(): string
    {
        if (!$this->applicable) {
            return 'secondary';
        }

        if ($this->fulfillmentPercentage >= 100) {
            return 'success';
        } elseif ($this->fulfillmentPercentage >= 75) {
            return 'info';
        } elseif ($this->fulfillmentPercentage >= 50) {
            return 'warning';
        } else {
            return 'danger';
        }
    }

    public function getRequirementType(): string
    {
        return $this->requirementType;
    }

    public function setRequirementType(string $requirementType): static
    {
        $this->requirementType = $requirementType;
        return $this;
    }

    public function getParentRequirement(): ?self
    {
        return $this->parentRequirement;
    }

    public function setParentRequirement(?self $parentRequirement): static
    {
        $this->parentRequirement = $parentRequirement;
        return $this;
    }

    /**
     * @return Collection<int, ComplianceRequirement>
     */
    public function getDetailedRequirements(): Collection
    {
        return $this->detailedRequirements;
    }

    public function addDetailedRequirement(ComplianceRequirement $detailedRequirement): static
    {
        if (!$this->detailedRequirements->contains($detailedRequirement)) {
            $this->detailedRequirements->add($detailedRequirement);
            $detailedRequirement->setParentRequirement($this);
        }

        return $this;
    }

    public function removeDetailedRequirement(ComplianceRequirement $detailedRequirement): static
    {
        if ($this->detailedRequirements->removeElement($detailedRequirement)) {
            if ($detailedRequirement->getParentRequirement() === $this) {
                $detailedRequirement->setParentRequirement(null);
            }
        }

        return $this;
    }

    /**
     * Check if this is a core requirement (has no parent)
     */
    public function isCoreRequirement(): bool
    {
        return $this->parentRequirement === null && $this->requirementType === 'core';
    }

    /**
     * Check if this requirement has detailed sub-requirements
     */
    public function hasDetailedRequirements(): bool
    {
        return !$this->detailedRequirements->isEmpty();
    }

    /**
     * Get fulfillment including detailed requirements
     * Aggregates fulfillment from all detailed requirements
     */
    public function getAggregatedFulfillment(): float
    {
        if ($this->detailedRequirements->isEmpty()) {
            return $this->fulfillmentPercentage;
        }

        $totalFulfillment = $this->fulfillmentPercentage;
        $count = 1;

        foreach ($this->detailedRequirements as $detailed) {
            if ($detailed->isApplicable()) {
                $totalFulfillment += $detailed->getFulfillmentPercentage();
                $count++;
            }
        }

        return round($totalFulfillment / $count, 2);
    }

    /**
     * Get count of applicable detailed requirements
     */
    public function getApplicableDetailedCount(): int
    {
        return $this->detailedRequirements->filter(fn($req) => $req->isApplicable())->count();
    }

    /**
     * Get count of fulfilled detailed requirements (>= 100%)
     */
    public function getFulfilledDetailedCount(): int
    {
        return $this->detailedRequirements->filter(
            fn($req) => $req->isApplicable() && $req->getFulfillmentPercentage() >= 100
        )->count();
    }
}
