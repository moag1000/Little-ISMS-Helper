<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Repository\DataProtectionImpactAssessmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRITICAL-07: Data Protection Impact Assessment (DPIA/DSFA)
 *
 * Datenschutz-Folgenabschätzung gemäß Art. 35 DSGVO.
 * Required when processing is likely to result in high risk to rights and freedoms.
 *
 * Compliance Mapping:
 * - GDPR Art. 35: Data Protection Impact Assessment requirement
 * - GDPR Art. 35(1): High-risk processing identification
 * - GDPR Art. 35(3): DPIA required for certain types of processing
 * - GDPR Art. 35(4): DPO consultation requirement
 * - GDPR Art. 35(7): Minimum content requirements
 * - GDPR Art. 35(9): Review when circumstances change
 * - NIS2 Art. 21: Risk assessment integration
 */
#[ORM\Entity(repositoryClass: DataProtectionImpactAssessmentRepository::class)]
#[ORM\Table(name: 'data_protection_impact_assessment')]
#[ORM\Index(name: 'idx_dpia_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_dpia_status', columns: ['status'])]
#[ORM\Index(name: 'idx_dpia_risk_level', columns: ['risk_level'])]
class DataProtectionImpactAssessment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Multi-Tenancy: Tenant that owns this DPIA
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Tenant $tenant = null;

    /**
     * Related processing activity (Art. 30 VVT entry)
     * One DPIA can be linked to one ProcessingActivity
     */
    #[ORM\OneToOne(targetEntity: ProcessingActivity::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProcessingActivity $processingActivity = null;

    // ============================================================================
    // Basic Information
    // ============================================================================

    /**
     * Title of the DPIA
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    /**
     * Reference number (e.g., "DPIA-2024-001")
     */
    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private ?string $referenceNumber = null;

    /**
     * Version number (incremented on review/update)
     */
    #[ORM\Column(length: 20, options: ['default' => '1.0'])]
    private string $version = '1.0';

    // ============================================================================
    // Art. 35(7)(a): Description of Processing Operations
    // ============================================================================

    /**
     * Systematic description of the envisaged processing operations
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $processingDescription = null;

    /**
     * Purposes of the processing
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $processingPurposes = null;

    /**
     * Categories of personal data processed
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank]
    private array $dataCategories = [];

    /**
     * Categories of data subjects affected
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank]
    private array $dataSubjectCategories = [];

    /**
     * Estimated number of data subjects
     */
    #[ORM\Column(nullable: true)]
    private ?int $estimatedDataSubjects = null;

    /**
     * Data retention period
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dataRetentionPeriod = null;

    /**
     * Description of data flows (collection, storage, processing, sharing, deletion)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dataFlowDescription = null;

    // ============================================================================
    // Art. 35(7)(b): Assessment of Necessity and Proportionality
    // ============================================================================

    /**
     * Assessment of necessity: Why is this processing necessary?
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $necessityAssessment = null;

    /**
     * Assessment of proportionality: Is the processing proportionate to the purpose?
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $proportionalityAssessment = null;

    /**
     * Legal basis for processing (Art. 6 GDPR)
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private ?string $legalBasis = null;

    /**
     * Compliance with relevant legislation (e.g., GDPR, NIS2, sector-specific)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $legislativeCompliance = null;

    // ============================================================================
    // Art. 35(7)(c): Risk Assessment
    // ============================================================================

    /**
     * Identified risks to rights and freedoms of data subjects
     * JSON array of risk objects: [{title, description, likelihood, impact, severity}]
     */
    #[ORM\Column(type: Types::JSON)]
    private array $identifiedRisks = [];

    /**
     * Overall risk level: low, medium, high, critical
     */
    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: ['low', 'medium', 'high', 'critical'])]
    private ?string $riskLevel = null;

    /**
     * Likelihood assessment: rare, unlikely, possible, likely, certain
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: ['rare', 'unlikely', 'possible', 'likely', 'certain'])]
    private ?string $likelihood = null;

    /**
     * Impact assessment: negligible, minor, moderate, major, severe
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: ['negligible', 'minor', 'moderate', 'major', 'severe'])]
    private ?string $impact = null;

    /**
     * Specific risks for data subjects (e.g., discrimination, identity theft, financial loss)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dataSubjectRisks = null;

    // ============================================================================
    // Art. 35(7)(d): Measures to Address Risks
    // ============================================================================

    /**
     * Technical measures to mitigate risks (Art. 32 GDPR)
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $technicalMeasures = null;

    /**
     * Organizational measures to mitigate risks (Art. 32 GDPR)
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $organizationalMeasures = null;

    /**
     * Safeguards, security measures, and mechanisms (ISO 27001 controls)
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'dpia_control')]
    private Collection $implementedControls;

    /**
     * Measures to demonstrate compliance (Art. 24 GDPR - accountability)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $complianceMeasures = null;

    /**
     * Residual risk assessment after measures
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $residualRiskAssessment = null;

    /**
     * Residual risk level: low, medium, high, critical
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: ['low', 'medium', 'high', 'critical'])]
    private ?string $residualRiskLevel = null;

    // ============================================================================
    // Stakeholder Consultation (Art. 35(4), 35(9))
    // ============================================================================

    /**
     * Data Protection Officer consulted (Art. 35(2))
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $dataProtectionOfficer = null;

    /**
     * Date DPO was consulted
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $dpoConsultationDate = null;

    /**
     * DPO advice/feedback
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dpoAdvice = null;

    /**
     * Data subjects consulted (Art. 35(9) - where appropriate)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $dataSubjectsConsulted = false;

    /**
     * Details of data subject consultation
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $dataSubjectConsultationDetails = null;

    /**
     * Other stakeholders consulted (e.g., IT, Legal, Management)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $stakeholdersConsulted = null;

    // ============================================================================
    // Supervisory Authority Consultation (Art. 36)
    // ============================================================================

    /**
     * Whether prior consultation with supervisory authority is required (Art. 36)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $requiresSupervisoryConsultation = false;

    /**
     * Date supervisory authority was consulted
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $supervisoryConsultationDate = null;

    /**
     * Supervisory authority feedback/decision
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $supervisoryAuthorityFeedback = null;

    // ============================================================================
    // Workflow & Approval
    // ============================================================================

    /**
     * Status: draft, in_review, approved, rejected, requires_revision
     */
    #[ORM\Column(length: 30, options: ['default' => 'draft'])]
    #[Assert\Choice(choices: ['draft', 'in_review', 'approved', 'rejected', 'requires_revision'])]
    private string $status = 'draft';

    /**
     * Person responsible for conducting the DPIA
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $conductor = null;

    /**
     * Approver (e.g., Data Protection Officer, Management)
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $approver = null;

    /**
     * Date of approval
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $approvalDate = null;

    /**
     * Approval comments
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $approvalComments = null;

    /**
     * Rejection reason (if status = rejected)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    // ============================================================================
    // Review & Updates (Art. 35(11))
    // ============================================================================

    /**
     * Review required when circumstances change (Art. 35(11))
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $reviewRequired = false;

    /**
     * Date of last review
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $lastReviewDate = null;

    /**
     * Next scheduled review date
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $nextReviewDate = null;

    /**
     * Review frequency in months (e.g., 12 = annually)
     */
    #[ORM\Column(nullable: true)]
    private ?int $reviewFrequencyMonths = 12;

    /**
     * Reason for review (if circumstances changed)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewReason = null;

    // ============================================================================
    // Metadata
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
        $this->implementedControls = new ArrayCollection();
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
    // Helper Methods
    // ============================================================================

    /**
     * Check if DPIA is complete (all mandatory fields filled)
     */
    public function isComplete(): bool
    {
        return !in_array($this->title, [null, '', '0'], true)
            && !in_array($this->processingDescription, [null, '', '0'], true)
            && !in_array($this->processingPurposes, [null, '', '0'], true)
            && $this->dataCategories !== []
            && $this->dataSubjectCategories !== []
            && !in_array($this->necessityAssessment, [null, '', '0'], true)
            && !in_array($this->proportionalityAssessment, [null, '', '0'], true)
            && !in_array($this->legalBasis, [null, '', '0'], true)
            && !in_array($this->riskLevel, [null, '', '0'], true)
            && !in_array($this->technicalMeasures, [null, '', '0'], true)
            && !in_array($this->organizationalMeasures, [null, '', '0'], true);
    }

    /**
     * Calculate completeness percentage
     */
    public function getCompletenessPercentage(): int
    {
        $fields = [
            'title' => !in_array($this->title, [null, '', '0'], true),
            'referenceNumber' => !in_array($this->referenceNumber, [null, '', '0'], true),
            'processingDescription' => !in_array($this->processingDescription, [null, '', '0'], true),
            'processingPurposes' => !in_array($this->processingPurposes, [null, '', '0'], true),
            'dataCategories' => $this->dataCategories !== [],
            'dataSubjectCategories' => $this->dataSubjectCategories !== [],
            'necessityAssessment' => !in_array($this->necessityAssessment, [null, '', '0'], true),
            'proportionalityAssessment' => !in_array($this->proportionalityAssessment, [null, '', '0'], true),
            'legalBasis' => !in_array($this->legalBasis, [null, '', '0'], true),
            'riskLevel' => !in_array($this->riskLevel, [null, '', '0'], true),
            'technicalMeasures' => !in_array($this->technicalMeasures, [null, '', '0'], true),
            'organizationalMeasures' => !in_array($this->organizationalMeasures, [null, '', '0'], true),
        ];

        $filledCount = count(array_filter($fields));
        return (int) (($filledCount / count($fields)) * 100);
    }

    /**
     * Get display name for breadcrumbs/titles
     */
    public function getDisplayName(): string
    {
        return $this->referenceNumber . ' - ' . ($this->title ?? 'Untitled DPIA');
    }

    /**
     * Check if residual risk is acceptable
     */
    public function isResidualRiskAcceptable(): bool
    {
        return in_array($this->residualRiskLevel, ['low', 'medium']);
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getReferenceNumber(): ?string
    {
        return $this->referenceNumber;
    }

    public function setReferenceNumber(string $referenceNumber): static
    {
        $this->referenceNumber = $referenceNumber;
        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

    public function getProcessingDescription(): ?string
    {
        return $this->processingDescription;
    }

    public function setProcessingDescription(string $processingDescription): static
    {
        $this->processingDescription = $processingDescription;
        return $this;
    }

    public function getProcessingPurposes(): ?string
    {
        return $this->processingPurposes;
    }

    public function setProcessingPurposes(string $processingPurposes): static
    {
        $this->processingPurposes = $processingPurposes;
        return $this;
    }

    public function getDataCategories(): array
    {
        return $this->dataCategories;
    }

    public function setDataCategories(array $dataCategories): static
    {
        $this->dataCategories = $dataCategories;
        return $this;
    }

    public function getDataSubjectCategories(): array
    {
        return $this->dataSubjectCategories;
    }

    public function setDataSubjectCategories(array $dataSubjectCategories): static
    {
        $this->dataSubjectCategories = $dataSubjectCategories;
        return $this;
    }

    public function getEstimatedDataSubjects(): ?int
    {
        return $this->estimatedDataSubjects;
    }

    public function setEstimatedDataSubjects(?int $estimatedDataSubjects): static
    {
        $this->estimatedDataSubjects = $estimatedDataSubjects;
        return $this;
    }

    public function getDataRetentionPeriod(): ?string
    {
        return $this->dataRetentionPeriod;
    }

    public function setDataRetentionPeriod(?string $dataRetentionPeriod): static
    {
        $this->dataRetentionPeriod = $dataRetentionPeriod;
        return $this;
    }

    public function getDataFlowDescription(): ?string
    {
        return $this->dataFlowDescription;
    }

    public function setDataFlowDescription(?string $dataFlowDescription): static
    {
        $this->dataFlowDescription = $dataFlowDescription;
        return $this;
    }

    public function getNecessityAssessment(): ?string
    {
        return $this->necessityAssessment;
    }

    public function setNecessityAssessment(string $necessityAssessment): static
    {
        $this->necessityAssessment = $necessityAssessment;
        return $this;
    }

    public function getProportionalityAssessment(): ?string
    {
        return $this->proportionalityAssessment;
    }

    public function setProportionalityAssessment(string $proportionalityAssessment): static
    {
        $this->proportionalityAssessment = $proportionalityAssessment;
        return $this;
    }

    public function getLegalBasis(): ?string
    {
        return $this->legalBasis;
    }

    public function setLegalBasis(string $legalBasis): static
    {
        $this->legalBasis = $legalBasis;
        return $this;
    }

    public function getLegislativeCompliance(): ?string
    {
        return $this->legislativeCompliance;
    }

    public function setLegislativeCompliance(?string $legislativeCompliance): static
    {
        $this->legislativeCompliance = $legislativeCompliance;
        return $this;
    }

    public function getIdentifiedRisks(): array
    {
        return $this->identifiedRisks;
    }

    public function setIdentifiedRisks(array $identifiedRisks): static
    {
        $this->identifiedRisks = $identifiedRisks;
        return $this;
    }

    public function getRiskLevel(): ?string
    {
        return $this->riskLevel;
    }

    public function setRiskLevel(string $riskLevel): static
    {
        $this->riskLevel = $riskLevel;
        return $this;
    }

    public function getLikelihood(): ?string
    {
        return $this->likelihood;
    }

    public function setLikelihood(?string $likelihood): static
    {
        $this->likelihood = $likelihood;
        return $this;
    }

    public function getImpact(): ?string
    {
        return $this->impact;
    }

    public function setImpact(?string $impact): static
    {
        $this->impact = $impact;
        return $this;
    }

    public function getDataSubjectRisks(): ?string
    {
        return $this->dataSubjectRisks;
    }

    public function setDataSubjectRisks(?string $dataSubjectRisks): static
    {
        $this->dataSubjectRisks = $dataSubjectRisks;
        return $this;
    }

    public function getTechnicalMeasures(): ?string
    {
        return $this->technicalMeasures;
    }

    public function setTechnicalMeasures(string $technicalMeasures): static
    {
        $this->technicalMeasures = $technicalMeasures;
        return $this;
    }

    public function getOrganizationalMeasures(): ?string
    {
        return $this->organizationalMeasures;
    }

    public function setOrganizationalMeasures(string $organizationalMeasures): static
    {
        $this->organizationalMeasures = $organizationalMeasures;
        return $this;
    }

    /**
     * @return Collection<int, Control>
     */
    public function getImplementedControls(): Collection
    {
        return $this->implementedControls;
    }

    public function addImplementedControl(Control $control): static
    {
        if (!$this->implementedControls->contains($control)) {
            $this->implementedControls->add($control);
        }
        return $this;
    }

    public function removeImplementedControl(Control $control): static
    {
        $this->implementedControls->removeElement($control);
        return $this;
    }

    public function getComplianceMeasures(): ?string
    {
        return $this->complianceMeasures;
    }

    public function setComplianceMeasures(?string $complianceMeasures): static
    {
        $this->complianceMeasures = $complianceMeasures;
        return $this;
    }

    public function getResidualRiskAssessment(): ?string
    {
        return $this->residualRiskAssessment;
    }

    public function setResidualRiskAssessment(?string $residualRiskAssessment): static
    {
        $this->residualRiskAssessment = $residualRiskAssessment;
        return $this;
    }

    public function getResidualRiskLevel(): ?string
    {
        return $this->residualRiskLevel;
    }

    public function setResidualRiskLevel(?string $residualRiskLevel): static
    {
        $this->residualRiskLevel = $residualRiskLevel;
        return $this;
    }

    public function getDataProtectionOfficer(): ?User
    {
        return $this->dataProtectionOfficer;
    }

    public function setDataProtectionOfficer(?User $user): static
    {
        $this->dataProtectionOfficer = $user;
        return $this;
    }

    public function getDpoConsultationDate(): ?DateTimeInterface
    {
        return $this->dpoConsultationDate;
    }

    public function setDpoConsultationDate(?DateTimeInterface $dpoConsultationDate): static
    {
        $this->dpoConsultationDate = $dpoConsultationDate;
        return $this;
    }

    public function getDpoAdvice(): ?string
    {
        return $this->dpoAdvice;
    }

    public function setDpoAdvice(?string $dpoAdvice): static
    {
        $this->dpoAdvice = $dpoAdvice;
        return $this;
    }

    public function getDataSubjectsConsulted(): bool
    {
        return $this->dataSubjectsConsulted;
    }

    public function setDataSubjectsConsulted(bool $dataSubjectsConsulted): static
    {
        $this->dataSubjectsConsulted = $dataSubjectsConsulted;
        return $this;
    }

    public function getDataSubjectConsultationDetails(): ?string
    {
        return $this->dataSubjectConsultationDetails;
    }

    public function setDataSubjectConsultationDetails(?string $dataSubjectConsultationDetails): static
    {
        $this->dataSubjectConsultationDetails = $dataSubjectConsultationDetails;
        return $this;
    }

    public function getStakeholdersConsulted(): ?array
    {
        return $this->stakeholdersConsulted;
    }

    public function setStakeholdersConsulted(?array $stakeholdersConsulted): static
    {
        $this->stakeholdersConsulted = $stakeholdersConsulted;
        return $this;
    }

    public function getRequiresSupervisoryConsultation(): bool
    {
        return $this->requiresSupervisoryConsultation;
    }

    public function setRequiresSupervisoryConsultation(bool $requiresSupervisoryConsultation): static
    {
        $this->requiresSupervisoryConsultation = $requiresSupervisoryConsultation;
        return $this;
    }

    public function getSupervisoryConsultationDate(): ?DateTimeInterface
    {
        return $this->supervisoryConsultationDate;
    }

    public function setSupervisoryConsultationDate(?DateTimeInterface $supervisoryConsultationDate): static
    {
        $this->supervisoryConsultationDate = $supervisoryConsultationDate;
        return $this;
    }

    public function getSupervisoryAuthorityFeedback(): ?string
    {
        return $this->supervisoryAuthorityFeedback;
    }

    public function setSupervisoryAuthorityFeedback(?string $supervisoryAuthorityFeedback): static
    {
        $this->supervisoryAuthorityFeedback = $supervisoryAuthorityFeedback;
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

    public function getConductor(): ?User
    {
        return $this->conductor;
    }

    public function setConductor(?User $user): static
    {
        $this->conductor = $user;
        return $this;
    }

    public function getApprover(): ?User
    {
        return $this->approver;
    }

    public function setApprover(?User $user): static
    {
        $this->approver = $user;
        return $this;
    }

    public function getApprovalDate(): ?DateTimeInterface
    {
        return $this->approvalDate;
    }

    public function setApprovalDate(?DateTimeInterface $approvalDate): static
    {
        $this->approvalDate = $approvalDate;
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

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
    }

    public function getReviewRequired(): bool
    {
        return $this->reviewRequired;
    }

    public function setReviewRequired(bool $reviewRequired): static
    {
        $this->reviewRequired = $reviewRequired;
        return $this;
    }

    public function getLastReviewDate(): ?DateTimeInterface
    {
        return $this->lastReviewDate;
    }

    public function setLastReviewDate(?DateTimeInterface $lastReviewDate): static
    {
        $this->lastReviewDate = $lastReviewDate;
        return $this;
    }

    public function getNextReviewDate(): ?DateTimeInterface
    {
        return $this->nextReviewDate;
    }

    public function setNextReviewDate(?DateTimeInterface $nextReviewDate): static
    {
        $this->nextReviewDate = $nextReviewDate;
        return $this;
    }

    public function getReviewFrequencyMonths(): ?int
    {
        return $this->reviewFrequencyMonths;
    }

    public function setReviewFrequencyMonths(?int $reviewFrequencyMonths): static
    {
        $this->reviewFrequencyMonths = $reviewFrequencyMonths;
        return $this;
    }

    public function getReviewReason(): ?string
    {
        return $this->reviewReason;
    }

    public function setReviewReason(?string $reviewReason): static
    {
        $this->reviewReason = $reviewReason;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): static
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
