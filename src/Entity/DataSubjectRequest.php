<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use App\Repository\DataSubjectRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * GDPR Data Subject Request (Betroffenenantrag)
 *
 * Implements GDPR Articles 15-22 (Data Subject Rights):
 * - Art. 15: Right of access
 * - Art. 16: Right to rectification
 * - Art. 17: Right to erasure ("right to be forgotten")
 * - Art. 18: Right to restriction of processing
 * - Art. 20: Right to data portability
 * - Art. 21: Right to object
 * - Art. 22: Right not to be subject to automated decision-making
 *
 * Art. 12(3): Response deadline is 30 days, extendable to 90 days.
 */
#[ORM\Entity(repositoryClass: DataSubjectRequestRepository::class)]
#[ORM\Table(name: 'data_subject_request')]
#[ORM\Index(name: 'idx_dsr_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_dsr_status', columns: ['status'])]
#[ORM\Index(name: 'idx_dsr_request_type', columns: ['request_type'])]
#[ORM\Index(name: 'idx_dsr_deadline', columns: ['deadline_at'])]
#[ORM\HasLifecycleCallbacks]
class DataSubjectRequest
{
    public const array REQUEST_TYPES = [
        'access',
        'rectification',
        'erasure',
        'restriction',
        'portability',
        'objection',
        'automated_decision',
    ];

    public const array STATUSES = [
        'received',
        'identity_verification',
        'in_progress',
        'completed',
        'rejected',
        'extended',
    ];

    public const array VERIFICATION_METHODS = [
        'id_document',
        'email_verification',
        'account_login',
        'other',
    ];

    /**
     * Maps request type to GDPR article number
     */
    private const array GDPR_ARTICLE_MAP = [
        'access' => 'Art. 15',
        'rectification' => 'Art. 16',
        'erasure' => 'Art. 17',
        'restriction' => 'Art. 18',
        'portability' => 'Art. 20',
        'objection' => 'Art. 21',
        'automated_decision' => 'Art. 22',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Multi-Tenancy: Tenant that owns this request
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Tenant $tenant = null;

    /**
     * Type of data subject right being exercised (Art. 15-22)
     */
    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::REQUEST_TYPES)]
    private ?string $requestType = null;

    /**
     * Current processing status
     */
    #[ORM\Column(length: 20, options: ['default' => 'received'])]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = 'received';

    /**
     * Name of the data subject making the request
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $dataSubjectName = null;

    /**
     * Contact email of the data subject
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    #[Assert\Length(max: 255)]
    private ?string $dataSubjectEmail = null;

    /**
     * Identifier of the data subject (customer ID, employee ID, etc.)
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $dataSubjectIdentifier = null;

    /**
     * Description of what the data subject is requesting
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $description = null;

    /**
     * Date/time the request was received
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull]
    private ?DateTimeImmutable $receivedAt = null;

    /**
     * Legal deadline for response (Art. 12(3): receivedAt + 30 days)
     * Calculated automatically in PrePersist
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $deadlineAt = null;

    /**
     * Date/time the request was completed
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    // ============================================================================
    // Identity Verification
    // ============================================================================

    /**
     * Whether the data subject's identity has been verified
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $identityVerified = false;

    /**
     * Method used to verify identity
     */
    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Choice(choices: self::VERIFICATION_METHODS)]
    private ?string $identityVerificationMethod = null;

    /**
     * Date/time identity was verified
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $identityVerifiedAt = null;

    // ============================================================================
    // Response & Resolution
    // ============================================================================

    /**
     * Description of what was done to fulfill the request
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $responseDescription = null;

    /**
     * Reason for rejection (Art. 12(5): manifestly unfounded or excessive)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    /**
     * Reason for extending deadline (Art. 12(3): complexity, number of requests)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $extensionReason = null;

    /**
     * Extended deadline (Art. 12(3): receivedAt + 90 days max)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $extendedDeadlineAt = null;

    // ============================================================================
    // Assignments & Relations
    // ============================================================================

    /**
     * User assigned to handle this request
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $assignedTo = null;

    /**
     * Linked processing activity (VVT Art. 30)
     */
    #[ORM\ManyToOne(targetEntity: ProcessingActivity::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProcessingActivity $processingActivity = null;

    /**
     * Internal notes (not shared with data subject)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    // ============================================================================
    // Metadata
    // ============================================================================

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    // ============================================================================
    // Lifecycle Callbacks
    // ============================================================================

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();

        // Calculate deadline: receivedAt + 30 days (Art. 12(3) GDPR)
        if ($this->receivedAt instanceof DateTimeImmutable && $this->deadlineAt === null) {
            $this->deadlineAt = $this->receivedAt->modify('+30 days');
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    // ============================================================================
    // Computed / Helper Methods
    // ============================================================================

    /**
     * Check if the request is overdue based on effective deadline
     */
    public function isOverdue(): bool
    {
        if (in_array($this->status, ['completed', 'rejected'], true)) {
            return false;
        }

        $deadline = $this->getEffectiveDeadline();
        if (!$deadline instanceof DateTimeImmutable) {
            return false;
        }

        return new DateTimeImmutable() > $deadline;
    }

    /**
     * Get the effective deadline (extended if applicable)
     */
    public function getEffectiveDeadline(): ?DateTimeImmutable
    {
        return $this->extendedDeadlineAt ?? $this->deadlineAt;
    }

    /**
     * Get number of days until the effective deadline
     * Negative value means overdue
     */
    public function getDaysUntilDeadline(): int
    {
        $deadline = $this->getEffectiveDeadline();
        if (!$deadline instanceof DateTimeImmutable) {
            return 0;
        }

        $now = new DateTimeImmutable();
        $diff = $now->diff($deadline);

        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * Get the GDPR article reference for this request type
     */
    public function getGdprArticle(): string
    {
        return self::GDPR_ARTICLE_MAP[$this->requestType] ?? 'N/A';
    }

    /**
     * Get display name for breadcrumbs/titles
     */
    public function getDisplayName(): string
    {
        return sprintf('DSR-%d: %s', $this->id ?? 0, $this->dataSubjectName ?? 'Unknown');
    }

    // ============================================================================
    // Getters and Setters
    // ============================================================================

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

    public function getRequestType(): ?string
    {
        return $this->requestType;
    }

    public function setRequestType(string $requestType): static
    {
        $this->requestType = $requestType;
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

    public function getDataSubjectName(): ?string
    {
        return $this->dataSubjectName;
    }

    public function setDataSubjectName(string $dataSubjectName): static
    {
        $this->dataSubjectName = $dataSubjectName;
        return $this;
    }

    public function getDataSubjectEmail(): ?string
    {
        return $this->dataSubjectEmail;
    }

    public function setDataSubjectEmail(?string $dataSubjectEmail): static
    {
        $this->dataSubjectEmail = $dataSubjectEmail;
        return $this;
    }

    public function getDataSubjectIdentifier(): ?string
    {
        return $this->dataSubjectIdentifier;
    }

    public function setDataSubjectIdentifier(?string $dataSubjectIdentifier): static
    {
        $this->dataSubjectIdentifier = $dataSubjectIdentifier;
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

    public function getReceivedAt(): ?DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;
        return $this;
    }

    public function getDeadlineAt(): ?DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(DateTimeImmutable $deadlineAt): static
    {
        $this->deadlineAt = $deadlineAt;
        return $this;
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

    public function isIdentityVerified(): bool
    {
        return $this->identityVerified;
    }

    public function setIdentityVerified(bool $identityVerified): static
    {
        $this->identityVerified = $identityVerified;
        return $this;
    }

    public function getIdentityVerificationMethod(): ?string
    {
        return $this->identityVerificationMethod;
    }

    public function setIdentityVerificationMethod(?string $identityVerificationMethod): static
    {
        $this->identityVerificationMethod = $identityVerificationMethod;
        return $this;
    }

    public function getIdentityVerifiedAt(): ?DateTimeImmutable
    {
        return $this->identityVerifiedAt;
    }

    public function setIdentityVerifiedAt(?DateTimeImmutable $identityVerifiedAt): static
    {
        $this->identityVerifiedAt = $identityVerifiedAt;
        return $this;
    }

    public function getResponseDescription(): ?string
    {
        return $this->responseDescription;
    }

    public function setResponseDescription(?string $responseDescription): static
    {
        $this->responseDescription = $responseDescription;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }

    public function getExtensionReason(): ?string
    {
        return $this->extensionReason;
    }

    public function setExtensionReason(?string $extensionReason): static
    {
        $this->extensionReason = $extensionReason;
        return $this;
    }

    public function getExtendedDeadlineAt(): ?DateTimeImmutable
    {
        return $this->extendedDeadlineAt;
    }

    public function setExtendedDeadlineAt(?DateTimeImmutable $extendedDeadlineAt): static
    {
        $this->extendedDeadlineAt = $extendedDeadlineAt;
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

    public function getProcessingActivity(): ?ProcessingActivity
    {
        return $this->processingActivity;
    }

    public function setProcessingActivity(?ProcessingActivity $processingActivity): static
    {
        $this->processingActivity = $processingActivity;
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

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
