<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;
use App\Repository\IncidentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: IncidentRepository::class)]
#[ORM\Index(columns: ['incident_number'], name: 'idx_incident_number')]
#[ORM\Index(columns: ['severity'], name: 'idx_incident_severity')]
#[ORM\Index(columns: ['status'], name: 'idx_incident_status')]
#[ORM\Index(columns: ['category'], name: 'idx_incident_category')]
#[ORM\Index(columns: ['detected_at'], name: 'idx_incident_detected_at')]
#[ORM\Index(columns: ['data_breach_occurred'], name: 'idx_incident_data_breach')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_incident_tenant')]
#[ApiResource(
    operations: [
        new Get(
            security: "is_granted('ROLE_USER')",
            description: 'Retrieve a specific security incident by ID'
        ),
        new GetCollection(
            security: "is_granted('ROLE_USER')",
            description: 'Retrieve the collection of security incidents with filtering by severity, status, and category'
        ),
        new Post(
            security: "is_granted('ROLE_USER')",
            description: 'Create a new security incident report'
        ),
        new Put(
            security: "is_granted('ROLE_USER')",
            description: 'Update an existing security incident'
        ),
        new Delete(
            security: "is_granted('ROLE_ADMIN')",
            description: 'Delete a security incident (Admin only)'
        ),
    ],
    normalizationContext: ['groups' => ['incident:read']],
    denormalizationContext: ['groups' => ['incident:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['title' => 'partial', 'incidentNumber' => 'exact', 'severity' => 'exact', 'status' => 'exact', 'category' => 'exact'])]
#[ApiFilter(BooleanFilter::class, properties: ['dataBreachOccurred', 'notificationRequired'])]
#[ApiFilter(OrderFilter::class, properties: ['detectedAt', 'severity', 'status'])]
#[ApiFilter(DateFilter::class, properties: ['detectedAt', 'resolvedAt', 'closedAt'])]
class Incident
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['incident:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['incident:read'])]
    private ?Tenant $tenant = null;

    // New relationship for threat tracking
    #[ORM\ManyToOne(targetEntity: ThreatIntelligence::class, inversedBy: 'resultingIncidents')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?ThreatIntelligence $originatingThreat = null;

    #[ORM\Column(length: 50)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\NotBlank(message: 'Incident number is required')]
    #[Assert\Length(max: 50, maxMessage: 'Incident number cannot exceed {{ limit }} characters')]
    private ?string $incidentNumber = null;

    #[ORM\Column(length: 255)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\NotBlank(message: 'Incident title is required')]
    #[Assert\Length(max: 255, maxMessage: 'Title cannot exceed {{ limit }} characters')]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\NotBlank(message: 'Incident description is required')]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\NotBlank(message: 'Incident category is required')]
    #[Assert\Length(max: 100, maxMessage: 'Category cannot exceed {{ limit }} characters')]
    private ?string $category = null;

    #[ORM\Column(length: 50)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\NotBlank(message: 'Severity is required')]
    #[Assert\Choice(
        choices: ['low', 'medium', 'high', 'critical'],
        message: 'Severity must be one of: {{ choices }}'
    )]
    private ?string $severity = null;

    #[ORM\Column(length: 50)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\NotBlank(message: 'Status is required')]
    #[Assert\Choice(
        choices: ['open', 'investigating', 'resolved', 'closed'],
        message: 'Status must be one of: {{ choices }}'
    )]
    private ?string $status = 'open';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\NotNull(message: 'Detection date is required')]
    private ?\DateTimeInterface $detectedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?\DateTimeInterface $occurredAt = null;

    #[ORM\Column(length: 100)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\NotBlank(message: 'Reporter name is required')]
    #[Assert\Length(max: 100, maxMessage: 'Reporter name cannot exceed {{ limit }} characters')]
    private ?string $reportedBy = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\Length(max: 100, maxMessage: 'Assignee name cannot exceed {{ limit }} characters')]
    private ?string $assignedTo = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $affectedSystems = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $immediateActions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $rootCause = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $correctiveActions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $preventiveActions = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $lessonsLearned = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?\DateTimeInterface $resolvedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?\DateTimeInterface $closedAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\NotNull(message: 'Data breach flag is required')]
    private ?bool $dataBreachOccurred = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\NotNull(message: 'Notification required flag is required')]
    private ?bool $notificationRequired = false;

    /**
     * NIS2 Article 23 - Early Warning Notification (24h deadline)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?\DateTimeImmutable $earlyWarningReportedAt = null;

    /**
     * NIS2 Article 23 - Detailed Notification (72h deadline)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?\DateTimeImmutable $detailedNotificationReportedAt = null;

    /**
     * NIS2 Article 23 - Final Report (1 month deadline)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?\DateTimeImmutable $finalReportSubmittedAt = null;

    /**
     * NIS2 incident category according to Article 23
     * - operational: Operational disruption
     * - security: Security breach
     * - privacy: Privacy/data protection
     * - availability: Service availability
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    #[Assert\Choice(
        choices: ['operational', 'security', 'privacy', 'availability'],
        message: 'NIS2 category must be one of: {{ choices }}'
    )]
    private ?string $nis2Category = null;

    /**
     * Does this incident have cross-border impact? (NIS2 relevant)
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['incident:read', 'incident:write'])]
    private bool $crossBorderImpact = false;

    /**
     * Number of affected users/customers (NIS2 reporting)
     */
    #[ORM\Column(nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?int $affectedUsersCount = null;

    /**
     * Estimated financial impact in EUR (NIS2 reporting)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $estimatedFinancialImpact = null;

    /**
     * National authority notified (e.g., BSI, ENISA)
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $nationalAuthorityNotified = null;

    /**
     * Reference number from authority
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['incident:read', 'incident:write'])]
    private ?string $authorityReferenceNumber = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['incident:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['incident:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class, inversedBy: 'incidents')]
    #[Groups(['incident:read'])]
    #[MaxDepth(1)]
    private Collection $relatedControls;

    /**
     * @var Collection<int, Asset>
     */
    #[ORM\ManyToMany(targetEntity: Asset::class, inversedBy: 'incidents')]
    #[ORM\JoinTable(name: 'incident_asset')]
    #[Groups(['incident:read'])]
    #[MaxDepth(1)]
    private Collection $affectedAssets;

    /**
     * @var Collection<int, Risk>
     */
    #[ORM\ManyToMany(targetEntity: Risk::class, inversedBy: 'incidents')]
    #[ORM\JoinTable(name: 'incident_risk')]
    #[Groups(['incident:read'])]
    #[MaxDepth(1)]
    private Collection $realizedRisks;

    /**
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'incident_failed_controls')]
    #[Groups(['incident:read', 'incident:write'])]
    #[MaxDepth(1)]
    private Collection $failedControls;

    /**
     * @var Collection<int, BusinessProcess>
     * CRITICAL-05: Incident ↔ BCM Integration
     * Links incidents to affected business processes for impact analysis
     */
    #[ORM\ManyToMany(targetEntity: BusinessProcess::class, inversedBy: 'incidents')]
    #[ORM\JoinTable(name: 'incident_business_process')]
    #[Groups(['incident:read', 'incident:write'])]
    #[MaxDepth(1)]
    private Collection $affectedBusinessProcesses;

    public function __construct()
    {
        $this->relatedControls = new ArrayCollection();
        $this->affectedAssets = new ArrayCollection();
        $this->realizedRisks = new ArrayCollection();
        $this->failedControls = new ArrayCollection();
        $this->affectedBusinessProcesses = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->detectedAt = new \DateTimeImmutable();
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

    public function getIncidentNumber(): ?string
    {
        return $this->incidentNumber;
    }

    public function setIncidentNumber(string $incidentNumber): static
    {
        $this->incidentNumber = $incidentNumber;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
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

    public function getSeverity(): ?string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): static
    {
        $this->severity = $severity;
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

    public function getDetectedAt(): ?\DateTimeInterface
    {
        return $this->detectedAt;
    }

    public function setDetectedAt(\DateTimeInterface $detectedAt): static
    {
        $this->detectedAt = $detectedAt;
        return $this;
    }

    public function getOccurredAt(): ?\DateTimeInterface
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(?\DateTimeInterface $occurredAt): static
    {
        $this->occurredAt = $occurredAt;
        return $this;
    }

    public function getReportedBy(): ?string
    {
        return $this->reportedBy;
    }

    public function setReportedBy(string $reportedBy): static
    {
        $this->reportedBy = $reportedBy;
        return $this;
    }

    public function getAssignedTo(): ?string
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?string $assignedTo): static
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

    public function getAffectedSystems(): ?string
    {
        return $this->affectedSystems;
    }

    public function setAffectedSystems(?string $affectedSystems): static
    {
        $this->affectedSystems = $affectedSystems;
        return $this;
    }

    public function getImmediateActions(): ?string
    {
        return $this->immediateActions;
    }

    public function setImmediateActions(?string $immediateActions): static
    {
        $this->immediateActions = $immediateActions;
        return $this;
    }

    public function getRootCause(): ?string
    {
        return $this->rootCause;
    }

    public function setRootCause(?string $rootCause): static
    {
        $this->rootCause = $rootCause;
        return $this;
    }

    public function getCorrectiveActions(): ?string
    {
        return $this->correctiveActions;
    }

    public function setCorrectiveActions(?string $correctiveActions): static
    {
        $this->correctiveActions = $correctiveActions;
        return $this;
    }

    public function getPreventiveActions(): ?string
    {
        return $this->preventiveActions;
    }

    public function setPreventiveActions(?string $preventiveActions): static
    {
        $this->preventiveActions = $preventiveActions;
        return $this;
    }

    public function getLessonsLearned(): ?string
    {
        return $this->lessonsLearned;
    }

    public function setLessonsLearned(?string $lessonsLearned): static
    {
        $this->lessonsLearned = $lessonsLearned;
        return $this;
    }

    public function getResolvedAt(): ?\DateTimeInterface
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeInterface $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;
        return $this;
    }

    public function getClosedAt(): ?\DateTimeInterface
    {
        return $this->closedAt;
    }

    public function setClosedAt(?\DateTimeInterface $closedAt): static
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    public function isDataBreachOccurred(): ?bool
    {
        return $this->dataBreachOccurred;
    }

    public function setDataBreachOccurred(bool $dataBreachOccurred): static
    {
        $this->dataBreachOccurred = $dataBreachOccurred;
        return $this;
    }

    public function isNotificationRequired(): ?bool
    {
        return $this->notificationRequired;
    }

    public function setNotificationRequired(bool $notificationRequired): static
    {
        $this->notificationRequired = $notificationRequired;
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
     * @return Collection<int, Control>
     */
    public function getRelatedControls(): Collection
    {
        return $this->relatedControls;
    }

    public function addRelatedControl(Control $relatedControl): static
    {
        if (!$this->relatedControls->contains($relatedControl)) {
            $this->relatedControls->add($relatedControl);
        }
        return $this;
    }

    public function removeRelatedControl(Control $relatedControl): static
    {
        $this->relatedControls->removeElement($relatedControl);
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
     * @return Collection<int, Risk>
     */
    public function getRealizedRisks(): Collection
    {
        return $this->realizedRisks;
    }

    public function addRealizedRisk(Risk $risk): static
    {
        if (!$this->realizedRisks->contains($risk)) {
            $this->realizedRisks->add($risk);
        }
        return $this;
    }

    public function removeRealizedRisk(Risk $risk): static
    {
        $this->realizedRisks->removeElement($risk);
        return $this;
    }

    public function getOriginatingThreat(): ?ThreatIntelligence
    {
        return $this->originatingThreat;
    }

    public function setOriginatingThreat(?ThreatIntelligence $originatingThreat): static
    {
        $this->originatingThreat = $originatingThreat;
        return $this;
    }

    /**
     * @return Collection<int, Control>
     */
    public function getFailedControls(): Collection
    {
        return $this->failedControls;
    }

    public function addFailedControl(Control $control): static
    {
        if (!$this->failedControls->contains($control)) {
            $this->failedControls->add($control);
        }
        return $this;
    }

    public function removeFailedControl(Control $control): static
    {
        $this->failedControls->removeElement($control);
        return $this;
    }

    /**
     * @return Collection<int, BusinessProcess>
     */
    public function getAffectedBusinessProcesses(): Collection
    {
        return $this->affectedBusinessProcesses;
    }

    public function addAffectedBusinessProcess(BusinessProcess $process): static
    {
        if (!$this->affectedBusinessProcesses->contains($process)) {
            $this->affectedBusinessProcesses->add($process);
        }
        return $this;
    }

    public function removeAffectedBusinessProcess(BusinessProcess $process): static
    {
        $this->affectedBusinessProcesses->removeElement($process);
        return $this;
    }

    /**
     * Check if any critical/high-risk assets were affected
     * Data Reuse: Uses Asset risk scoring
     */
    #[Groups(['incident:read'])]
    public function hasCriticalAssetsAffected(): bool
    {
        return $this->affectedAssets->exists(fn($k, $asset) => $asset->isHighRisk());
    }

    /**
     * Get count of realized risks
     * Data Reuse: Links incidents to pre-defined risks
     */
    #[Groups(['incident:read'])]
    public function getRealizedRiskCount(): int
    {
        return $this->realizedRisks->count();
    }

    /**
     * Get total impact value from affected assets
     * Data Reuse: Aggregates CIA values from affected assets
     */
    #[Groups(['incident:read'])]
    public function getTotalAssetImpact(): int
    {
        $total = 0;
        foreach ($this->affectedAssets as $asset) {
            $total += $asset->getTotalValue();
        }
        return $total;
    }

    /**
     * Check if this incident validated a previously identified risk
     * Data Reuse: Validates risk assessment accuracy
     */
    #[Groups(['incident:read'])]
    public function isRiskValidated(): bool
    {
        return !$this->realizedRisks->isEmpty();
    }

    // NIS2 Article 23 - Getters and Setters

    public function getEarlyWarningReportedAt(): ?\DateTimeImmutable
    {
        return $this->earlyWarningReportedAt;
    }

    public function setEarlyWarningReportedAt(?\DateTimeImmutable $earlyWarningReportedAt): static
    {
        $this->earlyWarningReportedAt = $earlyWarningReportedAt;
        return $this;
    }

    public function getDetailedNotificationReportedAt(): ?\DateTimeImmutable
    {
        return $this->detailedNotificationReportedAt;
    }

    public function setDetailedNotificationReportedAt(?\DateTimeImmutable $detailedNotificationReportedAt): static
    {
        $this->detailedNotificationReportedAt = $detailedNotificationReportedAt;
        return $this;
    }

    public function getFinalReportSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->finalReportSubmittedAt;
    }

    public function setFinalReportSubmittedAt(?\DateTimeImmutable $finalReportSubmittedAt): static
    {
        $this->finalReportSubmittedAt = $finalReportSubmittedAt;
        return $this;
    }

    public function getNis2Category(): ?string
    {
        return $this->nis2Category;
    }

    public function setNis2Category(?string $nis2Category): static
    {
        $this->nis2Category = $nis2Category;
        return $this;
    }

    public function isCrossBorderImpact(): bool
    {
        return $this->crossBorderImpact;
    }

    public function setCrossBorderImpact(bool $crossBorderImpact): static
    {
        $this->crossBorderImpact = $crossBorderImpact;
        return $this;
    }

    public function getAffectedUsersCount(): ?int
    {
        return $this->affectedUsersCount;
    }

    public function setAffectedUsersCount(?int $affectedUsersCount): static
    {
        $this->affectedUsersCount = $affectedUsersCount;
        return $this;
    }

    public function getEstimatedFinancialImpact(): ?string
    {
        return $this->estimatedFinancialImpact;
    }

    public function setEstimatedFinancialImpact(?string $estimatedFinancialImpact): static
    {
        $this->estimatedFinancialImpact = $estimatedFinancialImpact;
        return $this;
    }

    public function getNationalAuthorityNotified(): ?string
    {
        return $this->nationalAuthorityNotified;
    }

    public function setNationalAuthorityNotified(?string $nationalAuthorityNotified): static
    {
        $this->nationalAuthorityNotified = $nationalAuthorityNotified;
        return $this;
    }

    public function getAuthorityReferenceNumber(): ?string
    {
        return $this->authorityReferenceNumber;
    }

    public function setAuthorityReferenceNumber(?string $authorityReferenceNumber): static
    {
        $this->authorityReferenceNumber = $authorityReferenceNumber;
        return $this;
    }

    // NIS2 Article 23 - Timeline Helper Methods

    /**
     * Get early warning deadline (24 hours from detection)
     */
    #[Groups(['incident:read'])]
    public function getEarlyWarningDeadline(): ?\DateTimeImmutable
    {
        if ($this->detectedAt === null) {
            return null;
        }
        return $this->detectedAt instanceof \DateTimeImmutable
            ? $this->detectedAt->modify('+24 hours')
            : \DateTimeImmutable::createFromMutable($this->detectedAt)->modify('+24 hours');
    }

    /**
     * Get detailed notification deadline (72 hours from detection)
     */
    #[Groups(['incident:read'])]
    public function getDetailedNotificationDeadline(): ?\DateTimeImmutable
    {
        if ($this->detectedAt === null) {
            return null;
        }
        return $this->detectedAt instanceof \DateTimeImmutable
            ? $this->detectedAt->modify('+72 hours')
            : \DateTimeImmutable::createFromMutable($this->detectedAt)->modify('+72 hours');
    }

    /**
     * Get final report deadline (1 month from detection)
     */
    #[Groups(['incident:read'])]
    public function getFinalReportDeadline(): ?\DateTimeImmutable
    {
        if ($this->detectedAt === null) {
            return null;
        }
        return $this->detectedAt instanceof \DateTimeImmutable
            ? $this->detectedAt->modify('+1 month')
            : \DateTimeImmutable::createFromMutable($this->detectedAt)->modify('+1 month');
    }

    /**
     * Check if early warning is overdue
     */
    #[Groups(['incident:read'])]
    public function isEarlyWarningOverdue(): bool
    {
        if ($this->earlyWarningReportedAt !== null) {
            return false; // Already reported
        }
        $deadline = $this->getEarlyWarningDeadline();
        if ($deadline === null) {
            return false; // No deadline if detectedAt is not set
        }
        return new \DateTimeImmutable() > $deadline;
    }

    /**
     * Check if detailed notification is overdue
     */
    #[Groups(['incident:read'])]
    public function isDetailedNotificationOverdue(): bool
    {
        if ($this->detailedNotificationReportedAt !== null) {
            return false; // Already reported
        }
        $deadline = $this->getDetailedNotificationDeadline();
        if ($deadline === null) {
            return false; // No deadline if detectedAt is not set
        }
        return new \DateTimeImmutable() > $deadline;
    }

    /**
     * Check if final report is overdue
     */
    #[Groups(['incident:read'])]
    public function isFinalReportOverdue(): bool
    {
        if ($this->finalReportSubmittedAt !== null) {
            return false; // Already submitted
        }
        $deadline = $this->getFinalReportDeadline();
        if ($deadline === null) {
            return false; // No deadline if detectedAt is not set
        }
        return new \DateTimeImmutable() > $deadline;
    }

    /**
     * Get hours remaining until early warning deadline
     */
    #[Groups(['incident:read'])]
    public function getHoursUntilEarlyWarningDeadline(): int
    {
        if ($this->earlyWarningReportedAt !== null) {
            return 0;
        }
        $deadline = $this->getEarlyWarningDeadline();
        if ($deadline === null) {
            return 0; // No deadline if detectedAt is not set
        }
        $now = new \DateTimeImmutable();
        $diff = $now->diff($deadline);
        return $diff->invert ? 0 : ($diff->days * 24 + $diff->h);
    }

    /**
     * Get hours remaining until detailed notification deadline
     */
    #[Groups(['incident:read'])]
    public function getHoursUntilDetailedNotificationDeadline(): int
    {
        if ($this->detailedNotificationReportedAt !== null) {
            return 0;
        }
        $deadline = $this->getDetailedNotificationDeadline();
        if ($deadline === null) {
            return 0; // No deadline if detectedAt is not set
        }
        $now = new \DateTimeImmutable();
        $diff = $now->diff($deadline);
        return $diff->invert ? 0 : ($diff->days * 24 + $diff->h);
    }

    /**
     * Get days remaining until final report deadline
     */
    #[Groups(['incident:read'])]
    public function getDaysUntilFinalReportDeadline(): int
    {
        if ($this->finalReportSubmittedAt !== null) {
            return 0;
        }
        $deadline = $this->getFinalReportDeadline();
        if ($deadline === null) {
            return 0; // No deadline if detectedAt is not set
        }
        $now = new \DateTimeImmutable();
        $diff = $now->diff($deadline);
        return $diff->invert ? 0 : $diff->days;
    }

    /**
     * Check if this incident requires NIS2 reporting
     * Based on severity and category
     *
     * Note: No Groups annotation - API Platform only allows Groups on get/is/has/set methods
     */
    public function requiresNis2Reporting(): bool
    {
        // NIS2 reporting requires a detected date
        if ($this->detectedAt === null) {
            return false;
        }

        // High and critical incidents, or incidents with cross-border impact
        return in_array($this->severity, ['high', 'critical']) ||
               $this->crossBorderImpact ||
               $this->nis2Category !== null;
    }

    /**
     * Get NIS2 compliance status
     */
    #[Groups(['incident:read'])]
    public function getNis2ComplianceStatus(): string
    {
        if (!$this->requiresNis2Reporting()) {
            return 'not_applicable';
        }

        if ($this->finalReportSubmittedAt !== null) {
            return 'compliant';
        }

        if ($this->isEarlyWarningOverdue() || $this->isDetailedNotificationOverdue() || $this->isFinalReportOverdue()) {
            return 'overdue';
        }

        if ($this->earlyWarningReportedAt !== null && $this->detailedNotificationReportedAt !== null) {
            return 'awaiting_final';
        }

        if ($this->earlyWarningReportedAt !== null) {
            return 'awaiting_detailed';
        }

        return 'awaiting_early_warning';
    }

    // CRITICAL-05: BCM Integration - Helper Methods

    /**
     * Check if any critical business processes are affected
     * Data Reuse: BCM criticality assessment
     */
    #[Groups(['incident:read'])]
    public function hasCriticalProcessesAffected(): bool
    {
        return $this->affectedBusinessProcesses->exists(
            fn($k, $process) => $process->getCriticality() === 'critical'
        );
    }

    /**
     * Get count of affected business processes
     * Data Reuse: Quick BCM impact overview
     */
    #[Groups(['incident:read'])]
    public function getAffectedProcessCount(): int
    {
        return $this->affectedBusinessProcesses->count();
    }

    /**
     * Get the most critical affected process (lowest RTO)
     * Data Reuse: BCM RTO values for recovery prioritization
     */
    public function getMostCriticalAffectedProcess(): ?BusinessProcess
    {
        if ($this->affectedBusinessProcesses->isEmpty()) {
            return null;
        }

        $processes = $this->affectedBusinessProcesses->toArray();
        usort($processes, fn($a, $b) => $a->getRto() <=> $b->getRto());
        return $processes[0];
    }

    /**
     * Calculate estimated total financial impact based on affected processes
     * Data Reuse: BCM financial impact data
     *
     * @param int $estimatedDowntimeHours Estimated downtime in hours
     * @return float Total estimated financial impact in EUR
     */
    public function calculateEstimatedFinancialImpact(int $estimatedDowntimeHours = 24): float
    {
        $totalImpact = 0.0;

        foreach ($this->affectedBusinessProcesses as $process) {
            $impactPerHour = (float) ($process->getFinancialImpactPerHour() ?? 0);
            $totalImpact += $impactPerHour * $estimatedDowntimeHours;
        }

        return $totalImpact;
    }

    /**
     * Get suggested recovery priority based on BCM data
     * Data Reuse: RTO, MTPD, and criticality from BIA
     *
     * @return string Priority level: immediate, high, medium, low
     */
    #[Groups(['incident:read'])]
    public function getSuggestedRecoveryPriority(): string
    {
        if ($this->affectedBusinessProcesses->isEmpty()) {
            return 'medium';
        }

        $mostCritical = $this->getMostCriticalAffectedProcess();
        if ($mostCritical === null) {
            return 'medium';
        }

        $rto = $mostCritical->getRto();

        if ($rto <= 1 || $mostCritical->getCriticality() === 'critical') {
            return 'immediate'; // RTO ≤ 1 hour or critical process
        } elseif ($rto <= 4) {
            return 'high'; // RTO ≤ 4 hours
        } elseif ($rto <= 24) {
            return 'medium'; // RTO ≤ 24 hours
        } else {
            return 'low'; // RTO > 24 hours
        }
    }

    /**
     * Check if incident violates RTO thresholds
     * Data Reuse: BCM RTO monitoring
     *
     * @param int $actualDowntimeHours Actual or estimated downtime
     * @return bool True if RTO is exceeded for any affected process
     */
    public function isRTOViolated(int $actualDowntimeHours): bool
    {
        foreach ($this->affectedBusinessProcesses as $process) {
            if ($actualDowntimeHours > $process->getRto()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get aggregated business impact score from affected processes
     * Data Reuse: BCM impact scoring (reputational, regulatory, operational)
     *
     * @return int Average impact score (1-5)
     */
    #[Groups(['incident:read'])]
    public function getAggregatedBusinessImpactScore(): int
    {
        if ($this->affectedBusinessProcesses->isEmpty()) {
            return 0;
        }

        $totalScore = 0;
        $count = 0;

        foreach ($this->affectedBusinessProcesses as $process) {
            $totalScore += $process->getBusinessImpactScore();
            $count++;
        }

        return $count > 0 ? (int) round($totalScore / $count) : 0;
    }
}
