<?php

namespace App\Entity;

use DateTimeImmutable;
use InvalidArgumentException;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compliance Requirement Fulfillment
 *
 * Tenant-specific fulfillment tracking for compliance requirements.
 * Separates standard framework definitions from organization-specific implementation.
 *
 * Architecture Pattern: Definition-Fulfillment Separation
 * - ComplianceFramework: Global standards (ISO 27001, GDPR, NIS2)
 * - ComplianceRequirement: Global requirement definitions
 * - ComplianceRequirementFulfillment: Tenant-specific implementation & progress
 *
 * Multi-Tenancy: Each tenant has independent fulfillment tracking for each requirement.
 * Unique Constraint: (tenant_id, requirement_id) ensures one fulfillment record per tenant per requirement.
 *
 * Use Cases:
 * - Track compliance progress per tenant
 * - Justify applicability decisions per organization
 * - Document evidence and implementation notes
 * - Generate tenant-specific compliance reports
 *
 * @see ComplianceFramework For framework definitions
 * @see ComplianceRequirement For requirement definitions
 */
#[ORM\Entity(repositoryClass: ComplianceRequirementFulfillmentRepository::class)]
#[ORM\Table(name: 'compliance_requirement_fulfillment')]
#[ORM\UniqueConstraint(name: 'unique_tenant_requirement', columns: ['tenant_id', 'requirement_id'])]
#[ORM\Index(name: 'idx_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_requirement', columns: ['requirement_id'])]
#[ORM\Index(name: 'idx_fulfillment_percentage', columns: ['fulfillment_percentage'])]
class ComplianceRequirementFulfillment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The tenant this fulfillment belongs to
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * The compliance requirement being fulfilled
     */
    #[ORM\ManyToOne(targetEntity: ComplianceRequirement::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ComplianceRequirement $complianceRequirement = null;

    /**
     * Is this requirement applicable to this tenant?
     * Organizations can declare requirements as not applicable with justification.
     */
    #[ORM\Column]
    private bool $applicable = true;

    /**
     * Justification for applicability decision
     * Required when applicable = false (ISO 27001 Statement of Applicability)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $applicabilityJustification = null;

    /**
     * Fulfillment progress percentage (0-100)
     * ISO 27001: Used for compliance gap analysis
     */
    #[ORM\Column]
    private int $fulfillmentPercentage = 0;

    /**
     * Implementation notes and progress documentation
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $fulfillmentNotes = null;

    /**
     * Evidence description for compliance audit trail
     * ISO 27001: Documents how requirement is implemented
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evidenceDescription = null;

    /**
     * Last review date for this requirement fulfillment
     * ISO 27001: Annual reviews required
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastReviewDate = null;

    /**
     * Next review date
     * ISO 27001: Scheduled compliance reviews
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $nextReviewDate = null;

    /**
     * Responsible person for implementing this requirement
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $responsiblePerson = null;

    /**
     * Implementation status
     */
    #[ORM\Column(length: 50)]
    private string $status = 'not_started'; // not_started, in_progress, implemented, verified

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    /**
     * User who last updated this fulfillment
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $lastUpdatedBy = null;

    public function __construct()
    {
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

    public function getRequirement(): ?ComplianceRequirement
    {
        return $this->complianceRequirement;
    }

    public function setRequirement(?ComplianceRequirement $complianceRequirement): static
    {
        $this->complianceRequirement = $complianceRequirement;
        return $this;
    }

    public function isApplicable(): bool
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
        // Clamp value between 0 and 100
        $this->fulfillmentPercentage = max(0, min(100, $fulfillmentPercentage));
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

    public function getLastReviewDate(): ?DateTimeImmutable
    {
        return $this->lastReviewDate;
    }

    public function setLastReviewDate(?DateTimeImmutable $lastReviewDate): static
    {
        $this->lastReviewDate = $lastReviewDate;
        return $this;
    }

    public function getNextReviewDate(): ?DateTimeImmutable
    {
        return $this->nextReviewDate;
    }

    public function setNextReviewDate(?DateTimeImmutable $nextReviewDate): static
    {
        $this->nextReviewDate = $nextReviewDate;
        return $this;
    }

    public function getResponsiblePerson(): ?User
    {
        return $this->responsiblePerson;
    }

    public function setResponsiblePerson(?User $user): static
    {
        $this->responsiblePerson = $user;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $allowedStatuses = ['not_started', 'in_progress', 'implemented', 'verified'];
        if (!in_array($status, $allowedStatuses)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid status "%s". Allowed: %s',
                $status,
                implode(', ', $allowedStatuses)
            ));
        }
        $this->status = $status;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
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

    public function getLastUpdatedBy(): ?User
    {
        return $this->lastUpdatedBy;
    }

    public function setLastUpdatedBy(?User $user): static
    {
        $this->lastUpdatedBy = $user;
        return $this;
    }

    /**
     * Update timestamp on persist/update
     */
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Calculate compliance score (0-100)
     * Takes into account applicability and fulfillment percentage
     *
     * @return int Compliance score
     */
    public function getComplianceScore(): int
    {
        if (!$this->applicable) {
            return 100; // Not applicable = 100% compliant
        }

        return $this->fulfillmentPercentage;
    }

    /**
     * Check if requirement is overdue for review
     *
     * @return bool True if next review date is in the past
     */
    public function isOverdueForReview(): bool
    {
        if (!$this->nextReviewDate instanceof DateTimeImmutable) {
            return false;
        }

        return $this->nextReviewDate < new DateTimeImmutable();
    }

    /**
     * Check if requirement is fully implemented
     *
     * @return bool True if fulfillment is 100% or not applicable
     */
    public function isFullyImplemented(): bool
    {
        return $this->getComplianceScore() === 100;
    }
}
