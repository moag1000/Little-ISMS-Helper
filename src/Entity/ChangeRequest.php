<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;

use App\Repository\ChangeRequestRepository;
use App\Entity\Tenant;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Change Request Entity for ISMS Change Management
 *
 * Tracks changes to the ISMS and related systems
 */
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ]
)]
#[ORM\Entity(repositoryClass: ChangeRequestRepository::class)]
#[ORM\Table(name: 'change_request')]
#[ORM\Index(columns: ['change_type'], name: 'idx_change_type')]
#[ORM\Index(columns: ['priority'], name: 'idx_change_priority')]
#[ORM\Index(columns: ['status'], name: 'idx_change_status')]
#[ORM\Index(columns: ['planned_implementation_date'], name: 'idx_change_planned_date')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_change_request_tenant')]
#[ORM\HasLifecycleCallbacks]
class ChangeRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['change_request:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['change_request:read'])]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $changeNumber = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Change title is required')]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $title = null;

    /**
     * Type of change:
     * - isms_policy, isms_scope, control, asset, process, technology, supplier, other
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        'isms_policy', 'isms_scope', 'control', 'asset', 'process',
        'technology', 'supplier', 'organizational', 'other'
    ])]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $changeType = 'other';

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Description is required')]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Justification is required')]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $justification = null;

    /**
     * Requester
     */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $requestedBy = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotNull]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?\DateTimeInterface $requestedDate = null;

    /**
     * Priority: critical, high, medium, low
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['critical', 'high', 'medium', 'low'])]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $priority = 'medium';

    /**
     * Status: draft, submitted, under_review, approved, rejected, scheduled, implemented, verified, closed, cancelled
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: [
        'draft', 'submitted', 'under_review', 'approved', 'rejected',
        'scheduled', 'implemented', 'verified', 'closed', 'cancelled'
    ])]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $status = 'draft';

    /**
     * Impact on ISMS
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $ismsImpact = null;

    /**
     * Affected assets
     */
    #[ORM\ManyToMany(targetEntity: Asset::class)]
    #[ORM\JoinTable(name: 'change_request_asset')]
    #[Groups(['change_request:read'])]
    private Collection $affectedAssets;

    /**
     * Affected controls
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'change_request_control')]
    #[Groups(['change_request:read'])]
    private Collection $affectedControls;

    /**
     * Affected business processes
     */
    #[ORM\ManyToMany(targetEntity: BusinessProcess::class)]
    #[ORM\JoinTable(name: 'change_request_business_process')]
    #[Groups(['change_request:read'])]
    private Collection $affectedProcesses;

    /**
     * Associated risks (new or updated)
     */
    #[ORM\ManyToMany(targetEntity: Risk::class)]
    #[ORM\JoinTable(name: 'change_request_risk')]
    #[Groups(['change_request:read'])]
    private Collection $associatedRisks;

    /**
     * Risk assessment for the change
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $riskAssessment = null;

    /**
     * Implementation plan
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $implementationPlan = null;

    /**
     * Rollback plan
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $rollbackPlan = null;

    /**
     * Testing requirements
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $testingRequirements = null;

    /**
     * Planned implementation date
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?\DateTimeInterface $plannedImplementationDate = null;

    /**
     * Actual implementation date
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?\DateTimeInterface $actualImplementationDate = null;

    /**
     * Approver
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $approvedBy = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?\DateTimeInterface $approvedDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $approvalComments = null;

    /**
     * Implementer
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $implementedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $implementationNotes = null;

    /**
     * Verification
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $verifiedBy = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?\DateTimeInterface $verifiedDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $verificationResults = null;

    /**
     * Closure
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?\DateTimeInterface $closedDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['change_request:read', 'change_request:write'])]
    private ?string $closureNotes = null;

    /**
     * Documents
     */
    #[ORM\ManyToMany(targetEntity: Document::class)]
    #[ORM\JoinTable(name: 'change_request_document')]
    #[Groups(['change_request:read'])]
    private Collection $documents;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['change_request:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['change_request:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->affectedAssets = new ArrayCollection();
        $this->affectedControls = new ArrayCollection();
        $this->affectedProcesses = new ArrayCollection();
        $this->associatedRisks = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->requestedDate = new \DateTime();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
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

    public function getChangeNumber(): ?string
    {
        return $this->changeNumber;
    }

    public function setChangeNumber(string $changeNumber): static
    {
        $this->changeNumber = $changeNumber;
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

    public function getChangeType(): ?string
    {
        return $this->changeType;
    }

    public function setChangeType(string $changeType): static
    {
        $this->changeType = $changeType;
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

    public function getJustification(): ?string
    {
        return $this->justification;
    }

    public function setJustification(string $justification): static
    {
        $this->justification = $justification;
        return $this;
    }

    public function getRequestedBy(): ?string
    {
        return $this->requestedBy;
    }

    public function setRequestedBy(string $requestedBy): static
    {
        $this->requestedBy = $requestedBy;
        return $this;
    }

    public function getRequestedDate(): ?\DateTimeInterface
    {
        return $this->requestedDate;
    }

    public function setRequestedDate(\DateTimeInterface $requestedDate): static
    {
        $this->requestedDate = $requestedDate;
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

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getIsmsImpact(): ?string
    {
        return $this->ismsImpact;
    }

    public function setIsmsImpact(?string $ismsImpact): static
    {
        $this->ismsImpact = $ismsImpact;
        return $this;
    }

    /**
     * @return Collection<int, Asset>
     */
    public function getAffectedAssets(): Collection
    {
        return $this->affectedAssets;
    }

    public function addAffectedAsset(Asset $asset): static
    {
        if (!$this->affectedAssets->contains($asset)) {
            $this->affectedAssets->add($asset);
        }
        return $this;
    }

    public function removeAffectedAsset(Asset $asset): static
    {
        $this->affectedAssets->removeElement($asset);
        return $this;
    }

    /**
     * @return Collection<int, Control>
     */
    public function getAffectedControls(): Collection
    {
        return $this->affectedControls;
    }

    public function addAffectedControl(Control $control): static
    {
        if (!$this->affectedControls->contains($control)) {
            $this->affectedControls->add($control);
        }
        return $this;
    }

    public function removeAffectedControl(Control $control): static
    {
        $this->affectedControls->removeElement($control);
        return $this;
    }

    /**
     * @return Collection<int, BusinessProcess>
     */
    public function getAffectedProcesses(): Collection
    {
        return $this->affectedProcesses;
    }

    public function addAffectedProcess(BusinessProcess $process): static
    {
        if (!$this->affectedProcesses->contains($process)) {
            $this->affectedProcesses->add($process);
        }
        return $this;
    }

    public function removeAffectedProcess(BusinessProcess $process): static
    {
        $this->affectedProcesses->removeElement($process);
        return $this;
    }

    /**
     * @return Collection<int, Risk>
     */
    public function getAssociatedRisks(): Collection
    {
        return $this->associatedRisks;
    }

    public function addAssociatedRisk(Risk $risk): static
    {
        if (!$this->associatedRisks->contains($risk)) {
            $this->associatedRisks->add($risk);
        }
        return $this;
    }

    public function removeAssociatedRisk(Risk $risk): static
    {
        $this->associatedRisks->removeElement($risk);
        return $this;
    }

    public function getRiskAssessment(): ?string
    {
        return $this->riskAssessment;
    }

    public function setRiskAssessment(?string $riskAssessment): static
    {
        $this->riskAssessment = $riskAssessment;
        return $this;
    }

    public function getImplementationPlan(): ?string
    {
        return $this->implementationPlan;
    }

    public function setImplementationPlan(?string $implementationPlan): static
    {
        $this->implementationPlan = $implementationPlan;
        return $this;
    }

    public function getRollbackPlan(): ?string
    {
        return $this->rollbackPlan;
    }

    public function setRollbackPlan(?string $rollbackPlan): static
    {
        $this->rollbackPlan = $rollbackPlan;
        return $this;
    }

    public function getTestingRequirements(): ?string
    {
        return $this->testingRequirements;
    }

    public function setTestingRequirements(?string $testingRequirements): static
    {
        $this->testingRequirements = $testingRequirements;
        return $this;
    }

    public function getPlannedImplementationDate(): ?\DateTimeInterface
    {
        return $this->plannedImplementationDate;
    }

    public function setPlannedImplementationDate(?\DateTimeInterface $plannedImplementationDate): static
    {
        $this->plannedImplementationDate = $plannedImplementationDate;
        return $this;
    }

    public function getActualImplementationDate(): ?\DateTimeInterface
    {
        return $this->actualImplementationDate;
    }

    public function setActualImplementationDate(?\DateTimeInterface $actualImplementationDate): static
    {
        $this->actualImplementationDate = $actualImplementationDate;
        return $this;
    }

    public function getApprovedBy(): ?string
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?string $approvedBy): static
    {
        $this->approvedBy = $approvedBy;
        return $this;
    }

    public function getApprovedDate(): ?\DateTimeInterface
    {
        return $this->approvedDate;
    }

    public function setApprovedDate(?\DateTimeInterface $approvedDate): static
    {
        $this->approvedDate = $approvedDate;
        return $this;
    }

    public function getApprovalComments(): ?string
    {
        return $this->approvalComments;
    }

    public function setApprovalComments(?string $approvalComments): static
    {
        $this->approvalComments = $approvalComments;
        return $this;
    }

    public function getImplementedBy(): ?string
    {
        return $this->implementedBy;
    }

    public function setImplementedBy(?string $implementedBy): static
    {
        $this->implementedBy = $implementedBy;
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

    public function getVerifiedBy(): ?string
    {
        return $this->verifiedBy;
    }

    public function setVerifiedBy(?string $verifiedBy): static
    {
        $this->verifiedBy = $verifiedBy;
        return $this;
    }

    public function getVerifiedDate(): ?\DateTimeInterface
    {
        return $this->verifiedDate;
    }

    public function setVerifiedDate(?\DateTimeInterface $verifiedDate): static
    {
        $this->verifiedDate = $verifiedDate;
        return $this;
    }

    public function getVerificationResults(): ?string
    {
        return $this->verificationResults;
    }

    public function setVerificationResults(?string $verificationResults): static
    {
        $this->verificationResults = $verificationResults;
        return $this;
    }

    public function getClosedDate(): ?\DateTimeInterface
    {
        return $this->closedDate;
    }

    public function setClosedDate(?\DateTimeInterface $closedDate): static
    {
        $this->closedDate = $closedDate;
        return $this;
    }

    public function getClosureNotes(): ?string
    {
        return $this->closureNotes;
    }

    public function setClosureNotes(?string $closureNotes): static
    {
        $this->closureNotes = $closureNotes;
        return $this;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
        }
        return $this;
    }

    public function removeDocument(Document $document): static
    {
        $this->documents->removeElement($document);
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
     * Check if change is approved
     */
    public function isApproved(): bool
    {
        return in_array($this->status, ['approved', 'scheduled', 'implemented', 'verified', 'closed']);
    }

    /**
     * Check if change is pending approval
     */
    public function isPendingApproval(): bool
    {
        return in_array($this->status, ['submitted', 'under_review']);
    }

    /**
     * Calculate change complexity score (0-100)
     * Data Reuse: Aggregates impact across multiple dimensions
     */
    public function getComplexityScore(): int
    {
        $score = 0;

        // Affected assets (30%)
        $assetCount = $this->affectedAssets->count();
        $score += min(30, $assetCount * 3);

        // Affected controls (25%)
        $controlCount = $this->affectedControls->count();
        $score += min(25, $controlCount * 5);

        // Affected processes (20%)
        $processCount = $this->affectedProcesses->count();
        $score += min(20, $processCount * 4);

        // Associated risks (15%)
        $riskCount = $this->associatedRisks->count();
        $score += min(15, $riskCount * 3);

        // Priority weight (10%)
        $score += match($this->priority) {
            'critical' => 10,
            'high' => 7,
            'medium' => 4,
            'low' => 2,
            default => 0
        };

        return min(100, $score);
    }

    /**
     * Get workflow progress percentage
     */
    public function getWorkflowProgress(): int
    {
        $stages = [
            'draft' => 0,
            'submitted' => 14,
            'under_review' => 28,
            'approved' => 42,
            'scheduled' => 57,
            'implemented' => 71,
            'verified' => 85,
            'closed' => 100,
            'rejected' => 0,
            'cancelled' => 0
        ];

        return $stages[$this->status] ?? 0;
    }

    /**
     * Get status badge color
     */
    public function getStatusBadge(): string
    {
        return match($this->status) {
            'draft' => 'secondary',
            'submitted', 'under_review' => 'info',
            'approved', 'scheduled' => 'primary',
            'implemented', 'verified' => 'success',
            'closed' => 'dark',
            'rejected', 'cancelled' => 'danger',
            default => 'secondary'
        };
    }
}
