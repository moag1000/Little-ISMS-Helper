<?php

namespace App\Entity;

use App\Repository\DataBreachRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * CRITICAL-08: Data Breach Management
 *
 * Datenschutzverletzung gemäß Art. 33/34 DSGVO.
 * Linked to Incident for data reuse and workflow integration.
 *
 * Compliance Mapping:
 * - GDPR Art. 33: Notification to supervisory authority (72 hours)
 * - GDPR Art. 34: Communication to data subjects
 * - GDPR Art. 5(2): Accountability principle
 * - NIS2 Art. 23: Incident notification (24h/72h)
 * - ISO 27701: Privacy incident management
 */
#[ORM\Entity(repositoryClass: DataBreachRepository::class)]
#[ORM\Table(name: 'data_breach')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_data_breach_tenant')]
#[ORM\Index(columns: ['status'], name: 'idx_data_breach_status')]
#[ORM\Index(columns: ['severity'], name: 'idx_data_breach_severity')]
#[ORM\Index(columns: ['supervisory_authority_notified_at'], name: 'idx_data_breach_authority_notified')]
#[ORM\Index(columns: ['created_at'], name: 'idx_data_breach_created')]
class DataBreach
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Multi-Tenancy: Tenant that owns this data breach
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    private ?Tenant $tenant = null;

    /**
     * Related incident (security event that caused the breach)
     * OneToOne relationship for data reuse
     */
    #[ORM\OneToOne(targetEntity: Incident::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Incident $incident = null;

    /**
     * Related processing activity (VVT Art. 30)
     * Which processing activity was affected?
     */
    #[ORM\ManyToOne(targetEntity: ProcessingActivity::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?ProcessingActivity $processingActivity = null;

    // ============================================================================
    // Basic Information
    // ============================================================================

    /**
     * Unique reference number (e.g., "BREACH-2024-001")
     */
    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private ?string $referenceNumber = null;

    /**
     * Internal title/summary
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    /**
     * Status: draft, under_assessment, authority_notified, subjects_notified, closed
     */
    #[ORM\Column(length: 30, options: ['default' => 'draft'])]
    #[Assert\Choice(choices: ['draft', 'under_assessment', 'authority_notified', 'subjects_notified', 'closed'])]
    private string $status = 'draft';

    /**
     * Severity of the breach: low, medium, high, critical
     */
    #[ORM\Column(length: 20)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['low', 'medium', 'high', 'critical'])]
    private ?string $severity = null;

    // ============================================================================
    // Art. 33(3) - Content of Notification to Supervisory Authority
    // ============================================================================

    /**
     * Number of affected data subjects (Art. 33(3)(a))
     */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $affectedDataSubjects = null;

    /**
     * Categories of affected data (Art. 33(3)(a))
     * JSON array: ["identification", "financial", "health", etc.]
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank]
    private array $dataCategories = [];

    /**
     * Categories of affected data subjects (Art. 33(3)(a))
     * JSON array: ["customers", "employees", etc.]
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\NotBlank]
    private array $dataSubjectCategories = [];

    /**
     * Nature of the personal data breach (Art. 33(3)(a))
     * Description of what happened
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $breachNature = null;

    /**
     * Likely consequences of the breach (Art. 33(3)(b))
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $likelyConsequences = null;

    /**
     * Measures taken or proposed to address the breach (Art. 33(3)(c))
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private ?string $measuresTaken = null;

    /**
     * Measures taken or proposed to mitigate adverse effects (Art. 33(3)(d))
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $mitigationMeasures = null;

    /**
     * Name and contact details of DPO or contact point (Art. 33(3)(b))
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $dataProtectionOfficer = null;

    // ============================================================================
    // Art. 33 - Notification to Supervisory Authority
    // ============================================================================

    /**
     * Is notification to supervisory authority required? (Art. 33(1))
     * Required unless breach unlikely to result in risk to rights/freedoms
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $requiresAuthorityNotification = true;

    /**
     * Reason if authority notification NOT required
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $noNotificationReason = null;

    /**
     * Date/time supervisory authority was notified (must be within 72h!)
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $supervisoryAuthorityNotifiedAt = null;

    /**
     * Name of supervisory authority notified
     */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $supervisoryAuthorityName = null;

    /**
     * Reference number from supervisory authority
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $supervisoryAuthorityReference = null;

    /**
     * Reason for delay if notified after 72h (Art. 33(1))
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notificationDelayReason = null;

    /**
     * Method of notification (email, portal, letter, etc.)
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $notificationMethod = null;

    /**
     * Notification documents/evidence (JSON: file paths, emails, etc.)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $notificationDocuments = null;

    // ============================================================================
    // Art. 34 - Communication to Data Subjects
    // ============================================================================

    /**
     * Is notification to data subjects required? (Art. 34(1))
     * Required if high risk to rights/freedoms
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $requiresSubjectNotification = false;

    /**
     * Reason if subject notification NOT required (Art. 34(3))
     * - Technical/organizational measures applied (encryption)
     * - Measures taken to ensure no longer high risk
     * - Disproportionate effort (public communication)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $noSubjectNotificationReason = null;

    /**
     * Date/time data subjects were notified
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dataSubjectsNotifiedAt = null;

    /**
     * Method of subject notification (email, letter, website, public notice)
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $subjectNotificationMethod = null;

    /**
     * Number of data subjects successfully notified
     */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?int $subjectsNotified = null;

    /**
     * Subject notification documents/evidence
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $subjectNotificationDocuments = null;

    // ============================================================================
    // Risk Assessment
    // ============================================================================

    /**
     * Risk to rights and freedoms: low, medium, high
     */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: ['low', 'medium', 'high'])]
    private ?string $riskLevel = null;

    /**
     * Assessment of risk to rights and freedoms
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $riskAssessment = null;

    /**
     * Special categories of data affected? (Art. 9 - sensitive data)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $specialCategoriesAffected = false;

    /**
     * Criminal data affected? (Art. 10)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $criminalDataAffected = false;

    // ============================================================================
    // Investigation & Root Cause
    // ============================================================================

    /**
     * Root cause analysis
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rootCause = null;

    /**
     * Person/team responsible for assessment
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $assessor = null;

    /**
     * Lessons learned
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lessonsLearned = null;

    /**
     * Follow-up actions (JSON array of action items)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $followUpActions = null;

    // ============================================================================
    // Metadata
    // ============================================================================

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    public function __construct()
    {
        $this->dataCategories = [];
        $this->dataSubjectCategories = [];
        $this->notificationDocuments = [];
        $this->subjectNotificationDocuments = [];
        $this->followUpActions = [];
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    // ============================================================================
    // Lifecycle Callbacks
    // ============================================================================

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // ============================================================================
    // Helper Methods
    // ============================================================================

    /**
     * Check if 72-hour deadline for authority notification is approaching/exceeded
     * Returns number of hours remaining (negative if overdue)
     */
    public function getHoursUntilAuthorityDeadline(): ?int
    {
        if (!$this->incident || !$this->incident->getDetectedAt()) {
            return null;
        }

        if ($this->supervisoryAuthorityNotifiedAt) {
            return null; // Already notified
        }

        $detectedAt = $this->incident->getDetectedAt();
        $deadline = (clone $detectedAt)->modify('+72 hours');
        $now = new \DateTime();

        $diff = $now->diff($deadline);
        $hours = ($diff->days * 24) + $diff->h;

        return $diff->invert ? -$hours : $hours;
    }

    /**
     * Check if authority notification deadline is exceeded
     */
    public function isAuthorityNotificationOverdue(): bool
    {
        $hours = $this->getHoursUntilAuthorityDeadline();
        return $hours !== null && $hours < 0;
    }

    /**
     * Get deadline for authority notification (72h from detection)
     */
    public function getAuthorityNotificationDeadline(): ?\DateTimeInterface
    {
        if (!$this->incident || !$this->incident->getDetectedAt()) {
            return null;
        }

        $deadline = clone $this->incident->getDetectedAt();
        $deadline->modify('+72 hours');

        return $deadline;
    }

    /**
     * Check if breach is complete (all mandatory fields filled)
     */
    public function isComplete(): bool
    {
        $mandatory = [
            !empty($this->title),
            !empty($this->severity),
            !empty($this->dataCategories),
            !empty($this->dataSubjectCategories),
            !empty($this->breachNature),
            !empty($this->likelyConsequences),
            !empty($this->measuresTaken),
        ];

        // If authority notification required, check those fields
        if ($this->requiresAuthorityNotification) {
            $mandatory[] = $this->supervisoryAuthorityNotifiedAt !== null;
        }

        // If subject notification required, check those fields
        if ($this->requiresSubjectNotification) {
            $mandatory[] = $this->dataSubjectsNotifiedAt !== null;
        }

        return !in_array(false, $mandatory, true);
    }

    /**
     * Calculate completeness percentage
     */
    public function getCompletenessPercentage(): int
    {
        $fields = [
            'title' => !empty($this->title),
            'severity' => !empty($this->severity),
            'dataCategories' => !empty($this->dataCategories),
            'dataSubjectCategories' => !empty($this->dataSubjectCategories),
            'breachNature' => !empty($this->breachNature),
            'likelyConsequences' => !empty($this->likelyConsequences),
            'measuresTaken' => !empty($this->measuresTaken),
            'riskAssessment' => !empty($this->riskAssessment),
            'rootCause' => !empty($this->rootCause),
        ];

        // Conditional fields
        if ($this->requiresAuthorityNotification) {
            $fields['supervisoryAuthorityNotifiedAt'] = $this->supervisoryAuthorityNotifiedAt !== null;
        }

        if ($this->requiresSubjectNotification) {
            $fields['dataSubjectsNotifiedAt'] = $this->dataSubjectsNotifiedAt !== null;
        }

        $filledCount = count(array_filter($fields));
        return (int) (($filledCount / count($fields)) * 100);
    }

    /**
     * Get display name for breadcrumbs/titles
     */
    public function getDisplayName(): string
    {
        return $this->referenceNumber . ' - ' . ($this->title ?? 'Untitled Breach');
    }

    // ============================================================================
    // Getters and Setters (auto-generated by IDE or doctrine:make:entity)
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

    public function getIncident(): ?Incident
    {
        return $this->incident;
    }

    public function setIncident(?Incident $incident): static
    {
        $this->incident = $incident;
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

    public function getReferenceNumber(): ?string
    {
        return $this->referenceNumber;
    }

    public function setReferenceNumber(string $referenceNumber): static
    {
        $this->referenceNumber = $referenceNumber;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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

    public function getAffectedDataSubjects(): ?int
    {
        return $this->affectedDataSubjects;
    }

    public function setAffectedDataSubjects(?int $affectedDataSubjects): static
    {
        $this->affectedDataSubjects = $affectedDataSubjects;
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

    public function getBreachNature(): ?string
    {
        return $this->breachNature;
    }

    public function setBreachNature(string $breachNature): static
    {
        $this->breachNature = $breachNature;
        return $this;
    }

    public function getLikelyConsequences(): ?string
    {
        return $this->likelyConsequences;
    }

    public function setLikelyConsequences(string $likelyConsequences): static
    {
        $this->likelyConsequences = $likelyConsequences;
        return $this;
    }

    public function getMeasuresTaken(): ?string
    {
        return $this->measuresTaken;
    }

    public function setMeasuresTaken(string $measuresTaken): static
    {
        $this->measuresTaken = $measuresTaken;
        return $this;
    }

    public function getMitigationMeasures(): ?string
    {
        return $this->mitigationMeasures;
    }

    public function setMitigationMeasures(?string $mitigationMeasures): static
    {
        $this->mitigationMeasures = $mitigationMeasures;
        return $this;
    }

    public function getDataProtectionOfficer(): ?User
    {
        return $this->dataProtectionOfficer;
    }

    public function setDataProtectionOfficer(?User $dataProtectionOfficer): static
    {
        $this->dataProtectionOfficer = $dataProtectionOfficer;
        return $this;
    }

    public function getRequiresAuthorityNotification(): bool
    {
        return $this->requiresAuthorityNotification;
    }

    public function setRequiresAuthorityNotification(bool $requiresAuthorityNotification): static
    {
        $this->requiresAuthorityNotification = $requiresAuthorityNotification;
        return $this;
    }

    public function getNoNotificationReason(): ?string
    {
        return $this->noNotificationReason;
    }

    public function setNoNotificationReason(?string $noNotificationReason): static
    {
        $this->noNotificationReason = $noNotificationReason;
        return $this;
    }

    public function getSupervisoryAuthorityNotifiedAt(): ?\DateTimeInterface
    {
        return $this->supervisoryAuthorityNotifiedAt;
    }

    public function setSupervisoryAuthorityNotifiedAt(?\DateTimeInterface $supervisoryAuthorityNotifiedAt): static
    {
        $this->supervisoryAuthorityNotifiedAt = $supervisoryAuthorityNotifiedAt;
        return $this;
    }

    public function getSupervisoryAuthorityName(): ?string
    {
        return $this->supervisoryAuthorityName;
    }

    public function setSupervisoryAuthorityName(?string $supervisoryAuthorityName): static
    {
        $this->supervisoryAuthorityName = $supervisoryAuthorityName;
        return $this;
    }

    public function getSupervisoryAuthorityReference(): ?string
    {
        return $this->supervisoryAuthorityReference;
    }

    public function setSupervisoryAuthorityReference(?string $supervisoryAuthorityReference): static
    {
        $this->supervisoryAuthorityReference = $supervisoryAuthorityReference;
        return $this;
    }

    public function getNotificationDelayReason(): ?string
    {
        return $this->notificationDelayReason;
    }

    public function setNotificationDelayReason(?string $notificationDelayReason): static
    {
        $this->notificationDelayReason = $notificationDelayReason;
        return $this;
    }

    public function getNotificationMethod(): ?string
    {
        return $this->notificationMethod;
    }

    public function setNotificationMethod(?string $notificationMethod): static
    {
        $this->notificationMethod = $notificationMethod;
        return $this;
    }

    public function getNotificationDocuments(): ?array
    {
        return $this->notificationDocuments;
    }

    public function setNotificationDocuments(?array $notificationDocuments): static
    {
        $this->notificationDocuments = $notificationDocuments;
        return $this;
    }

    public function getRequiresSubjectNotification(): bool
    {
        return $this->requiresSubjectNotification;
    }

    public function setRequiresSubjectNotification(bool $requiresSubjectNotification): static
    {
        $this->requiresSubjectNotification = $requiresSubjectNotification;
        return $this;
    }

    public function getNoSubjectNotificationReason(): ?string
    {
        return $this->noSubjectNotificationReason;
    }

    public function setNoSubjectNotificationReason(?string $noSubjectNotificationReason): static
    {
        $this->noSubjectNotificationReason = $noSubjectNotificationReason;
        return $this;
    }

    public function getDataSubjectsNotifiedAt(): ?\DateTimeInterface
    {
        return $this->dataSubjectsNotifiedAt;
    }

    public function setDataSubjectsNotifiedAt(?\DateTimeInterface $dataSubjectsNotifiedAt): static
    {
        $this->dataSubjectsNotifiedAt = $dataSubjectsNotifiedAt;
        return $this;
    }

    public function getSubjectNotificationMethod(): ?string
    {
        return $this->subjectNotificationMethod;
    }

    public function setSubjectNotificationMethod(?string $subjectNotificationMethod): static
    {
        $this->subjectNotificationMethod = $subjectNotificationMethod;
        return $this;
    }

    public function getSubjectsNotified(): ?int
    {
        return $this->subjectsNotified;
    }

    public function setSubjectsNotified(?int $subjectsNotified): static
    {
        $this->subjectsNotified = $subjectsNotified;
        return $this;
    }

    public function getSubjectNotificationDocuments(): ?array
    {
        return $this->subjectNotificationDocuments;
    }

    public function setSubjectNotificationDocuments(?array $subjectNotificationDocuments): static
    {
        $this->subjectNotificationDocuments = $subjectNotificationDocuments;
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

    public function getRiskAssessment(): ?string
    {
        return $this->riskAssessment;
    }

    public function setRiskAssessment(?string $riskAssessment): static
    {
        $this->riskAssessment = $riskAssessment;
        return $this;
    }

    public function getSpecialCategoriesAffected(): bool
    {
        return $this->specialCategoriesAffected;
    }

    public function setSpecialCategoriesAffected(bool $specialCategoriesAffected): static
    {
        $this->specialCategoriesAffected = $specialCategoriesAffected;
        return $this;
    }

    public function getCriminalDataAffected(): bool
    {
        return $this->criminalDataAffected;
    }

    public function setCriminalDataAffected(bool $criminalDataAffected): static
    {
        $this->criminalDataAffected = $criminalDataAffected;
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

    public function getAssessor(): ?User
    {
        return $this->assessor;
    }

    public function setAssessor(?User $assessor): static
    {
        $this->assessor = $assessor;
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

    public function getFollowUpActions(): ?array
    {
        return $this->followUpActions;
    }

    public function setFollowUpActions(?array $followUpActions): static
    {
        $this->followUpActions = $followUpActions;
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

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;
        return $this;
    }
}
