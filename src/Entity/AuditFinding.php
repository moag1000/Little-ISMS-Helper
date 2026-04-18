<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditFindingRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * H-01: Structured Audit Finding (ISO 27001 Clause 10.1).
 * Captures a single nonconformity, observation or opportunity for improvement
 * detected during an audit — replaces free-text `InternalAudit.findings`.
 */
#[ORM\Entity(repositoryClass: AuditFindingRepository::class)]
#[ORM\Table(name: 'audit_findings')]
#[ORM\Index(name: 'idx_af_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_af_audit', columns: ['audit_id'])]
#[ORM\Index(name: 'idx_af_status', columns: ['status'])]
#[ORM\Index(name: 'idx_af_severity', columns: ['severity'])]
class AuditFinding
{
    public const TYPE_MAJOR_NC = 'major_nc';
    public const TYPE_MINOR_NC = 'minor_nc';
    public const TYPE_OBSERVATION = 'observation';
    public const TYPE_OPPORTUNITY = 'opportunity';

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_CLOSED = 'closed';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_LOW = 'low';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: InternalAudit::class, inversedBy: 'structuredFindings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?InternalAudit $audit = null;

    #[ORM\Column(length: 50)]
    private ?string $findingNumber = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(length: 50)]
    private string $type = self::TYPE_MINOR_NC;

    #[ORM\Column(length: 20)]
    private string $severity = self::SEVERITY_MEDIUM;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_OPEN;

    /** Clause/control reference (e.g. "ISO 27001 A.5.1", "Clause 9.3"). */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $clauseReference = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evidence = null;

    #[ORM\ManyToOne(targetEntity: Control::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Control $relatedControl = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $reportedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $assignedTo = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $dueDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $closedAt = null;

    /** @var Collection<int, CorrectiveAction> */
    #[ORM\OneToMany(targetEntity: CorrectiveAction::class, mappedBy: 'finding', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $correctiveActions;

    public function __construct()
    {
        $this->correctiveActions = new ArrayCollection();
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

    public function getAudit(): ?InternalAudit
    {
        return $this->audit;
    }

    public function setAudit(?InternalAudit $audit): static
    {
        $this->audit = $audit;
        return $this;
    }

    public function getFindingNumber(): ?string
    {
        return $this->findingNumber;
    }

    public function setFindingNumber(?string $findingNumber): static
    {
        $this->findingNumber = $findingNumber;
        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): static
    {
        $this->severity = $severity;
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

    public function getClauseReference(): ?string
    {
        return $this->clauseReference;
    }

    public function setClauseReference(?string $clauseReference): static
    {
        $this->clauseReference = $clauseReference;
        return $this;
    }

    public function getEvidence(): ?string
    {
        return $this->evidence;
    }

    public function setEvidence(?string $evidence): static
    {
        $this->evidence = $evidence;
        return $this;
    }

    public function getRelatedControl(): ?Control
    {
        return $this->relatedControl;
    }

    public function setRelatedControl(?Control $relatedControl): static
    {
        $this->relatedControl = $relatedControl;
        return $this;
    }

    public function getReportedBy(): ?User
    {
        return $this->reportedBy;
    }

    public function setReportedBy(?User $reportedBy): static
    {
        $this->reportedBy = $reportedBy;
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

    public function getDueDate(): ?DateTimeInterface
    {
        return $this->dueDate;
    }

    public function setDueDate(?DateTimeInterface $dueDate): static
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?DateTimeInterface
    {
        return $this->closedAt;
    }

    public function setClosedAt(?DateTimeInterface $closedAt): static
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    /** @return Collection<int, CorrectiveAction> */
    public function getCorrectiveActions(): Collection
    {
        return $this->correctiveActions;
    }

    public function addCorrectiveAction(CorrectiveAction $action): static
    {
        if (!$this->correctiveActions->contains($action)) {
            $this->correctiveActions->add($action);
            $action->setFinding($this);
        }
        return $this;
    }

    public function removeCorrectiveAction(CorrectiveAction $action): static
    {
        if ($this->correctiveActions->removeElement($action)) {
            if ($action->getFinding() === $this) {
                $action->setFinding(null);
            }
        }
        return $this;
    }

    public function isOverdue(): bool
    {
        if ($this->dueDate === null || in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_VERIFIED, self::STATUS_CLOSED], true)) {
            return false;
        }
        return $this->dueDate < new DateTimeImmutable();
    }
}
