<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use DateTime;
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
use App\Repository\SupplierRepository;
use App\State\TenantAwareStateProcessor;
use App\Entity\Document;
use App\Entity\Tenant;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Supplier/Vendor Entity for ISO 27001 A.15 Supplier Relationships
 *
 * Manages third-party suppliers and their security assessments
 */
#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\Index(name: 'idx_supplier_criticality', columns: ['criticality'])]
#[ORM\Index(name: 'idx_supplier_next_assessment', columns: ['next_assessment_date'])]
#[ORM\Index(name: 'idx_supplier_status', columns: ['status'])]
#[ORM\Index(name: 'idx_supplier_tenant', columns: ['tenant_id'])]
#[ApiResource(
    operations: [
        new Get(
            description: 'Retrieve a specific supplier by ID',
            security: "is_granted('API_VIEW', object)"
        ),
        new GetCollection(
            description: 'Retrieve the collection of suppliers with filtering',
            security: "is_granted('ROLE_USER')"
        ),
        new Post(
            description: 'Create a new supplier',
            securityPostDenormalize: "is_granted('API_CREATE', object)"
        ),
        new Put(
            description: 'Update an existing supplier',
            security: "is_granted('API_EDIT', object)"
        ),
        new Delete(
            description: 'Delete a supplier (Admin only)',
            security: "is_granted('API_DELETE', object)"
        ),
    ],
    normalizationContext: ['groups' => ['supplier:read']],
    denormalizationContext: ['groups' => ['supplier:write']],
    processor: TenantAwareStateProcessor::class
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'status' => 'exact', 'criticality' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'criticality', 'nextAssessmentDate'])]
#[ApiFilter(DateFilter::class, properties: ['nextAssessmentDate', 'lastSecurityAssessment'])]
#[ORM\HasLifecycleCallbacks]
class Supplier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['supplier:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['supplier:read'])]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Supplier name is required')]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $contactPerson = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email(message: 'Invalid email address')]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $phone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $address = null;

    /**
     * Service provided by supplier
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Service description is required')]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $serviceProvided = null;

    /**
     * Criticality code — references SupplierCriticalityLevel.code for this tenant.
     * Phase 8QW-5: Assert\Choice removed; validation is now via tenant-specific
     * SupplierCriticalityLevel lookup (FK-style). Existing canonical codes
     * (critical/high/medium/low) remain valid via seeded default records.
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $criticality = 'medium';

    /**
     * Status: active, inactive, evaluation, terminated
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['active', 'inactive', 'evaluation', 'terminated'])]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $status = 'evaluation';

    /**
     * Security assessment score (0-100)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 0, max: 100)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?int $securityScore = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?DateTimeInterface $lastSecurityAssessment = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?DateTimeInterface $nextAssessmentDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $assessmentFindings = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $nonConformities = null;

    /**
     * Contractual SLAs and security requirements (JSON)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?array $contractualSLAs = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?DateTimeInterface $contractStartDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?DateTimeInterface $contractEndDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $securityRequirements = null;

    /**
     * ISO 27001/ISO 22301 certification
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private bool $hasISO27001 = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private bool $hasISO22301 = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $certifications = null;

    /**
     * DORA Art. 28 — Register of Information scope flag.
     * When true, this supplier is included in the DORA RoI XBRL export
     * as an ICT third-party service provider (Art. 28 ICT-Drittdienstleister).
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private bool $isDoraRelevant = false;

    /**
     * Data processing agreement (GDPR)
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private bool $hasDPA = false;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?DateTimeInterface $dpaSignedDate = null;

    /**
     * @var Collection<int, Asset>
     */
    #[ORM\ManyToMany(targetEntity: Asset::class)]
    #[ORM\JoinTable(name: 'supplier_asset')]
    #[Groups(['supplier:read'])]
    private Collection $supportedAssets;

    /**
     * @var Collection<int, Risk>
     */
    #[ORM\ManyToMany(targetEntity: Risk::class)]
    #[ORM\JoinTable(name: 'supplier_risk')]
    #[Groups(['supplier:read'])]
    private Collection $identifiedRisks;

    /**
     * @var Collection<int, Document>
     */
    #[ORM\ManyToMany(targetEntity: Document::class)]
    #[ORM\JoinTable(name: 'supplier_document')]
    #[Groups(['supplier:read'])]
    private Collection $documents;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['supplier:read'])]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['supplier:read'])]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->supportedAssets = new ArrayCollection();
        $this->identifiedRisks = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new DateTimeImmutable();
        if (!$this->createdAt instanceof DateTimeInterface) {
            $this->createdAt = new DateTimeImmutable();
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

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): static
    {
        $this->contactPerson = $contactPerson;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
        return $this;
    }

    public function getServiceProvided(): ?string
    {
        return $this->serviceProvided;
    }

    public function setServiceProvided(string $serviceProvided): static
    {
        $this->serviceProvided = $serviceProvided;
        return $this;
    }

    public function getCriticality(): ?string
    {
        return $this->criticality;
    }

    public function setCriticality(string $criticality): static
    {
        $this->criticality = $criticality;
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

    /**
     * Operational supplier = currently in productive use. evaluation
     * (still being assessed), inactive, and terminated do NOT count as
     * operational. Domain decision: only `active` suppliers feed KPIs
     * like "active suppliers", "overdue assessments", critical-supplier
     * counts. SupplierRepository's status = :active queries match this.
     */
    public function isOperational(): bool
    {
        return $this->status === 'active';
    }

    public function getSecurityScore(): ?int
    {
        return $this->securityScore;
    }

    public function setSecurityScore(?int $securityScore): static
    {
        $this->securityScore = $securityScore;
        return $this;
    }

    public function getLastSecurityAssessment(): ?DateTimeInterface
    {
        return $this->lastSecurityAssessment;
    }

    public function setLastSecurityAssessment(?DateTimeInterface $lastSecurityAssessment): static
    {
        $this->lastSecurityAssessment = $lastSecurityAssessment;
        return $this;
    }

    public function getNextAssessmentDate(): ?DateTimeInterface
    {
        return $this->nextAssessmentDate;
    }

    public function setNextAssessmentDate(?DateTimeInterface $nextAssessmentDate): static
    {
        $this->nextAssessmentDate = $nextAssessmentDate;
        return $this;
    }

    public function getAssessmentFindings(): ?string
    {
        return $this->assessmentFindings;
    }

    public function setAssessmentFindings(?string $assessmentFindings): static
    {
        $this->assessmentFindings = $assessmentFindings;
        return $this;
    }

    public function getNonConformities(): ?string
    {
        return $this->nonConformities;
    }

    public function setNonConformities(?string $nonConformities): static
    {
        $this->nonConformities = $nonConformities;
        return $this;
    }

    public function getContractualSLAs(): ?array
    {
        return $this->contractualSLAs;
    }

    public function setContractualSLAs(?array $contractualSLAs): static
    {
        $this->contractualSLAs = $contractualSLAs;
        return $this;
    }

    public function getContractStartDate(): ?DateTimeInterface
    {
        return $this->contractStartDate;
    }

    public function setContractStartDate(?DateTimeInterface $contractStartDate): static
    {
        $this->contractStartDate = $contractStartDate;
        return $this;
    }

    public function getContractEndDate(): ?DateTimeInterface
    {
        return $this->contractEndDate;
    }

    public function setContractEndDate(?DateTimeInterface $contractEndDate): static
    {
        $this->contractEndDate = $contractEndDate;
        return $this;
    }

    public function getSecurityRequirements(): ?string
    {
        return $this->securityRequirements;
    }

    public function setSecurityRequirements(?string $securityRequirements): static
    {
        $this->securityRequirements = $securityRequirements;
        return $this;
    }

    public function isHasISO27001(): bool
    {
        return $this->hasISO27001;
    }

    public function setHasISO27001(bool $hasISO27001): static
    {
        $this->hasISO27001 = $hasISO27001;
        return $this;
    }

    public function isHasISO22301(): bool
    {
        return $this->hasISO22301;
    }

    public function setHasISO22301(bool $hasISO22301): static
    {
        $this->hasISO22301 = $hasISO22301;
        return $this;
    }

    public function getCertifications(): ?string
    {
        return $this->certifications;
    }

    public function setCertifications(?string $certifications): static
    {
        $this->certifications = $certifications;
        return $this;
    }

    public function isDoraRelevant(): bool
    {
        return $this->isDoraRelevant;
    }

    public function setIsDoraRelevant(bool $isDoraRelevant): static
    {
        $this->isDoraRelevant = $isDoraRelevant;
        return $this;
    }

    public function isHasDPA(): bool
    {
        return $this->hasDPA;
    }

    public function setHasDPA(bool $hasDPA): static
    {
        $this->hasDPA = $hasDPA;
        return $this;
    }

    public function getDpaSignedDate(): ?DateTimeInterface
    {
        return $this->dpaSignedDate;
    }

    public function setDpaSignedDate(?DateTimeInterface $dpaSignedDate): static
    {
        $this->dpaSignedDate = $dpaSignedDate;
        return $this;
    }

    /**
     * @return Collection<int, Asset>
     */
    public function getSupportedAssets(): Collection
    {
        return $this->supportedAssets;
    }

    public function addSupportedAsset(Asset $asset): static
    {
        if (!$this->supportedAssets->contains($asset)) {
            $this->supportedAssets->add($asset);
        }
        return $this;
    }

    public function removeSupportedAsset(Asset $asset): static
    {
        $this->supportedAssets->removeElement($asset);
        return $this;
    }

    /**
     * @return Collection<int, Risk>
     */
    public function getIdentifiedRisks(): Collection
    {
        return $this->identifiedRisks;
    }

    public function addIdentifiedRisk(Risk $risk): static
    {
        if (!$this->identifiedRisks->contains($risk)) {
            $this->identifiedRisks->add($risk);
        }
        return $this;
    }

    public function removeIdentifiedRisk(Risk $risk): static
    {
        $this->identifiedRisks->removeElement($risk);
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
     * Data Reuse: Calculate supplier risk score based on multiple factors
     */
    public function calculateRiskScore(): int
    {
        $score = 0;

        // Criticality weight (40%)
        $criticalityScore = match($this->criticality) {
            'critical' => 40,
            'high' => 30,
            'medium' => 15,
            'low' => 5,
            default => 0
        };
        $score += $criticalityScore;

        // Security score inverse (30%)
        if ($this->securityScore !== null) {
            $score += (100 - $this->securityScore) * 0.3;
        } else {
            $score += 30; // No assessment = high risk
        }

        // Missing certifications (15%)
        if (!$this->hasISO27001) {
            $score += 10;
        }
        if (!$this->hasISO22301 && $this->criticality === 'critical') {
            $score += 5;
        }

        // Missing DPA (10%)
        if (!$this->hasDPA) {
            $score += 10;
        }

        // Overdue assessment (5%)
        if ($this->isAssessmentOverdue()) {
            $score += 5;
        }

        return min(100, (int)$score);
    }

    /**
     * Check if security assessment is overdue
     */
    public function isAssessmentOverdue(): bool
    {
        if (!$this->nextAssessmentDate instanceof DateTimeInterface) {
            return !$this->lastSecurityAssessment instanceof DateTimeInterface;
        }

        return $this->nextAssessmentDate < new DateTime();
    }

    /**
     * Get assessment status badge
     */
    public function getAssessmentStatus(): string
    {
        if (!$this->lastSecurityAssessment instanceof DateTimeInterface) {
            return 'not_assessed';
        }

        if ($this->isAssessmentOverdue()) {
            return 'overdue';
        }

        $thirtyDaysFromNow = new DateTime('+30 days');
        if ($this->nextAssessmentDate instanceof DateTimeInterface && $this->nextAssessmentDate < $thirtyDaysFromNow) {
            return 'due_soon';
        }

        return 'current';
    }

    /**
     * Data Reuse: Aggregate risk level from identified risks
     */
    public function getAggregatedRiskLevel(): string
    {
        if ($this->identifiedRisks->isEmpty()) {
            return 'unknown';
        }

        $totalResidualRisk = 0;
        $count = 0;

        foreach ($this->identifiedRisks as $identifiedRisk) {
            $totalResidualRisk += $identifiedRisk->getResidualRiskLevel();
            $count++;
        }

        $avgRisk = $count > 0 ? $totalResidualRisk / $count : 0;

        if ($avgRisk >= 16) {
            return 'critical';
        }
        if ($avgRisk >= 9) {
            return 'high';
        }
        if ($avgRisk >= 4) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Check if supplier supports critical assets
     */
    public function supportsCriticalAssets(): bool
    {
        foreach ($this->supportedAssets as $supportedAsset) {
            if ($supportedAsset->isHighRisk()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get compliance status
     */
    public function getComplianceStatus(): array
    {
        return [
            'iso27001' => $this->hasISO27001,
            'iso22301' => $this->hasISO22301,
            'dpa' => $this->hasDPA,
            'security_assessment' => !$this->isAssessmentOverdue(),
            'overall_compliant' => $this->hasISO27001 && $this->hasDPA && !$this->isAssessmentOverdue()
        ];
    }

    // ── BCM Supplier fields ────────────────────────────────────────────────

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $supplierRto = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $supplierRecoveryCapability = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alternativeSupplier = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $bcmAssessmentDate = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $bcmAssessmentResult = null;

    // ── WS-3: DORA ROI + DSGVO Art. 28 fields ────────────────────────────────

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $leiCode = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $ictCriticality = null;  // non_ict|important|critical

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $ictFunctionType = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $substitutability = null;  // easy|medium|hard

    #[ORM\Column]
    private bool $hasSubcontractors = false;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $subcontractorChain = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $processingLocations = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastDoraAuditDate = null;

    #[ORM\Column]
    private bool $hasExitStrategy = false;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $exitStrategyDocument = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $gdprProcessorStatus = null;  // controller|processor|joint_controller|none

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $gdprTransferMechanism = null;

    #[ORM\Column]
    private bool $gdprAvContractSigned = false;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $gdprAvContractDate = null;

    // ── MINOR-6: DORA ITS Register of Information additional fields ──────────

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $naceCode = null;  // EU NACE Rev.2 industry code

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $countryOfHeadOffice = null;  // ISO-3166 alpha-2

    // ── LkSG (Lieferkettensorgfaltspflichtengesetz) ──────────────────────────
    // Mandatory due-diligence tracking for human-rights and environmental
    // risks across own business and direct + indirect suppliers. Pflicht ab
    // 1000 MA seit 2024; Ausweitung auf 250+ MA absehbar.

    /** Aggregierter LkSG-Risiko-Score 0-100 (höher = mehr Risiko). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'LkSG human rights risk score must be between {{ min }} and {{ max }}')]
    private ?int $lksgHumanRightsRiskScore = null;

    /** Umweltbezogener Risiko-Score 0-100. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 0, max: 100, notInRangeMessage: 'LkSG environmental risk score must be between {{ min }} and {{ max }}')]
    private ?int $lksgEnvironmentalRiskScore = null;

    /** Klassifikation der LkSG-Gesamtrisikolage. */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(
        choices: [null, 'low', 'medium', 'high', 'critical'],
        message: 'LkSG risk category must be one of: low, medium, high, critical',
    )]
    private ?string $lksgRiskCategory = null;

    /** Datum der letzten LkSG-Risikoanalyse. */
    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lksgRiskAnalysisDate = null;

    /** Beschwerdekanal-Beschreibung oder URL. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lksgComplaintMechanism = null;

    /** Präventions- und Abhilfemaßnahmen (Freitext, ISO 27001-Belege via Documents-Collection). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lksgPreventionMeasures = null;

    /** Berichtspflichtig nach LkSG (Tenant-Pflicht ≥1000 MA, Lieferant innerhalb der Sorgfaltspflicht). */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $lksgReportingObligation = false;

    public function getLksgHumanRightsRiskScore(): ?int { return $this->lksgHumanRightsRiskScore; }
    public function setLksgHumanRightsRiskScore(?int $v): self { $this->lksgHumanRightsRiskScore = $v; return $this; }

    public function getLksgEnvironmentalRiskScore(): ?int { return $this->lksgEnvironmentalRiskScore; }
    public function setLksgEnvironmentalRiskScore(?int $v): self { $this->lksgEnvironmentalRiskScore = $v; return $this; }

    public function getLksgRiskCategory(): ?string { return $this->lksgRiskCategory; }
    public function setLksgRiskCategory(?string $v): self { $this->lksgRiskCategory = $v; return $this; }

    public function getLksgRiskAnalysisDate(): ?\DateTimeInterface { return $this->lksgRiskAnalysisDate; }
    public function setLksgRiskAnalysisDate(?\DateTimeInterface $v): self { $this->lksgRiskAnalysisDate = $v; return $this; }

    public function getLksgComplaintMechanism(): ?string { return $this->lksgComplaintMechanism; }
    public function setLksgComplaintMechanism(?string $v): self { $this->lksgComplaintMechanism = $v; return $this; }

    public function getLksgPreventionMeasures(): ?string { return $this->lksgPreventionMeasures; }
    public function setLksgPreventionMeasures(?string $v): self { $this->lksgPreventionMeasures = $v; return $this; }

    public function isLksgReportingObligation(): bool { return $this->lksgReportingObligation; }
    public function setLksgReportingObligation(bool $v): self { $this->lksgReportingObligation = $v; return $this; }

    /**
     * Aggregated LkSG severity. Highest of the two component scores when both
     * exist, otherwise the available one. null when neither score is set.
     */
    public function getLksgAggregateRiskScore(): ?int
    {
        $scores = array_filter(
            [$this->lksgHumanRightsRiskScore, $this->lksgEnvironmentalRiskScore],
            static fn(?int $score): bool => $score !== null,
        );

        return $scores === [] ? null : max($scores);
    }

    public function getNaceCode(): ?string { return $this->naceCode; }
    public function setNaceCode(?string $v): self { $this->naceCode = $v; return $this; }

    public function getCountryOfHeadOffice(): ?string { return $this->countryOfHeadOffice; }
    public function setCountryOfHeadOffice(?string $v): self { $this->countryOfHeadOffice = $v; return $this; }

    public function getLeiCode(): ?string { return $this->leiCode; }
    public function setLeiCode(?string $v): self { $this->leiCode = $v; return $this; }

    public function getIctCriticality(): ?string { return $this->ictCriticality; }
    public function setIctCriticality(?string $v): self { $this->ictCriticality = $v; return $this; }

    public function getIctFunctionType(): ?string { return $this->ictFunctionType; }
    public function setIctFunctionType(?string $v): self { $this->ictFunctionType = $v; return $this; }

    public function getSubstitutability(): ?string { return $this->substitutability; }
    public function setSubstitutability(?string $v): self { $this->substitutability = $v; return $this; }

    public function hasSubcontractors(): bool { return $this->hasSubcontractors; }
    public function setHasSubcontractors(bool $v): self { $this->hasSubcontractors = $v; return $this; }

    public function getSubcontractorChain(): ?array { return $this->subcontractorChain; }
    public function setSubcontractorChain(?array $v): self { $this->subcontractorChain = $v; return $this; }

    public function getProcessingLocations(): ?array { return $this->processingLocations; }
    public function setProcessingLocations(?array $v): self { $this->processingLocations = $v; return $this; }

    public function getLastDoraAuditDate(): ?\DateTimeInterface { return $this->lastDoraAuditDate; }
    public function setLastDoraAuditDate(?\DateTimeInterface $v): self { $this->lastDoraAuditDate = $v; return $this; }

    public function hasExitStrategy(): bool { return $this->hasExitStrategy; }
    public function setHasExitStrategy(bool $v): self { $this->hasExitStrategy = $v; return $this; }

    public function getExitStrategyDocument(): ?Document { return $this->exitStrategyDocument; }
    public function setExitStrategyDocument(?Document $d): self { $this->exitStrategyDocument = $d; return $this; }

    public function getGdprProcessorStatus(): ?string { return $this->gdprProcessorStatus; }
    public function setGdprProcessorStatus(?string $v): self { $this->gdprProcessorStatus = $v; return $this; }

    public function getGdprTransferMechanism(): ?string { return $this->gdprTransferMechanism; }
    public function setGdprTransferMechanism(?string $v): self { $this->gdprTransferMechanism = $v; return $this; }

    public function getGdprAvContractSigned(): bool { return $this->gdprAvContractSigned; }
    public function setGdprAvContractSigned(bool $v): self { $this->gdprAvContractSigned = $v; return $this; }

    public function getGdprAvContractDate(): ?\DateTimeInterface { return $this->gdprAvContractDate; }
    public function setGdprAvContractDate(?\DateTimeInterface $v): self { $this->gdprAvContractDate = $v; return $this; }

    // BCM Supplier Getters/Setters

    public function getSupplierRto(): ?int
    {
        return $this->supplierRto;
    }

    public function setSupplierRto(?int $supplierRto): static
    {
        $this->supplierRto = $supplierRto;
        return $this;
    }

    public function getSupplierRecoveryCapability(): ?string
    {
        return $this->supplierRecoveryCapability;
    }

    public function setSupplierRecoveryCapability(?string $supplierRecoveryCapability): static
    {
        $this->supplierRecoveryCapability = $supplierRecoveryCapability;
        return $this;
    }

    public function getAlternativeSupplier(): ?string
    {
        return $this->alternativeSupplier;
    }

    public function setAlternativeSupplier(?string $alternativeSupplier): static
    {
        $this->alternativeSupplier = $alternativeSupplier;
        return $this;
    }

    public function getBcmAssessmentDate(): ?\DateTimeImmutable
    {
        return $this->bcmAssessmentDate;
    }

    public function setBcmAssessmentDate(?\DateTimeImmutable $bcmAssessmentDate): static
    {
        $this->bcmAssessmentDate = $bcmAssessmentDate;
        return $this;
    }

    public function getBcmAssessmentResult(): ?string
    {
        return $this->bcmAssessmentResult;
    }

    public function setBcmAssessmentResult(?string $bcmAssessmentResult): static
    {
        $this->bcmAssessmentResult = $bcmAssessmentResult;
        return $this;
    }

    // ── Sprint 7-B: MaRisk outsourcing fields (gated 'marisk' module) ─────────

    /**
     * Outsourcing classification (MaRisk AT 9.1).
     * Values: substantial | non_substantial
     * Note: separate from DORA's "critical or important" classification.
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $outsourcingClassification = null;

    /**
     * Whether the initial due diligence was completed before outsourcing (MaRisk AT 9.2).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['supplier:read', 'supplier:write'])]
    private bool $outsourcingDueDiligenceCompleted = false;

    /**
     * Date on which due diligence was completed.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?\DateTimeImmutable $outsourcingDueDiligenceDate = null;

    /**
     * Exit strategy for the outsourcing relationship (MaRisk AT 9.6).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $outsourcingExitStrategy = null;

    /**
     * Whether BaFin notification is required (MaRisk AT 9.7 + § 24 Abs. 1 Nr. 12 KWG).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['supplier:read', 'supplier:write'])]
    private bool $bafinNotificationRequired = false;

    /**
     * Date when BaFin was notified.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?\DateTimeImmutable $bafinNotificationDate = null;

    /**
     * Impact on risk-bearing capacity (MaRisk AT 4.1).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['supplier:read', 'supplier:write'])]
    private ?string $riskBearingCapacityImpact = null;

    /**
     * Whether the management board has explicitly accepted this outsourcing risk (MaRisk AT 4.3).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['supplier:read', 'supplier:write'])]
    private bool $boardLevelRiskAcceptance = false;

    /**
     * Whether the compliance function was involved in outsourcing assessment (MaRisk AT 4.4.2).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['supplier:read', 'supplier:write'])]
    private bool $complianceFunctionInvolvement = false;

    /**
     * Whether the internal audit function was involved (MaRisk AT 4.4.3).
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['supplier:read', 'supplier:write'])]
    private bool $internalAuditFunctionInvolvement = false;

    public function getOutsourcingClassification(): ?string
    {
        return $this->outsourcingClassification;
    }

    public function setOutsourcingClassification(?string $outsourcingClassification): static
    {
        $this->outsourcingClassification = $outsourcingClassification;
        return $this;
    }

    public function isOutsourcingDueDiligenceCompleted(): bool
    {
        return $this->outsourcingDueDiligenceCompleted;
    }

    public function setOutsourcingDueDiligenceCompleted(bool $outsourcingDueDiligenceCompleted): static
    {
        $this->outsourcingDueDiligenceCompleted = $outsourcingDueDiligenceCompleted;
        return $this;
    }

    public function getOutsourcingDueDiligenceDate(): ?\DateTimeImmutable
    {
        return $this->outsourcingDueDiligenceDate;
    }

    public function setOutsourcingDueDiligenceDate(?\DateTimeImmutable $outsourcingDueDiligenceDate): static
    {
        $this->outsourcingDueDiligenceDate = $outsourcingDueDiligenceDate;
        return $this;
    }

    public function getOutsourcingExitStrategy(): ?string
    {
        return $this->outsourcingExitStrategy;
    }

    public function setOutsourcingExitStrategy(?string $outsourcingExitStrategy): static
    {
        $this->outsourcingExitStrategy = $outsourcingExitStrategy;
        return $this;
    }

    public function isBafinNotificationRequired(): bool
    {
        return $this->bafinNotificationRequired;
    }

    public function setBafinNotificationRequired(bool $bafinNotificationRequired): static
    {
        $this->bafinNotificationRequired = $bafinNotificationRequired;
        return $this;
    }

    public function getBafinNotificationDate(): ?\DateTimeImmutable
    {
        return $this->bafinNotificationDate;
    }

    public function setBafinNotificationDate(?\DateTimeImmutable $bafinNotificationDate): static
    {
        $this->bafinNotificationDate = $bafinNotificationDate;
        return $this;
    }

    public function getRiskBearingCapacityImpact(): ?string
    {
        return $this->riskBearingCapacityImpact;
    }

    public function setRiskBearingCapacityImpact(?string $riskBearingCapacityImpact): static
    {
        $this->riskBearingCapacityImpact = $riskBearingCapacityImpact;
        return $this;
    }

    public function isBoardLevelRiskAcceptance(): bool
    {
        return $this->boardLevelRiskAcceptance;
    }

    public function setBoardLevelRiskAcceptance(bool $boardLevelRiskAcceptance): static
    {
        $this->boardLevelRiskAcceptance = $boardLevelRiskAcceptance;
        return $this;
    }

    public function isComplianceFunctionInvolvement(): bool
    {
        return $this->complianceFunctionInvolvement;
    }

    public function setComplianceFunctionInvolvement(bool $complianceFunctionInvolvement): static
    {
        $this->complianceFunctionInvolvement = $complianceFunctionInvolvement;
        return $this;
    }

    public function isInternalAuditFunctionInvolvement(): bool
    {
        return $this->internalAuditFunctionInvolvement;
    }

    public function setInternalAuditFunctionInvolvement(bool $internalAuditFunctionInvolvement): static
    {
        $this->internalAuditFunctionInvolvement = $internalAuditFunctionInvolvement;
        return $this;
    }

}
