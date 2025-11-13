<?php

namespace App\Entity;

use App\Entity\Tenant;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'workflow_instances')]
#[ORM\Index(columns: ['entity_type', 'entity_id'])]
#[ORM\Index(columns: ['status'])]
class WorkflowInstance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Workflow::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Workflow $workflow = null;

    #[ORM\Column(length: 100)]
    private ?string $entityType = null; // e.g., 'App\Entity\Risk'

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $entityId = null;

    #[ORM\Column(length: 50)]
    private string $status = 'pending'; // pending, in_progress, approved, rejected, cancelled

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $initiatedBy = null;

    #[ORM\ManyToOne(targetEntity: WorkflowStep::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?WorkflowStep $currentStep = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $completedSteps = []; // Array of step IDs

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $approvalHistory = []; // History of approvals/rejections

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comments = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

public function __construct()
    {
        $this->startedAt = new \DateTimeImmutable();
        $this->completedSteps = [];
        $this->approvalHistory = [];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflow(): ?Workflow
    {
        return $this->workflow;
    }

    public function setWorkflow(?Workflow $workflow): static
    {
        $this->workflow = $workflow;
        return $this;
    }

    public function getEntityType(): ?string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): static
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): static
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getInitiatedBy(): ?User
    {
        return $this->initiatedBy;
    }

    public function setInitiatedBy(?User $initiatedBy): static
    {
        $this->initiatedBy = $initiatedBy;
        return $this;
    }

    public function getCurrentStep(): ?WorkflowStep
    {
        return $this->currentStep;
    }

    public function setCurrentStep(?WorkflowStep $currentStep): static
    {
        $this->currentStep = $currentStep;
        return $this;
    }

    public function getCompletedSteps(): ?array
    {
        return $this->completedSteps;
    }

    public function setCompletedSteps(?array $completedSteps): static
    {
        $this->completedSteps = $completedSteps;
        return $this;
    }

    public function addCompletedStep(int $stepId): static
    {
        if (!in_array($stepId, $this->completedSteps)) {
            $this->completedSteps[] = $stepId;
        }
        return $this;
    }

    public function getApprovalHistory(): ?array
    {
        return $this->approvalHistory;
    }

    public function setApprovalHistory(?array $approvalHistory): static
    {
        $this->approvalHistory = $approvalHistory;
        return $this;
    }

    public function addApprovalHistoryEntry(array $entry): static
    {
        $this->approvalHistory[] = $entry;
        return $this;
    }

    public function getComments(): ?string
    {
        return $this->comments;
    }

    public function setComments(?string $comments): static
    {
        $this->comments = $comments;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    /**
     * Check if workflow is overdue
     */
    public function isOverdue(): bool
    {
        if ($this->dueDate === null) {
            return false;
        }

        return $this->dueDate < new \DateTimeImmutable() && $this->completedAt === null;
    }

    /**
     * Get workflow progress percentage
     */
    public function getProgressPercentage(): int
    {
        $totalSteps = count($this->workflow->getSteps());
        if ($totalSteps === 0) {
            return 0;
        }

        $completedCount = count($this->completedSteps);
        return (int) round(($completedCount / $totalSteps) * 100);
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
}
