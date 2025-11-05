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

    #[ORM\Column]
    private ?bool $applicable = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $applicabilityJustification = null;

    #[ORM\Column]
    private int $fulfillmentPercentage = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fulfillmentNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evidenceDescription = null;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'compliance_requirement_control')]
    private Collection $mappedControls;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dataSourceMapping = null; // Maps to Asset, Risk, BCM, etc.

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $responsiblePerson = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $targetDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastAssessmentDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->mappedControls = new ArrayCollection();
        $this->createdAt = new \DateTime();
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

    public function isApplicable(): ?bool
    {
        return $this->applicable;
    }

    public function setApplicable(bool $applicable): static
    {
        $this->applicable = $applicable;
        return $this;
    }

    public function getApplicabilityJustification(): ?string
    {
        return $this->applicabilityJustification;
    }

    public function setApplicabilityJustification(?string $applicabilityJustification): static
    {
        $this->applicabilityJustification = $applicabilityJustification;
        return $this;
    }

    public function getFulfillmentPercentage(): int
    {
        return $this->fulfillmentPercentage;
    }

    public function setFulfillmentPercentage(int $fulfillmentPercentage): static
    {
        $this->fulfillmentPercentage = $fulfillmentPercentage;
        return $this;
    }

    public function getFulfillmentNotes(): ?string
    {
        return $this->fulfillmentNotes;
    }

    public function setFulfillmentNotes(?string $fulfillmentNotes): static
    {
        $this->fulfillmentNotes = $fulfillmentNotes;
        return $this;
    }

    public function getEvidenceDescription(): ?string
    {
        return $this->evidenceDescription;
    }

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

    public function getDataSourceMapping(): ?array
    {
        return $this->dataSourceMapping;
    }

    public function setDataSourceMapping(?array $dataSourceMapping): static
    {
        $this->dataSourceMapping = $dataSourceMapping;
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

    public function getLastAssessmentDate(): ?\DateTimeInterface
    {
        return $this->lastAssessmentDate;
    }

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
}
