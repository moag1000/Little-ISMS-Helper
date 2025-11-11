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
use App\Repository\TrainingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TrainingRepository::class)]
#[ORM\Index(columns: ['training_type'], name: 'idx_training_type')]
#[ORM\Index(columns: ['status'], name: 'idx_training_status')]
#[ORM\Index(columns: ['scheduled_date'], name: 'idx_training_scheduled_date')]
#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('ROLE_USER')",
            description: 'Retrieve a specific security awareness training by ID'
        ),
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            description: 'Retrieve the collection of security awareness trainings with filtering by type, status, and date'
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            description: 'Create a new security awareness training event'
        ),
        new Put(
            security: "is_granted('ROLE_USER')",
            description: 'Update an existing training event'
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
            description: 'Delete a training event (Admin only)'
        ),
    ],
    normalizationContext: ['groups' => ['training:read']],
    denormalizationContext: ['groups' => ['training:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['title' => 'partial', 'trainingType' => 'exact', 'status' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['mandatory'])]
#[ApiFilter(OrderFilter::class, properties: ['scheduledDate', 'status'])]
#[ApiFilter(DateFilter::class, properties: ['scheduledDate', 'completionDate'])]
class Training
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['training:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['training:read', 'training:write'])]
    #[Assert\NotBlank(message: 'Training title is required')]
    #[Assert\Length(max: 255, maxMessage: 'Title cannot exceed {{ limit }} characters')]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['training:read', 'training:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    #[Groups(['training:read', 'training:write'])]
    #[Assert\NotBlank(message: 'Training type is required')]
    #[Assert\Length(max: 100, maxMessage: 'Training type cannot exceed {{ limit }} characters')]
    private ?string $trainingType = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['training:read', 'training:write'])]
    #[Assert\NotNull(message: 'Scheduled date is required')]
    private ?\DateTimeInterface $scheduledDate = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['training:read', 'training:write'])]
    #[Assert\Positive(message: 'Duration must be a positive number')]
    private ?int $durationMinutes = null;

    #[ORM\Column(length: 100)]
    #[Groups(['training:read', 'training:write'])]
    #[Assert\NotBlank(message: 'Trainer name is required')]
    #[Assert\Length(max: 100, maxMessage: 'Trainer name cannot exceed {{ limit }} characters')]
    private ?string $trainer = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['training:read', 'training:write'])]
    private ?string $targetAudience = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['training:read', 'training:write'])]
    private ?string $participants = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['training:read', 'training:write'])]
    #[Assert\PositiveOrZero(message: 'Attendee count must be zero or positive')]
    private ?int $attendeeCount = 0;

    #[ORM\Column(length: 50)]
    #[Groups(['training:read', 'training:write'])]
    #[Assert\NotBlank(message: 'Status is required')]
    #[Assert\Choice(
        choices: ['planned', 'scheduled', 'in_progress', 'completed', 'cancelled'],
        message: 'Status must be one of: {{ choices }}'
    )]
    private ?string $status = 'planned';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['training:read', 'training:write'])]
    private ?string $materials = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['training:read', 'training:write'])]
    private ?string $feedback = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['training:read', 'training:write'])]
    private ?\DateTimeInterface $completionDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['training:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['training:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'training_control')]
    #[Groups(['training:read'])]
    #[MaxDepth(1)]
    private Collection $coveredControls;

    /**
     * @var Collection<int, ComplianceRequirement>
     * Phase 6J: Training ↔ ComplianceRequirement relationship for compliance training tracking
     */
    #[ORM\ManyToMany(targetEntity: ComplianceRequirement::class)]
    #[ORM\JoinTable(name: 'training_compliance_requirement')]
    #[Groups(['training:read'])]
    #[MaxDepth(1)]
    private Collection $complianceRequirements;

    public function __construct()
    {
        $this->coveredControls = new ArrayCollection();
        $this->complianceRequirements = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getTrainingType(): ?string
    {
        return $this->trainingType;
    }

    public function setTrainingType(string $trainingType): static
    {
        $this->trainingType = $trainingType;
        return $this;
    }

    public function getScheduledDate(): ?\DateTimeInterface
    {
        return $this->scheduledDate;
    }

    public function setScheduledDate(\DateTimeInterface $scheduledDate): static
    {
        $this->scheduledDate = $scheduledDate;
        return $this;
    }

    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }

    public function setDurationMinutes(?int $durationMinutes): static
    {
        $this->durationMinutes = $durationMinutes;
        return $this;
    }

    public function getTrainer(): ?string
    {
        return $this->trainer;
    }

    public function setTrainer(string $trainer): static
    {
        $this->trainer = $trainer;
        return $this;
    }

    public function getTargetAudience(): ?string
    {
        return $this->targetAudience;
    }

    public function setTargetAudience(?string $targetAudience): static
    {
        $this->targetAudience = $targetAudience;
        return $this;
    }

    public function getParticipants(): ?string
    {
        return $this->participants;
    }

    public function setParticipants(?string $participants): static
    {
        $this->participants = $participants;
        return $this;
    }

    public function getAttendeeCount(): ?int
    {
        return $this->attendeeCount;
    }

    public function setAttendeeCount(?int $attendeeCount): static
    {
        $this->attendeeCount = $attendeeCount;
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

    public function getMaterials(): ?string
    {
        return $this->materials;
    }

    public function setMaterials(?string $materials): static
    {
        $this->materials = $materials;
        return $this;
    }

    public function getFeedback(): ?string
    {
        return $this->feedback;
    }

    public function setFeedback(?string $feedback): static
    {
        $this->feedback = $feedback;
        return $this;
    }

    public function getCompletionDate(): ?\DateTimeInterface
    {
        return $this->completionDate;
    }

    public function setCompletionDate(?\DateTimeInterface $completionDate): static
    {
        $this->completionDate = $completionDate;
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
     * @return Collection<int, Control>
     */
    public function getCoveredControls(): Collection
    {
        return $this->coveredControls;
    }

    public function addCoveredControl(Control $control): static
    {
        if (!$this->coveredControls->contains($control)) {
            $this->coveredControls->add($control);
        }
        return $this;
    }

    public function removeCoveredControl(Control $control): static
    {
        $this->coveredControls->removeElement($control);
        return $this;
    }

    /**
     * Get count of ISO 27001 controls covered
     * Data Reuse: Shows training impact on compliance
     */
    #[Groups(['training:read'])]
    public function getControlCoverageCount(): int
    {
        return $this->coveredControls->count();
    }

    /**
     * Calculate training effectiveness based on control implementation
     * Data Reuse: Training completion should correlate with control implementation
     */
    #[Groups(['training:read'])]
    public function getTrainingEffectiveness(): ?float
    {
        if ($this->status !== 'completed' || $this->coveredControls->isEmpty()) {
            return null; // Cannot measure until training completed
        }

        $totalImplementation = 0;
        foreach ($this->coveredControls as $control) {
            $totalImplementation += $control->getImplementationPercentage() ?? 0;
        }

        return round($totalImplementation / $this->coveredControls->count(), 2);
    }

    /**
     * Get list of control categories covered
     * Data Reuse: Shows training scope
     */
    #[Groups(['training:read'])]
    public function getCoveredCategories(): array
    {
        $categories = [];
        foreach ($this->coveredControls as $control) {
            $category = $control->getCategory();
            if (!in_array($category, $categories)) {
                $categories[] = $category;
            }
        }
        return $categories;
    }

    /**
     * Check if training addresses high-priority controls
     * Data Reuse: Links training to critical security areas
     */
    #[Groups(['training:read'])]
    public function hasCriticalControls(): bool
    {
        foreach ($this->coveredControls as $control) {
            if (!$control->isApplicable() || $control->getImplementationPercentage() < 50) {
                return true; // Training addresses controls that need attention
            }
        }
        return false;
    }

    /**
     * @return Collection<int, ComplianceRequirement>
     */
    public function getComplianceRequirements(): Collection
    {
        return $this->complianceRequirements;
    }

    public function addComplianceRequirement(ComplianceRequirement $requirement): static
    {
        if (!$this->complianceRequirements->contains($requirement)) {
            $this->complianceRequirements->add($requirement);
        }
        return $this;
    }

    public function removeComplianceRequirement(ComplianceRequirement $requirement): static
    {
        $this->complianceRequirements->removeElement($requirement);
        return $this;
    }

    /**
     * Get count of compliance requirements covered
     * Data Reuse: Shows training impact on regulatory compliance
     */
    #[Groups(['training:read'])]
    public function getComplianceRequirementCount(): int
    {
        return $this->complianceRequirements->count();
    }

    /**
     * Get list of compliance frameworks covered
     * Data Reuse: Shows training scope across regulations
     */
    #[Groups(['training:read'])]
    public function getCoveredFrameworks(): array
    {
        $frameworkNames = [];
        foreach ($this->complianceRequirements as $requirement) {
            $framework = $requirement->getFramework();
            if ($framework) {
                $name = $framework->getName();
                if ($name && !in_array($name, $frameworkNames)) {
                    $frameworkNames[] = $name;
                }
            }
        }
        return $frameworkNames;
    }

    /**
     * Check if training fulfills specific compliance framework
     * Data Reuse: Validates training coverage for certifications
     */
    #[Groups(['training:read'])]
    public function coversFramework(string $frameworkName): bool
    {
        foreach ($this->complianceRequirements as $requirement) {
            $framework = $requirement->getFramework();
            if ($framework && $framework->getName() === $frameworkName) {
                return true;
            }
        }
        return false;
    }
}
