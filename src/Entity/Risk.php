<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use Deprecated;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Enum\IncidentSeverity;
use App\Enum\RiskStatus;
use App\Enum\TreatmentStrategy;
use App\Repository\RiskRepository;
use App\Service\OwnerResolver;
use App\State\TenantAwareStateProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: RiskRepository::class)]
#[ORM\Index(name: 'idx_risk_status', columns: ['status'])]
#[ORM\Index(name: 'idx_risk_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_risk_review_date', columns: ['review_date'])]
#[ORM\Index(name: 'idx_risk_tenant', columns: ['tenant_id'])]
#[ApiResource(
    operations: [
        new Get(
            description: 'Retrieve a specific risk assessment by ID',
            security: "is_granted('view', object)"
        ),
        new GetCollection(
            description: 'Retrieve the collection of risk assessments with filtering by status and date',
            security: "is_granted('ROLE_USER')"
        ),
        new Post(
            description: 'Create a new risk assessment with probability and impact analysis',
            securityPostDenormalize: "is_granted('ROLE_USER')"
        ),
        new Put(
            description: 'Update an existing risk assessment',
            security: "is_granted('edit', object)"
        ),
        new Delete(
            description: 'Delete a risk assessment (Admin only)',
            security: "is_granted('delete', object)"
        ),
    ],
    normalizationContext: ['groups' => ['risk:read']],
    denormalizationContext: ['groups' => ['risk:write']],
    processor: TenantAwareStateProcessor::class
)]
#[ApiFilter(SearchFilter::class, properties: ['title' => 'partial', 'status' => 'exact', 'riskOwner.email' => 'partial', 'riskOwner.firstName' => 'partial', 'riskOwner.lastName' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['title', 'createdAt', 'status'])]
#[ApiFilter(DateFilter::class, properties: ['createdAt', 'reviewDate'])]
class Risk
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['risk:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['risk:read'])]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\NotBlank(message: 'Risk title is required')]
    #[Assert\Length(max: 255, maxMessage: 'Risk title cannot exceed { limit } characters')]
    private ?string $title = null;

    /**
     * Risk Category (ISO 27005:2022 Section 8.2.3)
     * Categorization for better risk grouping and reporting
     */
    #[ORM\Column(length: 100)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\NotBlank(message: 'risk.validation.category_required')]
    #[Assert\Choice(
        choices: ['financial', 'operational', 'compliance', 'strategic', 'reputational', 'security'],
        message: 'risk.validation.category_invalid'
    )]
    private ?string $category = null;

    /**
     * DSGVO Risk Assessment Extension (Priority 2.2)
     * DSGVO/GDPR Art. 32 - Security of processing
     */

    /**
     * Indicates if this risk involves processing of personal data
     * DSGVO Art. 4(1) - Personal Data Definition
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['risk:read', 'risk:write'])]
    private bool $involvesPersonalData = false;

    /**
     * Indicates if this risk involves special category data (Art. 9 DSGVO)
     * Includes: racial/ethnic origin, political opinions, religious beliefs,
     * trade union membership, genetic data, biometric data, health data, sex life
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['risk:read', 'risk:write'])]
    private bool $involvesSpecialCategoryData = false;

    /**
     * Legal basis for processing (Art. 6 DSGVO)
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\Choice(
        choices: ['consent', 'contract', 'legal_obligation', 'vital_interests', 'public_task', 'legitimate_interests'],
        message: 'risk.validation.legal_basis_invalid'
    )]
    private ?string $legalBasis = null;

    /**
     * Scale of data processing operation
     * large_scale requires DPIA (Art. 35 DSGVO)
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\Choice(
        choices: ['small', 'medium', 'large_scale'],
        message: 'risk.validation.processing_scale_invalid'
    )]
    private ?string $processingScale = null;

    /**
     * Indicates if a Data Protection Impact Assessment (DPIA) is required
     * DSGVO Art. 35 - Data Protection Impact Assessment
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    #[Groups(['risk:read', 'risk:write'])]
    private bool $requiresDPIA = false;

    /**
     * Assessment of impact on data subjects' rights and freedoms
     * DSGVO Art. 35(7) - DPIA content requirements
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $dataSubjectImpact = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\NotBlank(message: 'Risk description is required')]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $threat = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $vulnerability = null;

    /**
     * Risk Subject - Asset, Person, Location, or Supplier
     * ISO 27005:2022 Section 7.2.3 - Risk identification
     * At least one subject must be associated with each risk
     */
    #[ORM\ManyToOne(inversedBy: 'risks')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    private ?Asset $asset = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    private ?Person $person = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    private ?Location $location = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    private ?Supplier $supplier = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\NotNull(message: 'Probability is required')]
    #[Assert\Range(notInRangeMessage: 'Probability must be between { min } and { max }', min: 1, max: 5)]
    private ?int $probability = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\NotNull(message: 'Impact is required')]
    #[Assert\Range(notInRangeMessage: 'Impact must be between { min } and { max }', min: 1, max: 5)]
    private ?int $impact = null;

    // Set default values for required fields
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\Range(notInRangeMessage: 'Residual probability must be between { min } and { max }', min: 1, max: 5)]
    private ?int $residualProbability = 1;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\Range(notInRangeMessage: 'Residual impact must be between { min } and { max }', min: 1, max: 5)]
    private ?int $residualImpact = 1;

    #[ORM\Column(type: 'string', length: 50, nullable: true, enumType: TreatmentStrategy::class)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?TreatmentStrategy $treatmentStrategy = TreatmentStrategy::Mitigate;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $treatmentDescription = null;

    /**
     * Risk Owner (ISO 27001:2022 - A.5.1 Policies for information security)
     * Person responsible for managing this risk
     * Phase 6F-B: Changed from string to User entity reference
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'risk_owner_id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    #[Assert\NotNull(message: 'risk.validation.risk_owner_required')]
    private ?User $riskOwner = null;

    #[ORM\Column(type: 'string', length: 50, enumType: RiskStatus::class)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\NotNull(message: 'Status is required')]
    private ?RiskStatus $status = RiskStatus::Identified;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?DateTimeInterface $reviewDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['risk:read'])]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['risk:read'])]
    private ?DateTimeInterface $updatedAt = null;

    /**
     * Risk Acceptance Approval (ISO 27005)
     * Required when treatmentStrategy = 'accept'
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $acceptanceApprovedBy = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?DateTimeInterface $acceptanceApprovedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $acceptanceJustification = null;

    /**
     * Risk Acceptance Expiry (ISO 27001 Cl. 8.3 — Audit-V3 LB-7)
     *
     * Date until which the risk acceptance is valid. After this date the
     * acceptance must be re-evaluated by the risk owner. Closing the
     * audit-finding "risk acceptance without expiry date" requires every
     * accepted risk to carry a finite review horizon. Auditors check this
     * field as a tripwire for stale acceptances.
     */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?DateTimeInterface $acceptanceExpiryDate = null;

    /**
     * Formal approval for risk acceptance
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['risk:read'])]
    private bool $formallyAccepted = false;

    /**
     * Likelihood / probability justification (ISO 27001:2022 6.1.2.d — audit-required)
     * Score without textual justification is not audit-proof.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $likelihoodJustification = null;

    /**
     * Impact justification (ISO 27001:2022 6.1.2.d — audit-required)
     * Score without textual justification is not audit-proof.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $impactJustification = null;

    /**
     * Decision rationale for risk treatment (ISO 31000 §6.5.4)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $decisionRationale = null;

    /**
     * User who approved the risk treatment decision (replaces plain-text PT-F01 vector)
     * CVSS 9.1 fix: EntityType instead of free-text field.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decision_approved_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk:read', 'risk:write'])]
    private ?User $decisionApprovedByUser = null;

    /**
     * Date when the risk treatment decision was approved.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?\DateTimeImmutable $decisionApprovalDate = null;

    /**
     * Custom review interval in days (overrides default from RiskReviewService).
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?int $reviewIntervalDays = null;

    /**
     * Risk Communication Plan (ISO 31000 Section 6.2)
     * Structure: [{stakeholder: string, frequency: string, method: string, content: string}]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?array $communicationPlan = null;

    /**
     * Link to ThreatIntelligence entity for structured threat data
     */
    #[ORM\ManyToOne(targetEntity: ThreatIntelligence::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    private ?ThreatIntelligence $threatIntelligence = null;

    /**
     * Link to Vulnerability entity for structured vulnerability data
     */
    #[ORM\ManyToOne(targetEntity: Vulnerability::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    private ?Vulnerability $linkedVulnerability = null;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class, mappedBy: 'risks')]
    #[Groups(['risk:read'])]
    #[MaxDepth(1)]
    private Collection $controls;

    /**
     * @var Collection<int, Incident>
     */
    #[ORM\ManyToMany(targetEntity: Incident::class, mappedBy: 'realizedRisks')]
    #[Groups(['risk:read'])]
    #[MaxDepth(1)]
    private Collection $incidents;

    public function __construct()
    {
        $this->controls = new ArrayCollection();
        $this->incidents = new ArrayCollection();
        $this->riskOwnerDeputyPersons = new ArrayCollection();
        $this->ictAssetDependency = new ArrayCollection();
        $this->ictIncidentHistory = new ArrayCollection();
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
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

    public function getThreat(): ?string
    {
        return $this->threat;
    }

    public function setThreat(?string $threat): static
    {
        $this->threat = $threat;
        return $this;
    }

    public function getVulnerability(): ?string
    {
        return $this->vulnerability;
    }

    public function setVulnerability(?string $vulnerability): static
    {
        $this->vulnerability = $vulnerability;
        return $this;
    }

    public function getAsset(): ?Asset
    {
        return $this->asset;
    }

    public function setAsset(?Asset $asset): static
    {
        $this->asset = $asset;
        return $this;
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    public function setPerson(?Person $person): static
    {
        $this->person = $person;
        return $this;
    }

    public function getLocation(): ?Location
    {
        return $this->location;
    }

    public function setLocation(?Location $location): static
    {
        $this->location = $location;
        return $this;
    }

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): static
    {
        $this->supplier = $supplier;
        return $this;
    }

    /**
     * Get the primary risk subject (Asset, Person, Location, or Supplier)
     * Returns the first non-null relationship
     */
    public function getRiskSubject(): ?object
    {
        return $this->asset ?? $this->person ?? $this->location ?? $this->supplier;
    }

    /**
     * Get the name/title of the risk subject
     */
    public function getRiskSubjectName(): ?string
    {
        if ($this->asset instanceof Asset) {
            return $this->asset->getName();
        }
        if ($this->person instanceof Person) {
            return $this->person->getFullName();
        }
        if ($this->location instanceof Location) {
            return $this->location->getName();
        }
        if ($this->supplier instanceof Supplier) {
            return $this->supplier->getName();
        }
        return null;
    }

    /**
     * Get the type of risk subject
     */
    public function getRiskSubjectType(): ?string
    {
        if ($this->asset instanceof Asset) {
            return 'asset';
        }
        if ($this->person instanceof Person) {
            return 'person';
        }
        if ($this->location instanceof Location) {
            return 'location';
        }
        if ($this->supplier instanceof Supplier) {
            return 'supplier';
        }
        return null;
    }

    public function getProbability(): ?int
    {
        return $this->probability;
    }

    public function setProbability(int $probability): static
    {
        $this->probability = $probability;
        return $this;
    }

    public function getImpact(): ?int
    {
        return $this->impact;
    }

    public function setImpact(int $impact): static
    {
        $this->impact = $impact;
        return $this;
    }

    public function getResidualProbability(): ?int
    {
        return $this->residualProbability;
    }

    public function setResidualProbability(int $residualProbability): static
    {
        $this->residualProbability = $residualProbability;
        return $this;
    }

    public function getResidualImpact(): ?int
    {
        return $this->residualImpact;
    }

    public function setResidualImpact(int $residualImpact): static
    {
        $this->residualImpact = $residualImpact;
        return $this;
    }

    public function getTreatmentStrategy(): ?TreatmentStrategy
    {
        return $this->treatmentStrategy;
    }

    public function setTreatmentStrategy(?TreatmentStrategy $treatmentStrategy): static
    {
        $this->treatmentStrategy = $treatmentStrategy;
        return $this;
    }

    public function getTreatmentDescription(): ?string
    {
        return $this->treatmentDescription;
    }

    public function setTreatmentDescription(?string $treatmentDescription): static
    {
        $this->treatmentDescription = $treatmentDescription;
        return $this;
    }

    public function getRiskOwner(): ?User
    {
        return $this->riskOwner;
    }

    public function setRiskOwner(?User $user): static
    {
        $this->riskOwner = $user;
        return $this;
    }

    /**
     * Get risk owner's full name for display
     * Data Reuse: Quick access to owner name without loading full User entity
     */
    #[Groups(['risk:read'])]
    public function getRiskOwnerName(): ?string
    {
        return $this->riskOwner?->getFullName();
    }

    public function getStatus(): ?RiskStatus
    {
        return $this->status;
    }

    public function setStatus(?RiskStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getReviewDate(): ?DateTimeInterface
    {
        return $this->reviewDate;
    }

    public function setReviewDate(?DateTimeInterface $reviewDate): static
    {
        $this->reviewDate = $reviewDate;
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

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, Control>
     */
    public function getControls(): Collection
    {
        return $this->controls;
    }

    public function addControl(Control $control): static
    {
        if (!$this->controls->contains($control)) {
            $this->controls->add($control);
            $control->addRisk($this);
        }
        return $this;
    }

    public function removeControl(Control $control): static
    {
        if ($this->controls->removeElement($control)) {
            $control->removeRisk($this);
        }
        return $this;
    }

    #[Groups(['risk:read'])]
    public function getInherentRiskLevel(): int
    {
        return ($this->probability ?? 0) * ($this->impact ?? 0);
    }

    /**
     * Alias for getInherentRiskLevel() for backward compatibility
     * Risk score is calculated as probability * impact (inherent risk)
     */
    #[Groups(['risk:read'])]
    public function getRiskScore(): int
    {
        return $this->getInherentRiskLevel();
    }

    #[Groups(['risk:read'])]
    public function getResidualRiskLevel(): int
    {
        return ($this->residualProbability ?? 0) * ($this->residualImpact ?? 0);
    }

    #[Groups(['risk:read'])]
    public function getRiskReduction(): float
    {
        $inherent = $this->getInherentRiskLevel();
        if ($inherent === 0) {
            return 0.0;
        }

        $residual = $this->getResidualRiskLevel();
        return round((($inherent - $residual) / $inherent) * 100, 2);
    }

    /**
     * @return Collection<int, Incident>
     */
    public function getIncidents(): Collection
    {
        return $this->incidents;
    }

    public function addIncident(Incident $incident): static
    {
        if (!$this->incidents->contains($incident)) {
            $this->incidents->add($incident);
            $incident->addRealizedRisk($this);
        }
        return $this;
    }

    public function removeIncident(Incident $incident): static
    {
        if ($this->incidents->removeElement($incident)) {
            $incident->removeRealizedRisk($this);
        }
        return $this;
    }

    /**
     * Check if this risk has been realized (incident occurred)
     * Data Reuse: Validates risk assessment with real-world incidents
     */
    #[Groups(['risk:read'])]
    public function hasBeenRealized(): bool
    {
        return !$this->incidents->isEmpty();
    }

    /**
     * Get realization count
     * Data Reuse: Frequency analysis for risk assessment calibration
     */
    #[Groups(['risk:read'])]
    public function getRealizationCount(): int
    {
        return $this->incidents->count();
    }

    /**
     * Check if risk assessment was accurate based on incidents
     * Data Reuse: Compare predicted impact vs actual incident severity
     */
    #[Groups(['risk:read'])]
    public function isAssessmentAccurate(): ?bool
    {
        if ($this->incidents->isEmpty()) {
            return null; // Cannot validate without incidents
        }

        $predictedLevel = $this->getInherentRiskLevel();

        // Compare with average incident severity
        $criticalIncidents = 0;
        foreach ($this->incidents as $incident) {
            if (in_array($incident->getSeverity(), [IncidentSeverity::Critical, IncidentSeverity::High])) {
                $criticalIncidents++;
            }
        }
        // High predicted risk should correlate with critical incidents
        if ($predictedLevel >= 16) {
            // High risk (4x4 or higher)
            return $criticalIncidents > 0;
        }

        // High predicted risk should correlate with critical incidents
        if ($predictedLevel >= 9) {
            // Medium risk
            return $criticalIncidents === 0;
            // Should NOT have critical incidents
        }
        else { // Low risk
            return $criticalIncidents === 0; // Low risk should NOT have critical incidents
        }
    }

    /**
     * Get most recent incident for this risk
     * Data Reuse: Track latest realization
     */
    #[Groups(['risk:read'])]
    public function getMostRecentIncident(): ?Incident
    {
        if ($this->incidents->isEmpty()) {
            return null;
        }

        $latest = null;
        foreach ($this->incidents as $incident) {
            if ($latest === null || $incident->getDetectedAt() > $latest->getDetectedAt()) {
                $latest = $incident;
            }
        }

        return $latest;
    }

    /**
     * Check if this is a high-risk item
     * Data Reuse: Risk classification for prioritization
     */
    #[Groups(['risk:read'])]
    public function isHighRisk(): bool
    {
        return $this->getInherentRiskLevel() >= 15;
    }

    /**
     * Get count of controls mitigating this risk
     * Data Reuse: Control coverage metric
     */
    #[Groups(['risk:read'])]
    public function getControlCoverageCount(): int
    {
        return $this->controls->count();
    }

    /**
     * Get count of incidents related to this risk
     * Data Reuse: Realization frequency (alias for getRealizationCount)
     */
    #[Groups(['risk:read'])]
    public function getIncidentCount(): int
    {
        return $this->getRealizationCount();
    }

    // Risk Acceptance Approval Getters/Setters (ISO 27005)

    public function getAcceptanceApprovedBy(): ?string
    {
        return $this->acceptanceApprovedBy;
    }

    public function setAcceptanceApprovedBy(?string $acceptanceApprovedBy): static
    {
        $this->acceptanceApprovedBy = $acceptanceApprovedBy;
        return $this;
    }

    public function getAcceptanceApprovedAt(): ?DateTimeInterface
    {
        return $this->acceptanceApprovedAt;
    }

    public function setAcceptanceApprovedAt(?DateTimeInterface $acceptanceApprovedAt): static
    {
        $this->acceptanceApprovedAt = $acceptanceApprovedAt;
        return $this;
    }

    public function getAcceptanceJustification(): ?string
    {
        return $this->acceptanceJustification;
    }

    public function setAcceptanceJustification(?string $acceptanceJustification): static
    {
        $this->acceptanceJustification = $acceptanceJustification;
        return $this;
    }

    public function getAcceptanceExpiryDate(): ?DateTimeInterface
    {
        return $this->acceptanceExpiryDate;
    }

    public function setAcceptanceExpiryDate(?DateTimeInterface $acceptanceExpiryDate): static
    {
        $this->acceptanceExpiryDate = $acceptanceExpiryDate;
        return $this;
    }

    /**
     * Whether the risk acceptance has passed its expiry date and must be
     * re-evaluated. Returns false when no expiry is set or strategy is
     * not "accept" — the caller should treat that as "no acceptance, no
     * expiry signal".
     */
    #[Groups(['risk:read'])]
    public function isAcceptanceExpired(): bool
    {
        if ($this->treatmentStrategy !== TreatmentStrategy::Accept) {
            return false;
        }
        if ($this->acceptanceExpiryDate === null) {
            return false;
        }
        return $this->acceptanceExpiryDate < new \DateTimeImmutable('today');
    }

    /**
     * Number of days the acceptance has been expired. Returns 0 if not
     * expired, positive int otherwise.
     */
    #[Groups(['risk:read'])]
    public function getAcceptanceExpiredDays(): int
    {
        if (!$this->isAcceptanceExpired() || $this->acceptanceExpiryDate === null) {
            return 0;
        }
        $now = new \DateTimeImmutable('today');
        $diff = $now->diff($this->acceptanceExpiryDate);
        return (int) $diff->days;
    }

    public function isFormallyAccepted(): bool
    {
        return $this->formallyAccepted;
    }

    public function setFormallyAccepted(bool $formallyAccepted): static
    {
        $this->formallyAccepted = $formallyAccepted;
        return $this;
    }

    /**
     * Check if risk acceptance requires approval
     * ISO 27005 compliant: Risk acceptance must be formally approved
     */
    #[Groups(['risk:read'])]
    public function isAcceptanceApprovalRequired(): bool
    {
        return $this->treatmentStrategy === TreatmentStrategy::Accept && !$this->formallyAccepted;
    }

    /**
     * Alias for backward compatibility
     */
    #[Deprecated(message: 'Use isAcceptanceApprovalRequired() instead')]
    public function requiresAcceptanceApproval(): bool
    {
        return $this->isAcceptanceApprovalRequired();
    }

    /**
     * Check if risk acceptance is properly documented
     */
    #[Groups(['risk:read'])]
    public function isAcceptanceComplete(): bool
    {
        if ($this->treatmentStrategy !== TreatmentStrategy::Accept) {
            return true; // Not applicable
        }

        return $this->formallyAccepted
            && $this->acceptanceApprovedBy !== null
            && $this->acceptanceApprovedAt instanceof DateTimeInterface
            && $this->acceptanceJustification !== null;
    }

    /**
     * Get risk acceptance status
     */
    #[Groups(['risk:read'])]
    public function getAcceptanceStatus(): string
    {
        if ($this->treatmentStrategy !== TreatmentStrategy::Accept) {
            return 'not_applicable';
        }

        if (!$this->formallyAccepted) {
            return 'pending_approval';
        }

        if (!$this->isAcceptanceComplete()) {
            return 'incomplete_documentation';
        }

        return 'approved';
    }

    // DSGVO Risk Assessment Extension - Getter/Setter Methods (Priority 2.2)

    public function isInvolvesPersonalData(): bool
    {
        return $this->involvesPersonalData;
    }

    public function setInvolvesPersonalData(bool $involvesPersonalData): static
    {
        $this->involvesPersonalData = $involvesPersonalData;
        return $this;
    }

    public function isInvolvesSpecialCategoryData(): bool
    {
        return $this->involvesSpecialCategoryData;
    }

    public function setInvolvesSpecialCategoryData(bool $involvesSpecialCategoryData): static
    {
        $this->involvesSpecialCategoryData = $involvesSpecialCategoryData;
        return $this;
    }

    public function getLegalBasis(): ?string
    {
        return $this->legalBasis;
    }

    public function setLegalBasis(?string $legalBasis): static
    {
        $this->legalBasis = $legalBasis;
        return $this;
    }

    public function getProcessingScale(): ?string
    {
        return $this->processingScale;
    }

    public function setProcessingScale(?string $processingScale): static
    {
        $this->processingScale = $processingScale;
        return $this;
    }

    public function isRequiresDPIA(): bool
    {
        return $this->requiresDPIA;
    }

    public function setRequiresDPIA(bool $requiresDPIA): static
    {
        $this->requiresDPIA = $requiresDPIA;
        return $this;
    }

    public function getDataSubjectImpact(): ?string
    {
        return $this->dataSubjectImpact;
    }

    public function setDataSubjectImpact(?string $dataSubjectImpact): static
    {
        $this->dataSubjectImpact = $dataSubjectImpact;
        return $this;
    }

    // Review Interval, Communication Plan, Threat Intelligence, Vulnerability Getters/Setters

    public function getReviewIntervalDays(): ?int
    {
        return $this->reviewIntervalDays;
    }

    public function setReviewIntervalDays(?int $reviewIntervalDays): static
    {
        $this->reviewIntervalDays = $reviewIntervalDays;
        return $this;
    }

    public function getCommunicationPlan(): ?array
    {
        return $this->communicationPlan;
    }

    public function setCommunicationPlan(?array $communicationPlan): static
    {
        $this->communicationPlan = $communicationPlan;
        return $this;
    }

    public function getThreatIntelligence(): ?ThreatIntelligence
    {
        return $this->threatIntelligence;
    }

    public function setThreatIntelligence(?ThreatIntelligence $threatIntelligence): static
    {
        $this->threatIntelligence = $threatIntelligence;
        return $this;
    }

    public function getLinkedVulnerability(): ?Vulnerability
    {
        return $this->linkedVulnerability;
    }

    public function setLinkedVulnerability(?Vulnerability $linkedVulnerability): static
    {
        $this->linkedVulnerability = $linkedVulnerability;
        return $this;
    }

    /**
     * Pattern A dual-state: preferred structured owner. Falls back to string acceptanceApprovedBy.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'acceptance_approved_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $acceptanceApprovedByUser = null;

    public function getAcceptanceApprovedByUser(): ?User
    {
        return $this->acceptanceApprovedByUser;
    }

    public function setAcceptanceApprovedByUser(?User $acceptanceApprovedByUser): static
    {
        $this->acceptanceApprovedByUser = $acceptanceApprovedByUser;
        return $this;
    }

    /**
     * Effective acceptanceApprovedBy: prefer acceptanceApprovedByUser.fullName, fall back to legacy string.
     */
    public function getEffectiveAcceptanceApprovedBy(): ?string
    {
        return $this->acceptanceApprovedByUser?->getFullName() ?? $this->acceptanceApprovedBy;
    }

    /**
     * Person-based primary risk owner: for persons without a system login.
     * Falls back to riskOwner (User) → Person → no legacy string (Risk had no
     * string field; getEffectiveRiskOwner falls back to null when both are unset).
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'risk_owner_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk:read', 'risk:write'])]
    private ?Person $riskOwnerPerson = null;

    public function getRiskOwnerPerson(): ?Person
    {
        return $this->riskOwnerPerson;
    }

    public function setRiskOwnerPerson(?Person $riskOwnerPerson): static
    {
        $this->riskOwnerPerson = $riskOwnerPerson;
        return $this;
    }

    /**
     * Deputies / Vertretung — n additional Persons sharing ownership of this risk.
     *
     * @var Collection<int, Person>
     */
    #[Groups(['risk:read', 'risk:write'])]
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'risk_owner_deputy')]
    #[ORM\JoinColumn(name: 'risk_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $riskOwnerDeputyPersons;

    /** @return Collection<int, Person> */
    public function getRiskOwnerDeputyPersons(): Collection
    {
        return $this->riskOwnerDeputyPersons;
    }

    public function addRiskOwnerDeputyPerson(Person $person): static
    {
        if (!$this->riskOwnerDeputyPersons->contains($person)) {
            $this->riskOwnerDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeRiskOwnerDeputyPerson(Person $person): static
    {
        $this->riskOwnerDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective risk owner: prefer riskOwner (User), then riskOwnerPerson, then null.
     * Note: Risk has no legacy string field — the chain stops at Person.
     */
    public function getEffectiveRiskOwner(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->riskOwner,
            $this->riskOwnerPerson,
            null,
        );
    }

    /**
     * Full risk owner roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllRiskOwners(): array
    {
        return OwnerResolver::resolveAll(
            $this->riskOwner,
            $this->riskOwnerPerson,
            null,
            $this->riskOwnerDeputyPersons,
        );
    }

    // -------------------------------------------------------------------------
    // Justification & Decision fields (T31.1.2)
    // -------------------------------------------------------------------------

    public function getLikelihoodJustification(): ?string
    {
        return $this->likelihoodJustification;
    }

    public function setLikelihoodJustification(?string $likelihoodJustification): static
    {
        $this->likelihoodJustification = $likelihoodJustification;
        return $this;
    }

    public function getImpactJustification(): ?string
    {
        return $this->impactJustification;
    }

    public function setImpactJustification(?string $impactJustification): static
    {
        $this->impactJustification = $impactJustification;
        return $this;
    }

    public function getDecisionRationale(): ?string
    {
        return $this->decisionRationale;
    }

    public function setDecisionRationale(?string $decisionRationale): static
    {
        $this->decisionRationale = $decisionRationale;
        return $this;
    }

    public function getDecisionApprovedByUser(): ?User
    {
        return $this->decisionApprovedByUser;
    }

    public function setDecisionApprovedByUser(?User $decisionApprovedByUser): static
    {
        $this->decisionApprovedByUser = $decisionApprovedByUser;
        return $this;
    }

    public function getDecisionApprovalDate(): ?\DateTimeImmutable
    {
        return $this->decisionApprovalDate;
    }

    public function setDecisionApprovalDate(?\DateTimeImmutable $decisionApprovalDate): static
    {
        $this->decisionApprovalDate = $decisionApprovalDate;
        return $this;
    }

    // ── Sprint 7-A: DORA ICT-Risk fields (gated 'nis2_dora' module) ──────────

    /**
     * ICT risk category (DORA Art. 6(8)).
     * Values: cyber | operations | third_party_ict | concentration | data_integrity
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $ictRiskCategory = null;

    /**
     * Whether the risk concerns a critical or important function (DORA Art. 6(2)+Annex).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['risk:read', 'risk:write'])]
    private bool $criticalOrImportantFunction = false;

    /**
     * ICT third-party concentration risk flag (DORA Art. 28+29).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['risk:read', 'risk:write'])]
    private bool $ictThirdPartyConcentration = false;

    /**
     * ICT asset dependency M2M.
     * NOTE: Risk already has $asset (ManyToOne). This collection covers multi-asset ICT dependencies.
     *
     * @var Collection<int, Asset>
     */
    #[ORM\ManyToMany(targetEntity: Asset::class)]
    #[ORM\JoinTable(name: 'risk_ict_asset_dependency')]
    #[ORM\JoinColumn(name: 'risk_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'asset_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['risk:read', 'risk:write'])]
    private Collection $ictAssetDependency;

    /**
     * ICT incident history M2M (DORA Art. 17-19).
     * NOTE: Risk already has $incidents (ManyToMany, mappedBy). This separate collection
     * tracks ICT-specific incident references for DORA reporting.
     *
     * @var Collection<int, Incident>
     */
    #[ORM\ManyToMany(targetEntity: Incident::class)]
    #[ORM\JoinTable(name: 'risk_ict_incident_history')]
    #[ORM\JoinColumn(name: 'risk_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'incident_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[Groups(['risk:read', 'risk:write'])]
    private Collection $ictIncidentHistory;

    /**
     * Data resilience requirement (RTO/RPO, Art. 11(2)(c)).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $dataResilienceRequirement = null;

    /**
     * Threat-Led Penetration Test scope flag (DORA Art. 26-27).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['risk:read', 'risk:write'])]
    private bool $tlptScope = false;

    /**
     * Whether regulatory reporting is required (DORA Art. 19 + NIS2 Art. 23).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['risk:read', 'risk:write'])]
    private bool $regulatoryReportingRequired = false;

    /**
     * Whether board escalation is required (DORA Art. 5(2)).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['risk:read', 'risk:write'])]
    private bool $boardEscalationRequired = false;

    /**
     * Whether lessons learned have been documented (DORA Art. 13).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['risk:read', 'risk:write'])]
    private bool $lessonsLearnedDocumented = false;

    // ── FAIR Quantitative Risk fields (gated: quantitative_risk module) ───

    /** FAIR Loss Event Frequency — minimum (annualised). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $lossEventFrequencyMin = null;

    /** FAIR Loss Event Frequency — maximum (annualised). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $lossEventFrequencyMax = null;

    /** FAIR Loss Event Frequency — most likely (mode). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $lossEventFrequencyMode = null;

    /** FAIR Threat Event Frequency — minimum (annualised). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $threatEventFrequencyMin = null;

    /** FAIR Threat Event Frequency — maximum (annualised). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $threatEventFrequencyMax = null;

    /** FAIR Threat Event Frequency — most likely (mode). */
    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $threatEventFrequencyMode = null;

    /** FAIR Vulnerability probability (0.0000–1.0000): how likely a threat event leads to loss. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 4, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $vulnerabilityProbability = null;

    /** FAIR Primary Loss Magnitude — minimum (€). */
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $primaryLossMagnitudeMin = null;

    /** FAIR Primary Loss Magnitude — maximum (€). */
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $primaryLossMagnitudeMax = null;

    /** FAIR Primary Loss Magnitude — most likely (mode, €). */
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $primaryLossMagnitudeMode = null;

    /** FAIR Secondary Loss Magnitude — minimum (€, includes reputation/fines). */
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $secondaryLossMagnitudeMin = null;

    /** FAIR Secondary Loss Magnitude — maximum (€). */
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $secondaryLossMagnitudeMax = null;

    /** FAIR Secondary Loss Magnitude — most likely (mode, €). */
    #[ORM\Column(type: 'decimal', precision: 15, scale: 2, nullable: true)]
    #[Groups(['risk:read', 'risk:write'])]
    private ?string $secondaryLossMagnitudeMode = null;

    public function getIctRiskCategory(): ?string
    {
        return $this->ictRiskCategory;
    }

    public function setIctRiskCategory(?string $ictRiskCategory): static
    {
        $this->ictRiskCategory = $ictRiskCategory;
        return $this;
    }

    public function isCriticalOrImportantFunction(): bool
    {
        return $this->criticalOrImportantFunction;
    }

    public function setCriticalOrImportantFunction(bool $criticalOrImportantFunction): static
    {
        $this->criticalOrImportantFunction = $criticalOrImportantFunction;
        return $this;
    }

    public function isIctThirdPartyConcentration(): bool
    {
        return $this->ictThirdPartyConcentration;
    }

    public function setIctThirdPartyConcentration(bool $ictThirdPartyConcentration): static
    {
        $this->ictThirdPartyConcentration = $ictThirdPartyConcentration;
        return $this;
    }

    /** @return Collection<int, Asset> */
    public function getIctAssetDependency(): Collection
    {
        return $this->ictAssetDependency;
    }

    public function addIctAssetDependency(Asset $asset): static
    {
        if (!$this->ictAssetDependency->contains($asset)) {
            $this->ictAssetDependency->add($asset);
        }
        return $this;
    }

    public function removeIctAssetDependency(Asset $asset): static
    {
        $this->ictAssetDependency->removeElement($asset);
        return $this;
    }

    /** @return Collection<int, Incident> */
    public function getIctIncidentHistory(): Collection
    {
        return $this->ictIncidentHistory;
    }

    public function addIctIncidentHistory(Incident $incident): static
    {
        if (!$this->ictIncidentHistory->contains($incident)) {
            $this->ictIncidentHistory->add($incident);
        }
        return $this;
    }

    public function removeIctIncidentHistory(Incident $incident): static
    {
        $this->ictIncidentHistory->removeElement($incident);
        return $this;
    }

    public function getDataResilienceRequirement(): ?string
    {
        return $this->dataResilienceRequirement;
    }

    public function setDataResilienceRequirement(?string $dataResilienceRequirement): static
    {
        $this->dataResilienceRequirement = $dataResilienceRequirement;
        return $this;
    }

    public function isTlptScope(): bool
    {
        return $this->tlptScope;
    }

    public function setTlptScope(bool $tlptScope): static
    {
        $this->tlptScope = $tlptScope;
        return $this;
    }

    public function isRegulatoryReportingRequired(): bool
    {
        return $this->regulatoryReportingRequired;
    }

    public function setRegulatoryReportingRequired(bool $regulatoryReportingRequired): static
    {
        $this->regulatoryReportingRequired = $regulatoryReportingRequired;
        return $this;
    }

    public function isBoardEscalationRequired(): bool
    {
        return $this->boardEscalationRequired;
    }

    public function setBoardEscalationRequired(bool $boardEscalationRequired): static
    {
        $this->boardEscalationRequired = $boardEscalationRequired;
        return $this;
    }

    public function isLessonsLearnedDocumented(): bool
    {
        return $this->lessonsLearnedDocumented;
    }

    public function setLessonsLearnedDocumented(bool $lessonsLearnedDocumented): static
    {
        $this->lessonsLearnedDocumented = $lessonsLearnedDocumented;
        return $this;
    }

    // ── FAIR getters / setters ─────────────────────────────────────────────

    public function getLossEventFrequencyMin(): ?string
    {
        return $this->lossEventFrequencyMin;
    }

    public function setLossEventFrequencyMin(?string $lossEventFrequencyMin): static
    {
        $this->lossEventFrequencyMin = $lossEventFrequencyMin;
        return $this;
    }

    public function getLossEventFrequencyMax(): ?string
    {
        return $this->lossEventFrequencyMax;
    }

    public function setLossEventFrequencyMax(?string $lossEventFrequencyMax): static
    {
        $this->lossEventFrequencyMax = $lossEventFrequencyMax;
        return $this;
    }

    public function getLossEventFrequencyMode(): ?string
    {
        return $this->lossEventFrequencyMode;
    }

    public function setLossEventFrequencyMode(?string $lossEventFrequencyMode): static
    {
        $this->lossEventFrequencyMode = $lossEventFrequencyMode;
        return $this;
    }

    public function getThreatEventFrequencyMin(): ?string
    {
        return $this->threatEventFrequencyMin;
    }

    public function setThreatEventFrequencyMin(?string $threatEventFrequencyMin): static
    {
        $this->threatEventFrequencyMin = $threatEventFrequencyMin;
        return $this;
    }

    public function getThreatEventFrequencyMax(): ?string
    {
        return $this->threatEventFrequencyMax;
    }

    public function setThreatEventFrequencyMax(?string $threatEventFrequencyMax): static
    {
        $this->threatEventFrequencyMax = $threatEventFrequencyMax;
        return $this;
    }

    public function getThreatEventFrequencyMode(): ?string
    {
        return $this->threatEventFrequencyMode;
    }

    public function setThreatEventFrequencyMode(?string $threatEventFrequencyMode): static
    {
        $this->threatEventFrequencyMode = $threatEventFrequencyMode;
        return $this;
    }

    public function getVulnerabilityProbability(): ?string
    {
        return $this->vulnerabilityProbability;
    }

    public function setVulnerabilityProbability(?string $vulnerabilityProbability): static
    {
        $this->vulnerabilityProbability = $vulnerabilityProbability;
        return $this;
    }

    public function getPrimaryLossMagnitudeMin(): ?string
    {
        return $this->primaryLossMagnitudeMin;
    }

    public function setPrimaryLossMagnitudeMin(?string $primaryLossMagnitudeMin): static
    {
        $this->primaryLossMagnitudeMin = $primaryLossMagnitudeMin;
        return $this;
    }

    public function getPrimaryLossMagnitudeMax(): ?string
    {
        return $this->primaryLossMagnitudeMax;
    }

    public function setPrimaryLossMagnitudeMax(?string $primaryLossMagnitudeMax): static
    {
        $this->primaryLossMagnitudeMax = $primaryLossMagnitudeMax;
        return $this;
    }

    public function getPrimaryLossMagnitudeMode(): ?string
    {
        return $this->primaryLossMagnitudeMode;
    }

    public function setPrimaryLossMagnitudeMode(?string $primaryLossMagnitudeMode): static
    {
        $this->primaryLossMagnitudeMode = $primaryLossMagnitudeMode;
        return $this;
    }

    public function getSecondaryLossMagnitudeMin(): ?string
    {
        return $this->secondaryLossMagnitudeMin;
    }

    public function setSecondaryLossMagnitudeMin(?string $secondaryLossMagnitudeMin): static
    {
        $this->secondaryLossMagnitudeMin = $secondaryLossMagnitudeMin;
        return $this;
    }

    public function getSecondaryLossMagnitudeMax(): ?string
    {
        return $this->secondaryLossMagnitudeMax;
    }

    public function setSecondaryLossMagnitudeMax(?string $secondaryLossMagnitudeMax): static
    {
        $this->secondaryLossMagnitudeMax = $secondaryLossMagnitudeMax;
        return $this;
    }

    public function getSecondaryLossMagnitudeMode(): ?string
    {
        return $this->secondaryLossMagnitudeMode;
    }

    public function setSecondaryLossMagnitudeMode(?string $secondaryLossMagnitudeMode): static
    {
        $this->secondaryLossMagnitudeMode = $secondaryLossMagnitudeMode;
        return $this;
    }

    /**
     * V3 W2-FV-5 — audit-trail: when was this risk last reassessed in
     * direct response to an incident (and which incident triggered it)?
     * Set by IncidentController::reassessLinkedRisk() so an auditor can
     * verify "Risk-Update was incident-driven" without grep-ing the audit
     * log.
     */
    #[ORM\Column(name: 'last_incident_reassessment_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastIncidentReassessmentAt = null;

    #[ORM\ManyToOne(targetEntity: Incident::class)]
    #[ORM\JoinColumn(name: 'last_incident_reassessment_incident_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Incident $lastIncidentReassessmentIncident = null;

    public function getLastIncidentReassessmentAt(): ?DateTimeImmutable
    {
        return $this->lastIncidentReassessmentAt;
    }

    public function setLastIncidentReassessmentAt(?DateTimeImmutable $at): static
    {
        $this->lastIncidentReassessmentAt = $at;
        return $this;
    }

    public function getLastIncidentReassessmentIncident(): ?Incident
    {
        return $this->lastIncidentReassessmentIncident;
    }

    public function setLastIncidentReassessmentIncident(?Incident $incident): static
    {
        $this->lastIncidentReassessmentIncident = $incident;
        return $this;
    }
}
