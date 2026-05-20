<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\EvidenceReverificationTaskStatus;
use App\Repository\EvidenceReverificationTaskRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F4 Evidence-Versioning — reviewer-queue task.
 *
 * Created automatically by EvidenceCascadeInvalidationService when a
 * new DocumentVersion is published and linked CI/CF rows are marked
 * evidenceOutdated=true. Assigned to a reviewer (typically the control
 * owner or DPO) with a due-date SLA.
 *
 * Status machine:
 *   pending → in_progress → completed | skipped
 */
#[ORM\Entity(repositoryClass: EvidenceReverificationTaskRepository::class)]
#[ORM\Table(name: 'evidence_reverification_task')]
#[ORM\Index(name: 'idx_revtask_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_revtask_status', columns: ['status'])]
#[ORM\Index(name: 'idx_revtask_due', columns: ['due_date'])]
class EvidenceReverificationTask
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_IN_PROGRESS = 'in_progress';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_SKIPPED = 'skipped';

    public const array VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * The document version that triggered this task.
     */
    #[ORM\ManyToOne(targetEntity: DocumentVersion::class)]
    #[ORM\JoinColumn(name: 'document_version_id', nullable: false, onDelete: 'CASCADE')]
    private ?DocumentVersion $documentVersion = null;

    /**
     * The ISO 27001 Control linked to the outdated evidence (nullable).
     */
    #[ORM\ManyToOne(targetEntity: Control::class)]
    #[ORM\JoinColumn(name: 'control_id', nullable: true, onDelete: 'SET NULL')]
    private ?Control $control = null;

    /**
     * The ComplianceRequirementFulfillment row linked to the outdated evidence (nullable).
     */
    #[ORM\ManyToOne(targetEntity: ComplianceRequirementFulfillment::class)]
    #[ORM\JoinColumn(name: 'compliance_fulfillment_id', nullable: true, onDelete: 'SET NULL')]
    private ?ComplianceRequirementFulfillment $complianceFulfillment = null;

    /**
     * The user responsible for completing the re-verification.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_to_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    /**
     * Deadline for completing the re-verification.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $dueDate = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_PENDING])]
    private string $status = self::STATUS_PENDING;

    /**
     * Timestamp when the task was marked completed or skipped.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    /**
     * Free-text reviewer notes captured at completion / skip.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    // -------------------------------------------------------------------------
    // Getters / setters
    // -------------------------------------------------------------------------

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

    public function getDocumentVersion(): ?DocumentVersion
    {
        return $this->documentVersion;
    }

    public function setDocumentVersion(?DocumentVersion $documentVersion): static
    {
        $this->documentVersion = $documentVersion;
        return $this;
    }

    public function getControl(): ?Control
    {
        return $this->control;
    }

    public function setControl(?Control $control): static
    {
        $this->control = $control;
        return $this;
    }

    public function getComplianceFulfillment(): ?ComplianceRequirementFulfillment
    {
        return $this->complianceFulfillment;
    }

    public function setComplianceFulfillment(?ComplianceRequirementFulfillment $complianceFulfillment): static
    {
        $this->complianceFulfillment = $complianceFulfillment;
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

    public function getDueDate(): ?DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(EvidenceReverificationTaskStatus|string $status): static
    {
        // Accept both enum and string so new code can pass the typed enum
        // while existing string-passing callers keep working unchanged.
        $value = is_string($status) ? $status : $status->value;
        if (!in_array($value, self::VALID_STATUSES, true)) {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException(sprintf(
                'Invalid EvidenceReverificationTask status "%s". Valid: %s',
                $value,
                implode(', ', self::VALID_STATUSES),
            ));
        }
        $this->status = $value;
        return $this;
    }

    /** Typed status surface for enum-aware code. */
    public function getStatusEnum(): EvidenceReverificationTaskStatus
    {
        return EvidenceReverificationTaskStatus::from($this->status);
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * True when the task is still open (pending or in_progress).
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS], true);
    }

    /**
     * True when the due date is in the past and the task is not completed.
     */
    public function isOverdue(): bool
    {
        if (!$this->isOpen() || $this->dueDate === null) {
            return false;
        }
        return $this->dueDate < new DateTimeImmutable('today');
    }
}
