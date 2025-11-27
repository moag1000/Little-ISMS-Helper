<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
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
    #[ORM\JoinColumn(name: 'framework_id', nullable: false)]
    private ?ComplianceFramework $complianceFramework = null;

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
    #[ORM\JoinColumn(name: 'parent_requirement_id', nullable: true, onDelete: 'CASCADE')]
    private ?ComplianceRequirement $complianceRequirement = null;

    /**
     * @var Collection<int, ComplianceRequirement>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'complianceRequirement', cascade: ['persist', 'remove'])]
    private Collection $detailedRequirements;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(
        name: 'compliance_requirement_control',
        joinColumns: [new ORM\JoinColumn(onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(onDelete: 'CASCADE')]
    )]
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->mappedControls = new ArrayCollection();
        $this->trainings = new ArrayCollection();
        $this->detailedRequirements = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFramework(): ?ComplianceFramework
    {
        return $this->complianceFramework;
    }

    public function setFramework(?ComplianceFramework $complianceFramework): static
    {
        $this->complianceFramework = $complianceFramework;
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
     * Calculate fulfillment based on mapped controls
     */
    public function calculateFulfillmentFromControls(): int
    {
        if ($this->mappedControls->isEmpty()) {
            return 0;
        }

        $totalImplementation = 0;
        $implementedControls = 0;

        foreach ($this->mappedControls as $mappedControl) {
            if ($mappedControl->getImplementationStatus() === 'implemented') {
                $totalImplementation += $mappedControl->getImplementationPercentage() ?? 100;
                $implementedControls++;
            } elseif ($mappedControl->getImplementationStatus() === 'in_progress') {
                $totalImplementation += ($mappedControl->getImplementationPercentage() ?? 50);
            }
        }

        if ($this->mappedControls->count() === 0) {
            return 0;
        }

        return (int) round($totalImplementation / $this->mappedControls->count());
    }

    /**
     * Get fulfillment percentage (alias for calculateFulfillmentFromControls)
     * Used by ComplianceMapping for transitive fulfillment calculations
     */
    public function getFulfillmentPercentage(): float
    {
        return (float) $this->calculateFulfillmentFromControls();
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
        return $this->complianceRequirement;
    }

    public function setParentRequirement(?self $parentRequirement): static
    {
        $this->complianceRequirement = $parentRequirement;
        return $this;
    }

    /**
     * @return Collection<int, ComplianceRequirement>
     */
    public function getDetailedRequirements(): Collection
    {
        return $this->detailedRequirements;
    }

    public function addDetailedRequirement(ComplianceRequirement $complianceRequirement): static
    {
        if (!$this->detailedRequirements->contains($complianceRequirement)) {
            $this->detailedRequirements->add($complianceRequirement);
            $complianceRequirement->setParentRequirement($this);
        }

        return $this;
    }

    public function removeDetailedRequirement(ComplianceRequirement $complianceRequirement): static
    {
        if ($this->detailedRequirements->removeElement($complianceRequirement) && $complianceRequirement->getParentRequirement() === $this) {
            $complianceRequirement->setParentRequirement(null);
        }

        return $this;
    }

    /**
     * Check if this is a core requirement (has no parent)
     */
    public function isCoreRequirement(): bool
    {
        return $this->complianceRequirement === null && $this->requirementType === 'core';
    }

    /**
     * Check if this requirement has detailed sub-requirements
     */
    public function hasDetailedRequirements(): bool
    {
        return !$this->detailedRequirements->isEmpty();
    }
}
