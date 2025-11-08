<?php

namespace App\Entity;

use App\Repository\RiskTreatmentPlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Risk Treatment Plan Entity for ISO 27005 Compliance
 *
 * Detailed implementation plan for risk treatment actions.
 * ISO 27005:2022 requires documented risk treatment plans with:
 * - Clear objectives and scope
 * - Assigned responsibilities
 * - Timescales and priorities
 * - Resource requirements
 * - Monitoring and review mechanisms
 */
#[ORM\Entity(repositoryClass: RiskTreatmentPlanRepository::class)]
#[ORM\Table(name: 'risk_treatment_plans')]
#[ORM\Index(columns: ['status'], name: 'idx_rtp_status')]
#[ORM\Index(columns: ['priority'], name: 'idx_rtp_priority')]
#[ORM\Index(columns: ['planned_completion_date'], name: 'idx_rtp_completion')]
class RiskTreatmentPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    /**
     * Title of the treatment plan
     */
    #[ORM\Column(length: 255)]
    private ?string $title = null;

    /**
     * Detailed description of the treatment plan
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * The risk being treated
     */
    #[ORM\ManyToOne(targetEntity: Risk::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Risk $risk = null;

    /**
     * Treatment option (aligned with Risk.treatmentStrategy)
     * - mitigate: Implement controls to reduce risk
     * - transfer: Transfer risk to third party (insurance, outsourcing)
     * - avoid: Eliminate the risk by changing approach
     * - accept: Accept the risk with formal approval
     */
    #[ORM\Column(length: 30)]
    private ?string $treatmentOption = 'mitigate';

    /**
     * Current status of the treatment plan
     * - planned: Plan created, not yet started
     * - approved: Plan approved, ready to start
     * - in_progress: Currently being implemented
     * - completed: Implementation finished
     * - on_hold: Temporarily paused
     * - cancelled: Plan cancelled
     */
    #[ORM\Column(length: 30)]
    private ?string $status = 'planned';

    /**
     * Priority of this treatment plan
     */
    #[ORM\Column(length: 20)]
    private ?string $priority = 'medium';

    /**
     * Assigned to (responsible person)
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    /**
     * Approved by (management approval)
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    /**
     * Approval date
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    /**
     * Planned start date
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $plannedStartDate = null;

    /**
     * Planned completion date
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $plannedCompletionDate = null;

    /**
     * Actual start date
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $actualStartDate = null;

    /**
     * Actual completion date
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $actualCompletionDate = null;

    /**
     * Next review date
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextReviewDate = null;

    /**
     * Estimated cost (EUR)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $estimatedCost = null;

    /**
     * Actual cost (EUR)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $actualCost = null;

    /**
     * Implementation steps
     * Format: [
     *   {"step": 1, "description": "...", "status": "completed", "completedAt": "2024-01-15"},
     *   {"step": 2, "description": "...", "status": "in_progress"}
     * ]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $implementationSteps = [];

    /**
     * Required resources
     * Format: [
     *   {"type": "personnel", "description": "2 security engineers", "allocated": true},
     *   {"type": "budget", "description": "€50,000", "allocated": false}
     * ]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $resourcesRequired = [];

    /**
     * Dependencies and prerequisites
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dependencies = null;

    /**
     * Progress percentage (0-100)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $progressPercentage = 0;

    /**
     * Last progress update date
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastProgressUpdate = null;

    /**
     * Success criteria (how to measure completion)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $successCriteria = null;

    /**
     * Expected residual risk level after treatment
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $expectedResidualRiskLevel = null;

    /**
     * Implementation challenges and issues
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $challenges = null;

    /**
     * Notes and additional information
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Controls to be implemented as part of this plan
     *
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'risk_treatment_plan_controls')]
    private Collection $controls;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->controls = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->implementationSteps = [];
        $this->resourcesRequired = [];
        $this->progressPercentage = 0;
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

    public function getRisk(): ?Risk
    {
        return $this->risk;
    }

    public function setRisk(?Risk $risk): static
    {
        $this->risk = $risk;
        return $this;
    }

    public function getTreatmentOption(): ?string
    {
        return $this->treatmentOption;
    }

    public function setTreatmentOption(string $treatmentOption): static
    {
        $this->treatmentOption = $treatmentOption;
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

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $approvedBy): static
    {
        $this->approvedBy = $approvedBy;
        return $this;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getPlannedStartDate(): ?\DateTimeImmutable
    {
        return $this->plannedStartDate;
    }

    public function setPlannedStartDate(?\DateTimeImmutable $plannedStartDate): static
    {
        $this->plannedStartDate = $plannedStartDate;
        return $this;
    }

    public function getPlannedCompletionDate(): ?\DateTimeImmutable
    {
        return $this->plannedCompletionDate;
    }

    public function setPlannedCompletionDate(?\DateTimeImmutable $plannedCompletionDate): static
    {
        $this->plannedCompletionDate = $plannedCompletionDate;
        return $this;
    }

    public function getActualStartDate(): ?\DateTimeImmutable
    {
        return $this->actualStartDate;
    }

    public function setActualStartDate(?\DateTimeImmutable $actualStartDate): static
    {
        $this->actualStartDate = $actualStartDate;
        return $this;
    }

    public function getActualCompletionDate(): ?\DateTimeImmutable
    {
        return $this->actualCompletionDate;
    }

    public function setActualCompletionDate(?\DateTimeImmutable $actualCompletionDate): static
    {
        $this->actualCompletionDate = $actualCompletionDate;
        return $this;
    }

    public function getNextReviewDate(): ?\DateTimeImmutable
    {
        return $this->nextReviewDate;
    }

    public function setNextReviewDate(?\DateTimeImmutable $nextReviewDate): static
    {
        $this->nextReviewDate = $nextReviewDate;
        return $this;
    }

    public function getEstimatedCost(): ?string
    {
        return $this->estimatedCost;
    }

    public function setEstimatedCost(?string $estimatedCost): static
    {
        $this->estimatedCost = $estimatedCost;
        return $this;
    }

    public function getActualCost(): ?string
    {
        return $this->actualCost;
    }

    public function setActualCost(?string $actualCost): static
    {
        $this->actualCost = $actualCost;
        return $this;
    }

    public function getImplementationSteps(): ?array
    {
        return $this->implementationSteps;
    }

    public function setImplementationSteps(?array $implementationSteps): static
    {
        $this->implementationSteps = $implementationSteps;
        return $this;
    }

    public function addImplementationStep(array $step): static
    {
        $this->implementationSteps[] = $step;
        return $this;
    }

    public function getResourcesRequired(): ?array
    {
        return $this->resourcesRequired;
    }

    public function setResourcesRequired(?array $resourcesRequired): static
    {
        $this->resourcesRequired = $resourcesRequired;
        return $this;
    }

    public function addResource(array $resource): static
    {
        $this->resourcesRequired[] = $resource;
        return $this;
    }

    public function getDependencies(): ?string
    {
        return $this->dependencies;
    }

    public function setDependencies(?string $dependencies): static
    {
        $this->dependencies = $dependencies;
        return $this;
    }

    public function getProgressPercentage(): int
    {
        return $this->progressPercentage;
    }

    public function setProgressPercentage(int $progressPercentage): static
    {
        $this->progressPercentage = min(100, max(0, $progressPercentage));
        $this->lastProgressUpdate = new \DateTimeImmutable();
        return $this;
    }

    public function getLastProgressUpdate(): ?\DateTimeImmutable
    {
        return $this->lastProgressUpdate;
    }

    public function getSuccessCriteria(): ?string
    {
        return $this->successCriteria;
    }

    public function setSuccessCriteria(?string $successCriteria): static
    {
        $this->successCriteria = $successCriteria;
        return $this;
    }

    public function getExpectedResidualRiskLevel(): ?int
    {
        return $this->expectedResidualRiskLevel;
    }

    public function setExpectedResidualRiskLevel(?int $expectedResidualRiskLevel): static
    {
        $this->expectedResidualRiskLevel = $expectedResidualRiskLevel;
        return $this;
    }

    public function getChallenges(): ?string
    {
        return $this->challenges;
    }

    public function setChallenges(?string $challenges): static
    {
        $this->challenges = $challenges;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Check if the plan is approved
     */
    public function isApproved(): bool
    {
        return $this->approvedBy !== null && $this->approvedAt !== null;
    }

    /**
     * Check if the plan is overdue
     */
    public function isOverdue(): bool
    {
        if ($this->plannedCompletionDate === null) {
            return false;
        }

        if (in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }

        return $this->plannedCompletionDate < new \DateTimeImmutable();
    }

    /**
     * Check if the plan is on track
     */
    public function isOnTrack(): bool
    {
        if ($this->plannedCompletionDate === null || $this->plannedStartDate === null) {
            return true; // Cannot determine
        }

        if ($this->status === 'completed') {
            return !$this->isOverdue();
        }

        // Calculate expected progress based on timeline
        $now = new \DateTimeImmutable();
        $totalDays = $this->plannedStartDate->diff($this->plannedCompletionDate)->days;
        $elapsedDays = $this->plannedStartDate->diff($now)->days;

        if ($totalDays === 0) {
            return true;
        }

        $expectedProgress = min(100, ($elapsedDays / $totalDays) * 100);

        // Allow 10% tolerance
        return $this->progressPercentage >= ($expectedProgress - 10);
    }

    /**
     * Get days until planned completion
     */
    public function getDaysUntilCompletion(): ?int
    {
        if ($this->plannedCompletionDate === null) {
            return null;
        }

        $now = new \DateTimeImmutable();
        $diff = $now->diff($this->plannedCompletionDate);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Get completion percentage of implementation steps
     */
    public function getStepsCompletionPercentage(): int
    {
        if (empty($this->implementationSteps)) {
            return 0;
        }

        $completed = 0;
        foreach ($this->implementationSteps as $step) {
            if (isset($step['status']) && $step['status'] === 'completed') {
                $completed++;
            }
        }

        return (int) round(($completed / count($this->implementationSteps)) * 100);
    }

    /**
     * Auto-update progress based on implementation steps
     */
    public function updateProgressFromSteps(): static
    {
        $this->progressPercentage = $this->getStepsCompletionPercentage();
        $this->lastProgressUpdate = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Mark a specific step as completed
     */
    public function completeStep(int $stepIndex): static
    {
        if (isset($this->implementationSteps[$stepIndex])) {
            $this->implementationSteps[$stepIndex]['status'] = 'completed';
            $this->implementationSteps[$stepIndex]['completedAt'] = (new \DateTimeImmutable())->format('Y-m-d');
            $this->updateProgressFromSteps();
        }
        return $this;
    }

    /**
     * Get treatment option display name
     */
    public function getTreatmentOptionDisplayName(): string
    {
        return match($this->treatmentOption) {
            'mitigate' => 'Mindern (Maßnahmen implementieren)',
            'transfer' => 'Übertragen (Versicherung, Outsourcing)',
            'avoid' => 'Vermeiden (Aktivität einstellen)',
            'accept' => 'Akzeptieren (mit Genehmigung)',
            default => 'Unbekannt',
        };
    }

    /**
     * Get status display name
     */
    public function getStatusDisplayName(): string
    {
        return match($this->status) {
            'planned' => 'Geplant',
            'approved' => 'Genehmigt',
            'in_progress' => 'In Bearbeitung',
            'completed' => 'Abgeschlossen',
            'on_hold' => 'Pausiert',
            'cancelled' => 'Abgebrochen',
            default => 'Unbekannt',
        };
    }
}
