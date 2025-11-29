<?php

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
use App\Repository\RiskRepository;
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
            security: "is_granted('ROLE_USER')"
        ),
        new GetCollection(
            description: 'Retrieve the collection of risk assessments with filtering by status and date',
            security: "is_granted('ROLE_USER')"
        ),
        new Post(
            description: 'Create a new risk assessment with probability and impact analysis',
            security: "is_granted('ROLE_USER')"
        ),
        new Put(
            description: 'Update an existing risk assessment',
            security: "is_granted('ROLE_USER')"
        ),
        new Delete(
            description: 'Delete a risk assessment (Admin only)',
            security: "is_granted('ROLE_ADMIN')"
        ),
    ],
    normalizationContext: ['groups' => ['risk:read']],
    denormalizationContext: ['groups' => ['risk:write']]
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
    #[Assert\Length(max: 255, maxMessage: 'Risk title cannot exceed {{ limit }} characters')]
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
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    private ?Asset $asset = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    private ?Person $person = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    private ?Location $location = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    #[Groups(['risk:read', 'risk:write'])]
    #[MaxDepth(1)]
    private ?Supplier $supplier = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\NotNull(message: 'Probability is required')]
    #[Assert\Range(notInRangeMessage: 'Probability must be between {{ min }} and {{ max }}', min: 1, max: 5)]
    private ?int $probability = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\NotNull(message: 'Impact is required')]
    #[Assert\Range(notInRangeMessage: 'Impact must be between {{ min }} and {{ max }}', min: 1, max: 5)]
    private ?int $impact = null;

    // Set default values for required fields
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\Range(notInRangeMessage: 'Residual probability must be between {{ min }} and {{ max }}', min: 1, max: 5)]
    private ?int $residualProbability = 1;

    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\Range(notInRangeMessage: 'Residual impact must be between {{ min }} and {{ max }}', min: 1, max: 5)]
    private ?int $residualImpact = 1;

    #[ORM\Column(length: 50)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\NotBlank(message: 'Treatment strategy is required')]
    #[Assert\Choice(
        choices: ['accept', 'mitigate', 'transfer', 'avoid'],
        message: 'Treatment strategy must be one of: {{ choices }}'
    )]
    private ?string $treatmentStrategy = 'mitigate';

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
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    #[Groups(['risk:read', 'risk:write'])]
    #[Assert\NotBlank(message: 'Status is required')]
    #[Assert\Choice(
        choices: ['identified', 'assessed', 'treated', 'monitored', 'closed', 'accepted'],
        message: 'Status must be one of: {{ choices }}'
    )]
    private ?string $status = 'identified';

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
     * Formal approval for risk acceptance
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['risk:read'])]
    private bool $formallyAccepted = false;

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

    public function getTreatmentStrategy(): ?string
    {
        return $this->treatmentStrategy;
    }

    public function setTreatmentStrategy(string $treatmentStrategy): static
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
        return $this->user;
    }

    public function setRiskOwner(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    /**
     * Get risk owner's full name for display
     * Data Reuse: Quick access to owner name without loading full User entity
     */
    #[Groups(['risk:read'])]
    public function getRiskOwnerName(): ?string
    {
        return $this->user?->getFullName();
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
        return $this->probability * $this->impact;
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
        return $this->residualProbability * $this->residualImpact;
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
            if (in_array($incident->getSeverity(), ['critical', 'high'])) {
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
        return $this->treatmentStrategy === 'accept' && !$this->formallyAccepted;
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
        if ($this->treatmentStrategy !== 'accept') {
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
        if ($this->treatmentStrategy !== 'accept') {
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
}
