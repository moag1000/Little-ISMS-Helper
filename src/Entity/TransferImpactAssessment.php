<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use App\Repository\TransferImpactAssessmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Transfer Impact Assessment (TIA) — GDPR Art. 46 / 49 + Schrems-II
 *
 * A TIA evaluates whether a third-country recipient (e.g. a US cloud provider)
 * can offer an essentially equivalent level of data protection despite divergent
 * national surveillance law.  It is required before any transfer mechanism under
 * Art. 46 (SCC / BCR / adequacy) is relied upon when the destination country
 * does not enjoy an adequacy decision under Art. 45.
 *
 * Schrems-II (CJEU C-311/18, 16 July 2020) established that the controller must
 * actively assess the destination country's legal framework and apply
 * supplementary measures where necessary.
 *
 * Compliance mapping:
 * - GDPR Art. 44: General principle for transfers
 * - GDPR Art. 45: Adequacy decision
 * - GDPR Art. 46: Transfers subject to appropriate safeguards (SCC / BCR)
 * - GDPR Art. 49: Derogations for specific situations
 * - Schrems-II (C-311/18): Assessment obligation for standard contractual clauses
 * - EDPB Recommendations 01/2020: TIA methodology guidance
 */
#[ORM\Entity(repositoryClass: TransferImpactAssessmentRepository::class)]
#[ORM\Table(name: 'transfer_impact_assessment')]
#[ORM\Index(name: 'idx_tia_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_tia_status', columns: ['status'])]
#[ORM\Index(name: 'idx_tia_residual_risk', columns: ['residual_risk_rating'])]
#[ORM\HasLifecycleCallbacks]
class TransferImpactAssessment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Multi-Tenancy: Tenant that owns this TIA
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Tenant $tenant = null;

    /**
     * The processing activity that involves the third-country transfer.
     * ManyToOne (one PA may have multiple TIAs, e.g. per country).
     * On PA deletion the TIA is preserved with a NULL link (accountability).
     */
    #[ORM\ManyToOne(targetEntity: ProcessingActivity::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProcessingActivity $processingActivity = null;

    // ============================================================================
    // Art. 30(1)(e) — Transfer destination
    // ============================================================================

    /**
     * Destination country of the transfer (ISO 3166-1 alpha-2, e.g. "US").
     * Mandatory — a TIA without a destination country is meaningless.
     */
    #[ORM\Column(length: 10)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 10)]
    private ?string $destinationCountry = null;

    /**
     * Name of the third-country recipient (e.g. "AWS Inc.", "Google LLC").
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $recipientName = null;

    // ============================================================================
    // Art. 46 / 49 — Transfer mechanism
    // ============================================================================

    /**
     * Legal basis / safeguard for the transfer (Art. 46 / 49 GDPR).
     *
     * Permitted values:
     *   - scc             Standard Contractual Clauses (Art. 46(2)(c)/(d))
     *   - bcr             Binding Corporate Rules (Art. 47)
     *   - adequacy        Adequacy decision in force (Art. 45)
     *   - certification   Approved certification (Art. 42/46(2)(f))
     *   - codes_of_conduct Approved codes of conduct (Art. 40/46(2)(e))
     *   - derogation      Derogation for specific situations (Art. 49)
     */
    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['scc', 'bcr', 'adequacy', 'certification', 'codes_of_conduct', 'derogation'])]
    private ?string $transferMechanism = null;

    // ============================================================================
    // Schrems-II assessment core — EDPB Rec. 01/2020 Steps 3-5
    // ============================================================================

    /**
     * Step 3 (EDPB): Assessment of the destination country's surveillance law.
     *
     * Example areas:
     *  - US: FISA 702 / EO 12333 / CLOUD Act (conflict with GDPR confidentiality)
     *  - CN: National Intelligence Law Art. 7 (mandatory cooperation)
     *  - IN: PDPB / CERT-In directions (data localisation + broad access)
     *
     * Describe whether and to what extent national law impairs the safeguard.
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $lawSurveillanceRisk = null;

    /**
     * Step 4 (EDPB): Supplementary technical and contractual measures adopted
     * to restore equivalent protection where national law creates a gap.
     *
     * Examples:
     *  - End-to-end encryption with controller-held keys (technical)
     *  - Pseudonymisation / data-minimisation before transfer (technical)
     *  - Contractual prohibition of access beyond what GDPR allows (contractual)
     *  - Transparency obligations on the importer (organisational)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $supplementaryMeasures = null;

    /**
     * Step 5 (EDPB): Residual risk rating after applying supplementary measures.
     * - low    Risk effectively mitigated; transfer may proceed.
     * - medium Residual risk; additional oversight recommended.
     * - high   Risk not acceptably mitigated; transfer MUST be suspended.
     */
    #[ORM\Column(length: 10)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['low', 'medium', 'high'])]
    private ?string $residualRiskRating = null;

    // ============================================================================
    // Conclusion
    // ============================================================================

    /**
     * Overall conclusion / recommendation free-text.
     * Documents the final outcome and any specific conditions for the transfer.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conclusion = null;

    // ============================================================================
    // Status & approval
    // ============================================================================

    /**
     * Status lifecycle: draft → assessed.
     * Mirrors the two-state pattern appropriate for a focused assessment artefact
     * (unlike DPIA which has a multi-party approval chain).
     */
    #[ORM\Column(length: 20, options: ['default' => 'draft'])]
    #[Assert\Choice(choices: ['draft', 'assessed'])]
    private string $status = 'draft';

    /**
     * Date when the assessment was finalised (status = assessed).
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $assessedAt = null;

    /**
     * User who performed / finalised the assessment.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $assessedBy = null;

    // ============================================================================
    // Optimistic locking (Lifecycle X.1)
    // ============================================================================

    /**
     * Optimistic locking version — prevents concurrent assessment conflicts.
     */
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    // ============================================================================
    // Audit metadata
    // ============================================================================

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    // ============================================================================
    // Lifecycle Callbacks
    // ============================================================================

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    // ============================================================================
    // Helper methods
    // ============================================================================

    /**
     * Returns true when the residual risk is acceptable (low or medium).
     * High residual risk requires suspension or termination of the transfer.
     */
    public function isResidualRiskAcceptable(): bool
    {
        return in_array($this->residualRiskRating, ['low', 'medium'], true);
    }

    /**
     * Display name for breadcrumbs / page headers.
     */
    public function getDisplayName(): string
    {
        return sprintf(
            'TIA – %s (%s)',
            $this->recipientName ?? 'Unknown recipient',
            $this->destinationCountry ?? '??'
        );
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

    public function getProcessingActivity(): ?ProcessingActivity
    {
        return $this->processingActivity;
    }

    public function setProcessingActivity(?ProcessingActivity $processingActivity): static
    {
        $this->processingActivity = $processingActivity;
        return $this;
    }

    public function getDestinationCountry(): ?string
    {
        return $this->destinationCountry;
    }

    public function setDestinationCountry(?string $destinationCountry): static
    {
        $this->destinationCountry = $destinationCountry;
        return $this;
    }

    public function getRecipientName(): ?string
    {
        return $this->recipientName;
    }

    public function setRecipientName(?string $recipientName): static
    {
        $this->recipientName = $recipientName;
        return $this;
    }

    public function getTransferMechanism(): ?string
    {
        return $this->transferMechanism;
    }

    public function setTransferMechanism(?string $transferMechanism): static
    {
        $this->transferMechanism = $transferMechanism;
        return $this;
    }

    public function getLawSurveillanceRisk(): ?string
    {
        return $this->lawSurveillanceRisk;
    }

    public function setLawSurveillanceRisk(?string $lawSurveillanceRisk): static
    {
        $this->lawSurveillanceRisk = $lawSurveillanceRisk;
        return $this;
    }

    public function getSupplementaryMeasures(): ?string
    {
        return $this->supplementaryMeasures;
    }

    public function setSupplementaryMeasures(?string $supplementaryMeasures): static
    {
        $this->supplementaryMeasures = $supplementaryMeasures;
        return $this;
    }

    public function getResidualRiskRating(): ?string
    {
        return $this->residualRiskRating;
    }

    public function setResidualRiskRating(?string $residualRiskRating): static
    {
        $this->residualRiskRating = $residualRiskRating;
        return $this;
    }

    public function getConclusion(): ?string
    {
        return $this->conclusion;
    }

    public function setConclusion(?string $conclusion): static
    {
        $this->conclusion = $conclusion;
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

    public function getAssessedAt(): ?DateTimeInterface
    {
        return $this->assessedAt;
    }

    public function setAssessedAt(?DateTimeInterface $assessedAt): static
    {
        $this->assessedAt = $assessedAt;
        return $this;
    }

    public function getAssessedBy(): ?User
    {
        return $this->assessedBy;
    }

    public function setAssessedBy(?User $assessedBy): static
    {
        $this->assessedBy = $assessedBy;
        return $this;
    }

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $user): static
    {
        $this->createdBy = $user;
        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $user): static
    {
        $this->updatedBy = $user;
        return $this;
    }
}
