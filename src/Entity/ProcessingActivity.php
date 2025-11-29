<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Repository\ProcessingActivityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRITICAL-06: DSGVO Art. 30 - Verzeichnis von Verarbeitungstätigkeiten
 *
 * Processing Activity according to GDPR Article 30.
 * Records all processing activities carried out by the controller or processor.
 *
 * Compliance Mapping:
 * - GDPR Art. 30(1): Controller record requirements
 * - GDPR Art. 30(2): Processor record requirements
 * - GDPR Art. 5(1)(a): Lawfulness, fairness, transparency
 * - GDPR Art. 32: Security of processing (TOM reference)
 * - NIS2 Art. 21: Risk management (data processing risks)
 */
#[ORM\Entity(repositoryClass: ProcessingActivityRepository::class)]
#[ORM\Table(name: 'processing_activity')]
#[ORM\Index(name: 'idx_processing_activity_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_processing_activity_legal_basis', columns: ['legal_basis'])]
#[ORM\Index(name: 'idx_processing_activity_high_risk', columns: ['is_high_risk'])]
class ProcessingActivity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Multi-Tenancy: Tenant that owns this processing activity
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Tenant $tenant = null;

    // ============================================================================
    // GDPR Art. 30(1)(a): Name and purposes of processing
    // ============================================================================

    /**
     * Name/Title of the processing activity (e.g., "Customer Management", "Payroll Processing")
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    /**
     * Detailed description of the processing activity
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Purpose(s) of processing (e.g., "Contract fulfillment", "Marketing", "Legal obligation")
     * JSON array for multiple purposes
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank]
    private array $purposes = [];

    // ============================================================================
    // GDPR Art. 30(1)(b): Categories of data subjects
    // ============================================================================

    /**
     * Categories of data subjects (e.g., "Customers", "Employees", "Suppliers")
     * JSON array: ["customers", "employees", "applicants", "suppliers", "other"]
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank]
    private array $dataSubjectCategories = [];

    /**
     * Number of data subjects affected (optional, for impact assessment)
     */
    #[ORM\Column(nullable: true)]
    private ?int $estimatedDataSubjectsCount = null;

    // ============================================================================
    // GDPR Art. 30(1)(c): Categories of personal data
    // ============================================================================

    /**
     * Categories of personal data processed
     * JSON array: ["identification", "contact", "financial", "location", "health", "biometric", "special_categories"]
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank]
    private array $personalDataCategories = [];

    /**
     * Whether special categories of data (Art. 9 GDPR) are processed
     * - Racial/ethnic origin
     * - Political opinions
     * - Religious/philosophical beliefs
     * - Trade union membership
     * - Genetic data
     * - Biometric data
     * - Health data
     * - Sex life/sexual orientation
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $processesSpecialCategories = false;

    /**
     * Special categories processed (if applicable)
     * JSON array: ["health", "biometric", "genetic", "racial_ethnic", "political", "religious", "union", "sex_life"]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $specialCategoriesDetails = null;

    /**
     * Whether data relating to criminal convictions (Art. 10 GDPR) is processed
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $processesCriminalData = false;

    // ============================================================================
    // GDPR Art. 30(1)(d): Categories of recipients
    // ============================================================================

    /**
     * Categories of recipients (e.g., "Internal departments", "Service providers", "Authorities")
     * JSON array
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $recipientCategories = null;

    /**
     * Specific recipients (optional, for transparency)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recipientDetails = null;

    // ============================================================================
    // GDPR Art. 30(1)(e): Transfers to third countries
    // ============================================================================

    /**
     * Whether data is transferred to third countries (outside EU/EEA)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $hasThirdCountryTransfer = false;

    /**
     * Third countries to which data is transferred
     * JSON array of country codes (ISO 3166-1 alpha-2)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $thirdCountries = null;

    /**
     * Legal basis for third country transfer (Art. 45-49 GDPR)
     * Possible values:
     * - adequacy_decision: Art. 45 adequacy decision
     * - standard_contractual_clauses: Art. 46 SCCs
     * - binding_corporate_rules: Art. 47 BCRs
     * - certification: Art. 42 certification
     * - codes_of_conduct: Art. 40 codes of conduct
     * - explicit_consent: Art. 49(1)(a)
     * - contract_necessity: Art. 49(1)(b)
     * - public_interest: Art. 49(1)(d)
     * - legal_claims: Art. 49(1)(e)
     * - vital_interests: Art. 49(1)(f)
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $transferSafeguards = null;

    // ============================================================================
    // GDPR Art. 30(1)(f): Retention periods
    // ============================================================================

    /**
     * General retention period description (e.g., "3 years after contract end")
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\NotBlank]
    private ?string $retentionPeriod = null;

    /**
     * Retention period in days (optional, for automated deletion)
     */
    #[ORM\Column(nullable: true)]
    private ?int $retentionPeriodDays = null;

    /**
     * Legal basis for retention period (e.g., "HGB §257", "DSGVO Art. 6(1)(c)")
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $retentionLegalBasis = null;

    // ============================================================================
    // GDPR Art. 30(1)(g): Technical and organizational measures (TOMs)
    // ============================================================================

    /**
     * General description of technical and organizational measures (Art. 32 GDPR)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $technicalOrganizationalMeasures = null;

    /**
     * Reference to implemented controls (ISO 27001 controls)
     * ManyToMany relationship to Control entity
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'processing_activity_control')]
    private Collection $implementedControls;

    /**
     * Consents for this processing activity (Art. 6(1)(a) GDPR)
     * Data Reuse: Track all consents when legalBasis = 'consent'
     */
    #[ORM\OneToMany(targetEntity: Consent::class, mappedBy: 'processingActivity', cascade: ['persist'])]
    private Collection $consents;

    // ============================================================================
    // Legal Basis (GDPR Art. 6)
    // ============================================================================

    /**
     * Legal basis for processing (Art. 6(1) GDPR)
     * Possible values:
     * - consent: Art. 6(1)(a) - Consent
     * - contract: Art. 6(1)(b) - Contract performance
     * - legal_obligation: Art. 6(1)(c) - Legal obligation
     * - vital_interests: Art. 6(1)(d) - Vital interests
     * - public_task: Art. 6(1)(e) - Public task
     * - legitimate_interests: Art. 6(1)(f) - Legitimate interests
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['consent', 'contract', 'legal_obligation', 'vital_interests', 'public_task', 'legitimate_interests'])]
    private ?string $legalBasis = null;

    /**
     * Detailed explanation of legal basis (mandatory for legitimate interests)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $legalBasisDetails = null;

    /**
     * Legal basis for special categories (Art. 9(2) GDPR) - if applicable
     * Possible values:
     * - explicit_consent: Art. 9(2)(a)
     * - employment_law: Art. 9(2)(b)
     * - vital_interests: Art. 9(2)(c)
     * - legitimate_activities: Art. 9(2)(d)
     * - made_public: Art. 9(2)(e)
     * - legal_claims: Art. 9(2)(f)
     * - substantial_public_interest: Art. 9(2)(g)
     * - health_care: Art. 9(2)(h)
     * - public_health: Art. 9(2)(i)
     * - research_statistics: Art. 9(2)(j)
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $legalBasisSpecialCategories = null;

    // ============================================================================
    // Organizational Structure
    // ============================================================================

    /**
     * Department/Unit responsible for this processing activity
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $responsibleDepartment = null;

    /**
     * Contact person for this processing activity
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $contactPerson = null;

    /**
     * Data Protection Officer notified about this activity
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $dataProtectionOfficer = null;

    // ============================================================================
    // Processor Relationships (Art. 28 GDPR)
    // ============================================================================

    /**
     * Whether processors (Auftragsverarbeiter) are involved
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $involvesProcessors = false;

    /**
     * List of processors involved (name, contact, description)
     * JSON array of objects: [{"name": "...", "contact": "...", "description": "...", "contract_date": "..."}]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $processors = null;

    // ============================================================================
    // Joint Controllers (Art. 26 GDPR)
    // ============================================================================

    /**
     * Whether this is a joint controller arrangement (Art. 26 GDPR)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isJointController = false;

    /**
     * Joint controller details (JSON array)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $jointControllerDetails = null;

    // ============================================================================
    // Risk Assessment & DPIA (Art. 35 GDPR)
    // ============================================================================

    /**
     * Whether this processing is considered high-risk (requires DPIA per Art. 35)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isHighRisk = false;

    /**
     * Whether a Data Protection Impact Assessment (DPIA/DSFA) has been conducted
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $dpiaCompleted = false;

    /**
     * Date of DPIA completion (if applicable)
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $dpiaDate = null;

    /**
     * Risk level assessment: low, medium, high, critical
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: ['low', 'medium', 'high', 'critical'])]
    private ?string $riskLevel = null;

    // ============================================================================
    // Data Sources & Automated Decision-Making
    // ============================================================================

    /**
     * Data sources (where data is collected from)
     * JSON array: ["data_subject", "third_parties", "public_sources", "other"]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dataSources = null;

    /**
     * Whether automated decision-making (Art. 22 GDPR) is involved
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $hasAutomatedDecisionMaking = false;

    /**
     * Details of automated decision-making/profiling (if applicable)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $automatedDecisionMakingDetails = null;

    // ============================================================================
    // Status & Metadata
    // ============================================================================

    /**
     * Status of this record: draft, active, archived
     */
    #[ORM\Column(length: 20, options: ['default' => 'draft'])]
    #[Assert\Choice(choices: ['draft', 'active', 'archived'])]
    private string $status = 'draft';

    /**
     * Date when this processing activity started
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $startDate = null;

    /**
     * Date when this processing activity ended (if applicable)
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $endDate = null;

    /**
     * Last review date (VVT should be reviewed regularly)
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $lastReviewDate = null;

    /**
     * Next planned review date
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $nextReviewDate = null;

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
        $this->consents = new ArrayCollection();
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
     * Check if DPIA is required per Art. 35(3) GDPR
     */
    public function requiresDPIA(): bool
    {
        // DPIA required if:
        // 1. Systematic and extensive evaluation (e.g., profiling)
        // 2. Large-scale processing of special categories
        // 3. Systematic monitoring of publicly accessible areas

        if ($this->hasAutomatedDecisionMaking) {
            return true;
        }

        if ($this->processesSpecialCategories && $this->estimatedDataSubjectsCount > 1000) {
            return true;
        }
        return $this->isHighRisk;
    }

    /**
     * Get completeness percentage (for compliance dashboard)
     */
    public function getCompletenessPercentage(): int
    {
        $requiredFields = [
            'name' => $this->name !== null,
            'purposes' => $this->purposes !== [],
            'dataSubjectCategories' => $this->dataSubjectCategories !== [],
            'personalDataCategories' => $this->personalDataCategories !== [],
            'legalBasis' => $this->legalBasis !== null,
            'retentionPeriod' => $this->retentionPeriod !== null,
            'technicalOrganizationalMeasures' => $this->technicalOrganizationalMeasures !== null,
        ];

        $filledFields = count(array_filter($requiredFields));
        $totalFields = count($requiredFields);

        return (int) (($filledFields / $totalFields) * 100);
    }

    /**
     * Check if record is complete per Art. 30 GDPR
     */
    public function isComplete(): bool
    {
        return $this->getCompletenessPercentage() === 100;
    }

    /**
     * Get display name for breadcrumbs/titles
     */
    public function getDisplayName(): string
    {
        return $this->name ?? 'Unnamed Processing Activity';
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
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

    public function getPurposes(): array
    {
        return $this->purposes;
    }

    public function setPurposes(array $purposes): static
    {
        $this->purposes = $purposes;
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

    public function getEstimatedDataSubjectsCount(): ?int
    {
        return $this->estimatedDataSubjectsCount;
    }

    public function setEstimatedDataSubjectsCount(?int $estimatedDataSubjectsCount): static
    {
        $this->estimatedDataSubjectsCount = $estimatedDataSubjectsCount;
        return $this;
    }

    public function getPersonalDataCategories(): array
    {
        return $this->personalDataCategories;
    }

    public function setPersonalDataCategories(array $personalDataCategories): static
    {
        $this->personalDataCategories = $personalDataCategories;
        return $this;
    }

    public function getProcessesSpecialCategories(): bool
    {
        return $this->processesSpecialCategories;
    }

    public function setProcessesSpecialCategories(bool $processesSpecialCategories): static
    {
        $this->processesSpecialCategories = $processesSpecialCategories;
        return $this;
    }

    public function getSpecialCategoriesDetails(): ?array
    {
        return $this->specialCategoriesDetails;
    }

    public function setSpecialCategoriesDetails(?array $specialCategoriesDetails): static
    {
        $this->specialCategoriesDetails = $specialCategoriesDetails;
        return $this;
    }

    public function getProcessesCriminalData(): bool
    {
        return $this->processesCriminalData;
    }

    public function setProcessesCriminalData(bool $processesCriminalData): static
    {
        $this->processesCriminalData = $processesCriminalData;
        return $this;
    }

    public function getRecipientCategories(): ?array
    {
        return $this->recipientCategories;
    }

    public function setRecipientCategories(?array $recipientCategories): static
    {
        $this->recipientCategories = $recipientCategories;
        return $this;
    }

    public function getRecipientDetails(): ?string
    {
        return $this->recipientDetails;
    }

    public function setRecipientDetails(?string $recipientDetails): static
    {
        $this->recipientDetails = $recipientDetails;
        return $this;
    }

    public function getHasThirdCountryTransfer(): bool
    {
        return $this->hasThirdCountryTransfer;
    }

    public function setHasThirdCountryTransfer(bool $hasThirdCountryTransfer): static
    {
        $this->hasThirdCountryTransfer = $hasThirdCountryTransfer;
        return $this;
    }

    public function getThirdCountries(): ?array
    {
        return $this->thirdCountries;
    }

    public function setThirdCountries(?array $thirdCountries): static
    {
        $this->thirdCountries = $thirdCountries;
        return $this;
    }

    public function getTransferSafeguards(): ?string
    {
        return $this->transferSafeguards;
    }

    public function setTransferSafeguards(?string $transferSafeguards): static
    {
        $this->transferSafeguards = $transferSafeguards;
        return $this;
    }

    public function getRetentionPeriod(): ?string
    {
        return $this->retentionPeriod;
    }

    public function setRetentionPeriod(?string $retentionPeriod): static
    {
        $this->retentionPeriod = $retentionPeriod;
        return $this;
    }

    public function getRetentionPeriodDays(): ?int
    {
        return $this->retentionPeriodDays;
    }

    public function setRetentionPeriodDays(?int $retentionPeriodDays): static
    {
        $this->retentionPeriodDays = $retentionPeriodDays;
        return $this;
    }

    public function getRetentionLegalBasis(): ?string
    {
        return $this->retentionLegalBasis;
    }

    public function setRetentionLegalBasis(?string $retentionLegalBasis): static
    {
        $this->retentionLegalBasis = $retentionLegalBasis;
        return $this;
    }

    public function getTechnicalOrganizationalMeasures(): ?string
    {
        return $this->technicalOrganizationalMeasures;
    }

    public function setTechnicalOrganizationalMeasures(?string $technicalOrganizationalMeasures): static
    {
        $this->technicalOrganizationalMeasures = $technicalOrganizationalMeasures;
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

    public function getLegalBasis(): ?string
    {
        return $this->legalBasis;
    }

    public function setLegalBasis(string $legalBasis): static
    {
        $this->legalBasis = $legalBasis;
        return $this;
    }

    public function getLegalBasisDetails(): ?string
    {
        return $this->legalBasisDetails;
    }

    public function setLegalBasisDetails(?string $legalBasisDetails): static
    {
        $this->legalBasisDetails = $legalBasisDetails;
        return $this;
    }

    public function getLegalBasisSpecialCategories(): ?string
    {
        return $this->legalBasisSpecialCategories;
    }

    public function setLegalBasisSpecialCategories(?string $legalBasisSpecialCategories): static
    {
        $this->legalBasisSpecialCategories = $legalBasisSpecialCategories;
        return $this;
    }

    public function getResponsibleDepartment(): ?string
    {
        return $this->responsibleDepartment;
    }

    public function setResponsibleDepartment(?string $responsibleDepartment): static
    {
        $this->responsibleDepartment = $responsibleDepartment;
        return $this;
    }

    public function getContactPerson(): ?User
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?User $user): static
    {
        $this->contactPerson = $user;
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

    public function getInvolvesProcessors(): bool
    {
        return $this->involvesProcessors;
    }

    public function setInvolvesProcessors(bool $involvesProcessors): static
    {
        $this->involvesProcessors = $involvesProcessors;
        return $this;
    }

    public function getProcessors(): ?array
    {
        return $this->processors;
    }

    public function setProcessors(?array $processors): static
    {
        $this->processors = $processors;
        return $this;
    }

    public function getIsJointController(): bool
    {
        return $this->isJointController;
    }

    public function setIsJointController(bool $isJointController): static
    {
        $this->isJointController = $isJointController;
        return $this;
    }

    public function getJointControllerDetails(): ?array
    {
        return $this->jointControllerDetails;
    }

    public function setJointControllerDetails(?array $jointControllerDetails): static
    {
        $this->jointControllerDetails = $jointControllerDetails;
        return $this;
    }

    public function getIsHighRisk(): bool
    {
        return $this->isHighRisk;
    }

    public function setIsHighRisk(bool $isHighRisk): static
    {
        $this->isHighRisk = $isHighRisk;
        return $this;
    }

    public function getDpiaCompleted(): bool
    {
        return $this->dpiaCompleted;
    }

    public function setDpiaCompleted(bool $dpiaCompleted): static
    {
        $this->dpiaCompleted = $dpiaCompleted;
        return $this;
    }

    public function getDpiaDate(): ?DateTimeInterface
    {
        return $this->dpiaDate;
    }

    public function setDpiaDate(?DateTimeInterface $dpiaDate): static
    {
        $this->dpiaDate = $dpiaDate;
        return $this;
    }

    public function getRiskLevel(): ?string
    {
        return $this->riskLevel;
    }

    public function setRiskLevel(?string $riskLevel): static
    {
        $this->riskLevel = $riskLevel;
        return $this;
    }

    public function getDataSources(): ?array
    {
        return $this->dataSources;
    }

    public function setDataSources(?array $dataSources): static
    {
        $this->dataSources = $dataSources;
        return $this;
    }

    public function getHasAutomatedDecisionMaking(): bool
    {
        return $this->hasAutomatedDecisionMaking;
    }

    public function setHasAutomatedDecisionMaking(bool $hasAutomatedDecisionMaking): static
    {
        $this->hasAutomatedDecisionMaking = $hasAutomatedDecisionMaking;
        return $this;
    }

    public function getAutomatedDecisionMakingDetails(): ?string
    {
        return $this->automatedDecisionMakingDetails;
    }

    public function setAutomatedDecisionMakingDetails(?string $automatedDecisionMakingDetails): static
    {
        $this->automatedDecisionMakingDetails = $automatedDecisionMakingDetails;
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

    public function getStartDate(): ?DateTimeInterface
    {
        return $this->startDate;
    }

    public function setStartDate(?DateTimeInterface $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): ?DateTimeInterface
    {
        return $this->endDate;
    }

    public function setEndDate(?DateTimeInterface $endDate): static
    {
        $this->endDate = $endDate;
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

    /**
     * @return Collection<int, Consent>
     */
    public function getConsents(): Collection
    {
        return $this->consents;
    }

    public function addConsent(Consent $consent): static
    {
        if (!$this->consents->contains($consent)) {
            $this->consents->add($consent);
            $consent->setProcessingActivity($this);
        }

        return $this;
    }

    public function removeConsent(Consent $consent): static
    {
        if ($this->consents->removeElement($consent)) {
            if ($consent->getProcessingActivity() === $this) {
                $consent->setProcessingActivity(null);
            }
        }

        return $this;
    }

    /**
     * Check if processing activity has valid consents
     * Required when legalBasis = 'consent' (Art. 6(1)(a) GDPR)
     */
    public function hasValidConsents(): bool
    {
        if ($this->legalBasis !== 'consent') {
            return true; // Not based on consent
        }

        $activeConsents = $this->consents->filter(
            fn(Consent $c) => $c->getStatus() === 'active'
                && $c->isVerifiedByDpo()
                && !$c->isRevoked()
        );

        return $activeConsents->count() > 0;
    }

    /**
     * Get count of active verified consents
     */
    public function getActiveConsentCount(): int
    {
        return $this->consents->filter(
            fn(Consent $c) => $c->getStatus() === 'active'
                && $c->isVerifiedByDpo()
                && !$c->isRevoked()
        )->count();
    }
}
