<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WizardSessionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wizard Session Entity
 *
 * Phase 7E: Stores compliance wizard progress for users.
 * Allows users to pause and resume wizard assessments.
 * Tracks assessment results and recommendations.
 */
#[ORM\Entity(repositoryClass: WizardSessionRepository::class)]
#[ORM\Table(name: 'wizard_session')]
#[ORM\Index(name: 'idx_wizard_session_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_wizard_session_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_wizard_session_wizard', columns: ['wizard_type'])]
#[ORM\Index(name: 'idx_wizard_session_status', columns: ['status'])]
#[ORM\HasLifecycleCallbacks]
class WizardSession
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ABANDONED = 'abandoned';

    public const WIZARD_ISO27001 = 'iso27001';
    public const WIZARD_NIS2 = 'nis2';
    public const WIZARD_DORA = 'dora';
    public const WIZARD_TISAX = 'tisax';
    public const WIZARD_GDPR = 'gdpr';
    public const WIZARD_BSI = 'bsi_grundschutz';
    // V3 W2-M1: Per-wizard slots so History/Trend doesn't collapse 16 of 22
    // wizards into the ISO27001 bucket. Each wizard has its own snapshot lane.
    public const WIZARD_BSI_C5 = 'bsi_c5';
    public const WIZARD_BSI_C5_2026 = 'bsi_c5_2026';
    public const WIZARD_BSI_GRUNDSCHUTZ_STANDARD = 'bsi_grundschutz_standard';
    public const WIZARD_BSI_GRUNDSCHUTZ_KERN = 'bsi_grundschutz_kern';
    public const WIZARD_ISO22301 = 'iso22301';
    public const WIZARD_ISO27017 = 'iso27017';
    public const WIZARD_ISO27018 = 'iso27018';
    public const WIZARD_ISO27701 = 'iso27701';
    public const WIZARD_ISO42001 = 'iso42001';
    public const WIZARD_NIST_CSF = 'nist_csf';
    public const WIZARD_KRITIS = 'kritis';
    public const WIZARD_PCI_DSS = 'pci_dss';
    public const WIZARD_SOC2 = 'soc2';
    public const WIZARD_EU_AI_ACT = 'eu_ai_act';
    public const WIZARD_EUCS = 'eucs';
    public const WIZARD_CRA = 'cra';

    /**
     * V3 W2-M1: Full list of supported wizard slots. Used by Choice-Validator
     * and ComplianceWizardController::mapWizardCodeToSessionType().
     */
    public const ALL_WIZARDS = [
        self::WIZARD_ISO27001,
        self::WIZARD_NIS2,
        self::WIZARD_DORA,
        self::WIZARD_TISAX,
        self::WIZARD_GDPR,
        self::WIZARD_BSI,
        self::WIZARD_BSI_C5,
        self::WIZARD_BSI_C5_2026,
        self::WIZARD_BSI_GRUNDSCHUTZ_STANDARD,
        self::WIZARD_BSI_GRUNDSCHUTZ_KERN,
        self::WIZARD_ISO22301,
        self::WIZARD_ISO27017,
        self::WIZARD_ISO27018,
        self::WIZARD_ISO27701,
        self::WIZARD_ISO42001,
        self::WIZARD_NIST_CSF,
        self::WIZARD_KRITIS,
        self::WIZARD_PCI_DSS,
        self::WIZARD_SOC2,
        self::WIZARD_EU_AI_ACT,
        self::WIZARD_EUCS,
        self::WIZARD_CRA,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::ALL_WIZARDS)]
    private ?string $wizardType = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_ABANDONED,
    ])]
    private string $status = self::STATUS_IN_PROGRESS;

    #[ORM\Column]
    private int $currentStep = 1;

    #[ORM\Column]
    private int $totalSteps = 1;

    #[ORM\Column(type: Types::JSON)]
    private array $completedCategories = [];

    #[ORM\Column(type: Types::JSON)]
    private array $assessmentResults = [];

    #[ORM\Column(type: Types::JSON)]
    private array $recommendations = [];

    #[ORM\Column(type: Types::JSON)]
    private array $criticalGaps = [];

    #[ORM\Column]
    private int $overallScore = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastActivityAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->startedAt = new DateTimeImmutable();
        $this->lastActivityAt = new DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function prePersist(): void
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function preUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
        $this->lastActivityAt = new DateTimeImmutable();
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getWizardType(): ?string
    {
        return $this->wizardType;
    }

    public function setWizardType(string $wizardType): static
    {
        $this->wizardType = $wizardType;
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

    public function getCurrentStep(): int
    {
        return $this->currentStep;
    }

    public function setCurrentStep(int $currentStep): static
    {
        $this->currentStep = $currentStep;
        return $this;
    }

    public function getTotalSteps(): int
    {
        return $this->totalSteps;
    }

    public function setTotalSteps(int $totalSteps): static
    {
        $this->totalSteps = $totalSteps;
        return $this;
    }

    public function getCompletedCategories(): array
    {
        return $this->completedCategories;
    }

    public function setCompletedCategories(array $completedCategories): static
    {
        $this->completedCategories = $completedCategories;
        return $this;
    }

    public function addCompletedCategory(string $category): static
    {
        if (!in_array($category, $this->completedCategories, true)) {
            $this->completedCategories[] = $category;
        }
        return $this;
    }

    public function getAssessmentResults(): array
    {
        return $this->assessmentResults;
    }

    public function setAssessmentResults(array $assessmentResults): static
    {
        $this->assessmentResults = $assessmentResults;
        return $this;
    }

    public function getRecommendations(): array
    {
        return $this->recommendations;
    }

    public function setRecommendations(array $recommendations): static
    {
        $this->recommendations = $recommendations;
        return $this;
    }

    public function getCriticalGaps(): array
    {
        return $this->criticalGaps;
    }

    public function setCriticalGaps(array $criticalGaps): static
    {
        $this->criticalGaps = $criticalGaps;
        return $this;
    }

    public function getOverallScore(): int
    {
        return $this->overallScore;
    }

    public function setOverallScore(int $overallScore): static
    {
        $this->overallScore = $overallScore;
        return $this;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;
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

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function setLastActivityAt(?DateTimeImmutable $lastActivityAt): static
    {
        $this->lastActivityAt = $lastActivityAt;
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

    /**
     * Mark the session as completed
     */
    public function complete(): static
    {
        $this->status = self::STATUS_COMPLETED;
        $this->completedAt = new DateTimeImmutable();
        return $this;
    }

    /**
     * Mark the session as abandoned
     */
    public function abandon(): static
    {
        $this->status = self::STATUS_ABANDONED;
        return $this;
    }

    /**
     * Calculate progress percentage
     */
    public function getProgressPercentage(): int
    {
        if ($this->totalSteps === 0) {
            return 0;
        }
        return (int) round(($this->currentStep / $this->totalSteps) * 100);
    }

    /**
     * Check if session is in progress
     */
    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * Check if session is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Get human-readable wizard name
     */
    public function getWizardName(): string
    {
        return match ($this->wizardType) {
            self::WIZARD_ISO27001 => 'ISO 27001:2022',
            self::WIZARD_NIS2 => 'NIS2 Directive',
            self::WIZARD_DORA => 'DORA',
            self::WIZARD_TISAX => 'TISAX',
            self::WIZARD_GDPR => 'GDPR/DSGVO',
            self::WIZARD_BSI => 'BSI IT-Grundschutz',
            self::WIZARD_BSI_C5 => 'BSI C5:2020',
            self::WIZARD_BSI_C5_2026 => 'BSI C5:2026',
            self::WIZARD_BSI_GRUNDSCHUTZ_STANDARD => 'BSI Grundschutz Standard',
            self::WIZARD_BSI_GRUNDSCHUTZ_KERN => 'BSI Grundschutz Kern',
            self::WIZARD_ISO22301 => 'ISO 22301',
            self::WIZARD_ISO27017 => 'ISO 27017',
            self::WIZARD_ISO27018 => 'ISO 27018',
            self::WIZARD_ISO27701 => 'ISO 27701',
            self::WIZARD_ISO42001 => 'ISO 42001 (AI)',
            self::WIZARD_NIST_CSF => 'NIST CSF 2.0',
            self::WIZARD_KRITIS => 'KRITIS',
            self::WIZARD_PCI_DSS => 'PCI DSS',
            self::WIZARD_SOC2 => 'SOC 2',
            self::WIZARD_EU_AI_ACT => 'EU AI Act',
            self::WIZARD_EUCS => 'EUCS',
            self::WIZARD_CRA => 'EU CRA',
            default => $this->wizardType ?? 'Unknown',
        };
    }

    /**
     * Get status badge class for UI
     */
    public function getStatusBadgeClass(): string
    {
        return match ($this->status) {
            self::STATUS_IN_PROGRESS => 'bg-primary',
            self::STATUS_COMPLETED => 'bg-success',
            self::STATUS_ABANDONED => 'bg-secondary',
            default => 'bg-secondary',
        };
    }
}
