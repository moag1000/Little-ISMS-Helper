<?php

declare(strict_types=1);

namespace App\Entity;

use App\Attribute\EvidenceRecord;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use Symfony\Component\Serializer\Attribute\Groups;
use App\Enum\ManagementDecisionStatus;
use App\Enum\ManagementDecisionType;
use App\Repository\ManagementDecisionRepository;
use App\Validator\Constraint\SameTenantOwner;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One decision of top management, with its evidence: what was put forward,
 * who decided when, with which comment, until when it holds.
 *
 * Covers every decision the norms reserve for top management: ISMS policy and
 * scope (ISO 27001 5.1/5.2), risk acceptance criteria (6.1.2 a), board-level
 * risk acceptance (6.1.3 f), SoA (6.1.3 d), objectives (6.2), resources (7.1),
 * management review results (9.3.3) and NIS-2 Art. 20 (1) approval of the
 * cybersecurity measures. Decisions are taken through management_decision_lifecycle
 * (approve / reject by ROLE_TOP_MGMT); decisions taken elsewhere (risk
 * workflow, review completion) are recorded as already approved.
 *
 * The subject (subjectType + subjectId) is the record the decision is about,
 * resolved like ActionItemReference — no FK, any table. subjectSnapshot keeps
 * the values that were approved (e.g. acceptance thresholds), so a later change
 * of the subject is visible as "no longer covered by the approval".
 */
/*
 * REST: read-only. Decisions are proposed in the UI and taken through
 * management_decision_lifecycle (approve/reject by ROLE_TOP_MGMT) or recorded by
 * ManagementDecisionService::record(); the API never writes them. Readers as in
 * the UI: ROLE_AUDITOR and above.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            description: 'Top-management decisions of the organisation (ISO 27001 5.1, 6.1.3, 9.3; NIS-2 Art. 20)',
            security: "is_granted('ROLE_AUDITOR')",
            parameters: [
                'type' => new QueryParameter(filter: new ExactFilter()),
                'status' => new QueryParameter(filter: new ExactFilter()),
            ],
        ),
        new Get(security: "is_granted('ROLE_AUDITOR') and is_granted('API_VIEW', object)"),
    ],
    normalizationContext: ['groups' => ['management_decision:read']],
)]
#[EvidenceRecord('ManagementDecisionService / management_decision_lifecycle (ROLE_TOP_MGMT)')]
#[ORM\Entity(repositoryClass: ManagementDecisionRepository::class)]
#[ORM\Table(name: 'management_decision')]
#[ORM\Index(name: 'idx_md_tenant_status', columns: ['tenant_id', 'status'])]
#[ORM\Index(name: 'idx_md_tenant_type', columns: ['tenant_id', 'decision_type'])]
#[ORM\Index(name: 'idx_md_subject', columns: ['subject_type', 'subject_id'])]
class ManagementDecision
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['management_decision:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(name: 'decision_type', length: 40, enumType: ManagementDecisionType::class)]
    #[Groups(['management_decision:read'])]
    private ManagementDecisionType $type = ManagementDecisionType::Other;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['management_decision:read'])]
    private ?string $title = null;

    /** What was put before top management (prefilled from the records, editable before submission). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['management_decision:read'])]
    private ?string $proposal = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['management_decision:read'])]
    private ?string $subjectType = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['management_decision:read'])]
    private ?int $subjectId = null;

    /** @var array<string, mixed>|null values the decision covers */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $subjectSnapshot = null;

    #[ORM\ManyToOne(targetEntity: ManagementReview::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ManagementReview $managementReview = null;

    /** The approval this decision rests on (automatic risk acceptance → approved criteria). */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ManagementDecision $basis = null;

    #[ORM\Column(length: 20)]
    #[Groups(['management_decision:read'])]
    private string $status = 'pending';

    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[SameTenantOwner]
    private ?User $requestedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['management_decision:read'])]
    private DateTimeImmutable $requestedAt;

    /** By when top management should decide (pending proposals). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Groups(['management_decision:read'])]
    private ?DateTimeImmutable $dueDate = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[SameTenantOwner]
    private ?User $decidedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['management_decision:read'])]
    private ?DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['management_decision:read'])]
    private ?string $decisionComment = null;

    /** Until when the approval holds; top management confirms it again before. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Groups(['management_decision:read'])]
    private ?DateTimeImmutable $validUntil = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['management_decision:read'])]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->requestedAt = $this->createdAt;
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

    public function getType(): ManagementDecisionType
    {
        return $this->type;
    }

    public function setType(ManagementDecisionType $type): static
    {
        $this->type = $type;
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

    public function getProposal(): ?string
    {
        return $this->proposal;
    }

    public function setProposal(?string $proposal): static
    {
        $this->proposal = $proposal;
        return $this;
    }

    public function getSubjectType(): ?string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    public function setSubject(?string $subjectType, ?int $subjectId): static
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getSubjectSnapshot(): ?array
    {
        return $this->subjectSnapshot;
    }

    /** @param array<string, mixed>|null $subjectSnapshot */
    public function setSubjectSnapshot(?array $subjectSnapshot): static
    {
        $this->subjectSnapshot = $subjectSnapshot;
        return $this;
    }

    public function getManagementReview(): ?ManagementReview
    {
        return $this->managementReview;
    }

    public function setManagementReview(?ManagementReview $managementReview): static
    {
        $this->managementReview = $managementReview;
        return $this;
    }

    public function getBasis(): ?ManagementDecision
    {
        return $this->basis;
    }

    public function setBasis(?ManagementDecision $basis): static
    {
        $this->basis = $basis;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** Marking store of management_decision_lifecycle — call sites use LifecycleService. */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStatusEnum(): ManagementDecisionStatus
    {
        return ManagementDecisionStatus::tryFrom($this->status) ?? ManagementDecisionStatus::Pending;
    }

    public function isPending(): bool
    {
        return $this->status === ManagementDecisionStatus::Pending->value;
    }

    /** Approved, not superseded and not past its validity. */
    public function isEffective(?DateTimeImmutable $today = null): bool
    {
        if ($this->status !== ManagementDecisionStatus::Approved->value) {
            return false;
        }
        $today ??= new DateTimeImmutable('today');

        return $this->validUntil === null || $this->validUntil >= $today;
    }

    public function isExpired(?DateTimeImmutable $today = null): bool
    {
        $today ??= new DateTimeImmutable('today');

        return $this->status === ManagementDecisionStatus::Approved->value
            && $this->validUntil !== null && $this->validUntil < $today;
    }

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function getRequestedBy(): ?User
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(?User $requestedBy): static
    {
        $this->requestedBy = $requestedBy;
        return $this;
    }

    public function getRequestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(DateTimeImmutable $requestedAt): static
    {
        $this->requestedAt = $requestedAt;
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

    public function isOverdue(?DateTimeImmutable $today = null): bool
    {
        $today ??= new DateTimeImmutable('today');

        return $this->isPending() && $this->dueDate !== null && $this->dueDate < $today;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): static
    {
        $this->decidedBy = $decidedBy;
        return $this;
    }

    public function getDecidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;
        return $this;
    }

    public function getDecisionComment(): ?string
    {
        return $this->decisionComment;
    }

    public function setDecisionComment(?string $decisionComment): static
    {
        $this->decisionComment = $decisionComment;
        return $this;
    }

    public function getValidUntil(): ?DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?DateTimeImmutable $validUntil): static
    {
        $this->validUntil = $validUntil;
        return $this;
    }

    #[Groups(['management_decision:read'])]
    public function getRequestedByName(): ?string
    {
        return $this->requestedBy?->getFullName();
    }

    #[Groups(['management_decision:read'])]
    public function getDecidedByName(): ?string
    {
        return $this->decidedBy?->getFullName();
    }

    #[Groups(['management_decision:read'])]
    public function getNormReference(): string
    {
        return $this->type->normReference();
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function __toString(): string
    {
        return (string) $this->title;
    }
}
