<?php

namespace App\Entity;

use App\Repository\RiskTreatmentPlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * RiskTreatmentPlan Entity (ISO 27005:2022)
 *
 * Tracks implementation of risk treatment measures.
 * Links risks to controls with timeline, budget, and responsibility tracking.
 *
 * Phase 6F-B3: Risk treatment plan management for ISO 27001 compliance
 */
#[ORM\Entity(repositoryClass: RiskTreatmentPlanRepository::class)]
#[ORM\Index(columns: ['status'], name: 'idx_treatment_plan_status')]
#[ORM\Index(columns: ['priority'], name: 'idx_treatment_plan_priority')]
#[ORM\Index(columns: ['target_completion_date'], name: 'idx_treatment_plan_target')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_treatment_plan_tenant')]
class RiskTreatmentPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['treatment_plan:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['treatment_plan:read'])]
    private ?Tenant $tenant = null;

    /**
     * The risk being treated by this plan
     */
    #[ORM\ManyToOne(targetEntity: Risk::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    #[Assert\NotNull(message: 'Risk is required')]
    #[MaxDepth(1)]
    private ?Risk $risk = null;

    #[ORM\Column(length: 255)]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    #[Assert\NotBlank(message: 'Title is required')]
    #[Assert\Length(max: 255, maxMessage: 'Title cannot exceed {{ limit }} characters')]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    #[Assert\NotBlank(message: 'Description is required')]
    private ?string $description = null;

    /**
     * Implementation status
     * planned: Plan created, not started
     * in_progress: Currently implementing
     * completed: Successfully implemented
     * cancelled: Plan cancelled
     * on_hold: Temporarily paused
     */
    #[ORM\Column(length: 50)]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    #[Assert\NotBlank(message: 'Status is required')]
    #[Assert\Choice(
        choices: ['planned', 'in_progress', 'completed', 'cancelled', 'on_hold'],
        message: 'Status must be one of: {{ choices }}'
    )]
    private ?string $status = 'planned';

    /**
     * Priority level for implementation
     */
    #[ORM\Column(length: 20)]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    #[Assert\NotBlank(message: 'Priority is required')]
    #[Assert\Choice(
        choices: ['low', 'medium', 'high', 'critical'],
        message: 'Priority must be one of: {{ choices }}'
    )]
    private ?string $priority = 'medium';

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    private ?\DateTimeInterface $startDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    #[Assert\NotNull(message: 'Target completion date is required')]
    private ?\DateTimeInterface $targetCompletionDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    private ?\DateTimeInterface $actualCompletionDate = null;

    /**
     * Budget allocated for this treatment plan
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    #[Assert\PositiveOrZero(message: 'Budget must be positive or zero')]
    private ?string $budget = null;

    /**
     * Person responsible for implementing this plan
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    #[MaxDepth(1)]
    private ?User $responsiblePerson = null;

    /**
     * Controls implementing this treatment plan
     * Data Reuse: Link treatment plans to specific controls
     *
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'risk_treatment_plan_control')]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    #[MaxDepth(1)]
    private Collection $controls;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    private ?string $implementationNotes = null;

    /**
     * Completion percentage (0-100)
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['treatment_plan:read', 'treatment_plan:write'])]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'Completion percentage must be between {{ min }} and {{ max }}')]
    private int $completionPercentage = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['treatment_plan:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['treatment_plan:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->controls = new ArrayCollection();
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

    public function getRisk(): ?Risk
    {
        return $this->risk;
    }

    public function setRisk(?Risk $risk): static
    {
        $this->risk = $risk;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeInterface $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getTargetCompletionDate(): ?\DateTimeInterface
    {
        return $this->targetCompletionDate;
    }

    public function setTargetCompletionDate(?\DateTimeInterface $targetCompletionDate): static
    {
        $this->targetCompletionDate = $targetCompletionDate;
        return $this;
    }

    public function getActualCompletionDate(): ?\DateTimeInterface
    {
        return $this->actualCompletionDate;
    }

    public function setActualCompletionDate(?\DateTimeInterface $actualCompletionDate): static
    {
        $this->actualCompletionDate = $actualCompletionDate;
        return $this;
    }

    public function getBudget(): ?string
    {
        return $this->budget;
    }

    public function setBudget(?string $budget): static
    {
        $this->budget = $budget;
        return $this;
    }

    public function getResponsiblePerson(): ?User
    {
        return $this->responsiblePerson;
    }

    public function setResponsiblePerson(?User $responsiblePerson): static
    {
        $this->responsiblePerson = $responsiblePerson;
        return $this;
    }

    /**
     * @return Collection<int, Control>
     */
    public function getControls(): Collection
    {
        return $this->controls;
    }

    public function addControl(Control $control): static
    {
        if (!$this->controls->contains($control)) {
            $this->controls->add($control);
        }
        return $this;
    }

    public function removeControl(Control $control): static
    {
        $this->controls->removeElement($control);
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

    public function getCompletionPercentage(): int
    {
        return $this->completionPercentage;
    }

    public function setCompletionPercentage(int $completionPercentage): static
    {
        $this->completionPercentage = $completionPercentage;
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
     * Check if plan is overdue
     */
    #[Groups(['treatment_plan:read'])]
    public function isOverdue(): bool
    {
        if ($this->status === 'completed' || $this->status === 'cancelled') {
            return false;
        }

        $now = new \DateTime();
        return $this->targetCompletionDate < $now;
    }

    /**
     * Get days until target completion (negative = overdue)
     */
    #[Groups(['treatment_plan:read'])]
    public function getDaysUntilTarget(): int
    {
        $now = new \DateTime();
        $interval = $now->diff($this->targetCompletionDate);
        return (int)($interval->invert ? -$interval->days : $interval->days);
    }

    /**
     * Check if plan is on track based on completion vs time elapsed
     * Data Reuse: Progress monitoring
     */
    #[Groups(['treatment_plan:read'])]
    public function isOnTrack(): bool
    {
        if ($this->status === 'completed') {
            return true;
        }

        if ($this->startDate === null) {
            return true; // Not started yet
        }

        $now = new \DateTime();
        $totalDuration = $this->startDate->diff($this->targetCompletionDate)->days;
        $elapsedDuration = $this->startDate->diff($now)->days;

        if ($totalDuration === 0) {
            return true;
        }

        $expectedCompletion = ($elapsedDuration / $totalDuration) * 100;

        // Allow 15% tolerance
        return $this->completionPercentage >= ($expectedCompletion - 15);
    }

    /**
     * Get count of linked controls
     * Data Reuse: Control coverage metric
     */
    #[Groups(['treatment_plan:read'])]
    public function getControlCount(): int
    {
        return $this->controls->count();
    }

    /**
     * Get responsible person's name
     * Data Reuse: Quick display without loading full User entity
     */
    #[Groups(['treatment_plan:read'])]
    public function getResponsiblePersonName(): ?string
    {
        return $this->responsiblePerson?->getFullName();
    }

    /**
     * Check if treatment plan has started
     */
    #[Groups(['treatment_plan:read'])]
    public function hasStarted(): bool
    {
        return $this->startDate !== null && $this->startDate <= new \DateTime();
    }

    /**
     * Check if treatment plan is complete
     */
    #[Groups(['treatment_plan:read'])]
    public function isComplete(): bool
    {
        return $this->status === 'completed' && $this->actualCompletionDate !== null;
    }
}
