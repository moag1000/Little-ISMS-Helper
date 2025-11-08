<?php

namespace App\Entity;

use App\Repository\RiskAppetiteRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Risk Appetite Entity for ISO 27005 and ISO 31000 Compliance
 *
 * Defines the organization's willingness to accept risk in pursuit of its objectives.
 * Risk appetite statements guide risk treatment decisions and ensure consistency
 * across the organization.
 *
 * ISO 27005: Risk acceptance criteria and risk appetite should be defined
 * ISO 31000: Risk appetite should be aligned with organizational objectives
 */
#[ORM\Entity(repositoryClass: RiskAppetiteRepository::class)]
#[ORM\Table(name: 'risk_appetites')]
#[ORM\Index(columns: ['is_active'], name: 'idx_risk_appetite_active')]
#[ORM\Index(columns: ['valid_from', 'valid_to'], name: 'idx_risk_appetite_validity')]
class RiskAppetite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    /**
     * Name/Title of the risk appetite statement
     */
    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /**
     * Detailed description of the risk appetite
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Overall appetite level
     * - conservative: Low risk tolerance, strict controls
     * - moderate: Balanced approach to risk and opportunity
     * - aggressive: Higher risk tolerance for strategic objectives
     */
    #[ORM\Column(length: 30)]
    private ?string $appetiteLevel = 'moderate';

    /**
     * Maximum acceptable inherent risk level (1-25 scale)
     * Risks above this level MUST be treated or escalated for approval
     */
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $maxAcceptableRiskLevel = 12;

    /**
     * Category-specific risk limits
     * Format: {
     *   "operational": {"max_level": 15, "description": "..."},
     *   "financial": {"max_level": 20, "description": "..."},
     *   "compliance": {"max_level": 8, "description": "..."}
     * }
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $categoryLimits = [];

    /**
     * Threshold for low risk (below this value)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $lowRiskThreshold = 5;

    /**
     * Threshold for medium risk (below this value, above low)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $mediumRiskThreshold = 11;

    /**
     * Threshold for high risk (below this value, above medium)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $highRiskThreshold = 19;

    /**
     * Threshold for critical risk (at or above this value)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $criticalRiskThreshold = 20;

    /**
     * Valid from date
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $validFrom = null;

    /**
     * Valid to date (end of validity period)
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    /**
     * Is this risk appetite currently active?
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    /**
     * Approved by (User who approved this risk appetite)
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    /**
     * Approval date
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $approvedAt = null;

    /**
     * Next review date for this risk appetite statement
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewDate = null;

    /**
     * Notes and additional information
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Risks using this appetite definition
     *
     * @var Collection<int, Risk>
     */
    #[ORM\OneToMany(targetEntity: Risk::class, mappedBy: 'riskAppetite')]
    private Collection $risks;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->risks = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->validFrom = new \DateTimeImmutable();
        $this->categoryLimits = [];
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

    public function getAppetiteLevel(): ?string
    {
        return $this->appetiteLevel;
    }

    public function setAppetiteLevel(string $appetiteLevel): static
    {
        $this->appetiteLevel = $appetiteLevel;
        return $this;
    }

    public function getMaxAcceptableRiskLevel(): ?int
    {
        return $this->maxAcceptableRiskLevel;
    }

    public function setMaxAcceptableRiskLevel(int $maxAcceptableRiskLevel): static
    {
        $this->maxAcceptableRiskLevel = $maxAcceptableRiskLevel;
        return $this;
    }

    public function getCategoryLimits(): ?array
    {
        return $this->categoryLimits;
    }

    public function setCategoryLimits(?array $categoryLimits): static
    {
        $this->categoryLimits = $categoryLimits;
        return $this;
    }

    public function getCategoryLimit(string $category): ?int
    {
        return $this->categoryLimits[$category]['max_level'] ?? $this->maxAcceptableRiskLevel;
    }

    public function setCategoryLimit(string $category, int $maxLevel, ?string $description = null): static
    {
        $this->categoryLimits[$category] = [
            'max_level' => $maxLevel,
            'description' => $description,
        ];
        return $this;
    }

    public function getLowRiskThreshold(): int
    {
        return $this->lowRiskThreshold;
    }

    public function setLowRiskThreshold(int $lowRiskThreshold): static
    {
        $this->lowRiskThreshold = $lowRiskThreshold;
        return $this;
    }

    public function getMediumRiskThreshold(): int
    {
        return $this->mediumRiskThreshold;
    }

    public function setMediumRiskThreshold(int $mediumRiskThreshold): static
    {
        $this->mediumRiskThreshold = $mediumRiskThreshold;
        return $this;
    }

    public function getHighRiskThreshold(): int
    {
        return $this->highRiskThreshold;
    }

    public function setHighRiskThreshold(int $highRiskThreshold): static
    {
        $this->highRiskThreshold = $highRiskThreshold;
        return $this;
    }

    public function getCriticalRiskThreshold(): int
    {
        return $this->criticalRiskThreshold;
    }

    public function setCriticalRiskThreshold(int $criticalRiskThreshold): static
    {
        $this->criticalRiskThreshold = $criticalRiskThreshold;
        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(\DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;
        return $this;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeImmutable $validTo): static
    {
        $this->validTo = $validTo;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $approvedBy): static
    {
        $this->approvedBy = $approvedBy;
        return $this;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getReviewDate(): ?\DateTimeImmutable
    {
        return $this->reviewDate;
    }

    public function setReviewDate(?\DateTimeImmutable $reviewDate): static
    {
        $this->reviewDate = $reviewDate;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    /**
     * @return Collection<int, Risk>
     */
    public function getRisks(): Collection
    {
        return $this->risks;
    }

    public function addRisk(Risk $risk): static
    {
        if (!$this->risks->contains($risk)) {
            $this->risks->add($risk);
            $risk->setRiskAppetite($this);
        }
        return $this;
    }

    public function removeRisk(Risk $risk): static
    {
        if ($this->risks->removeElement($risk)) {
            if ($risk->getRiskAppetite() === $this) {
                $risk->setRiskAppetite(null);
            }
        }
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Check if this risk appetite is currently valid
     */
    public function isCurrentlyValid(): bool
    {
        if (!$this->isActive) {
            return false;
        }

        $now = new \DateTimeImmutable();

        if ($this->validFrom > $now) {
            return false;
        }

        if ($this->validTo !== null && $this->validTo < $now) {
            return false;
        }

        return true;
    }

    /**
     * Check if a risk level is acceptable according to this appetite
     */
    public function isRiskLevelAcceptable(int $riskLevel, ?string $category = null): bool
    {
        if ($category !== null) {
            $limit = $this->getCategoryLimit($category);
            return $riskLevel <= $limit;
        }

        return $riskLevel <= $this->maxAcceptableRiskLevel;
    }

    /**
     * Get severity classification for a risk level
     */
    public function classifyRiskLevel(int $riskLevel): string
    {
        return match(true) {
            $riskLevel >= $this->criticalRiskThreshold => 'critical',
            $riskLevel >= $this->highRiskThreshold => 'high',
            $riskLevel >= $this->mediumRiskThreshold => 'medium',
            $riskLevel >= $this->lowRiskThreshold => 'low',
            default => 'negligible',
        };
    }

    /**
     * Check if review is overdue
     */
    public function isReviewOverdue(): bool
    {
        if ($this->reviewDate === null) {
            return false;
        }

        return $this->reviewDate < new \DateTimeImmutable();
    }

    /**
     * Get count of risks using this appetite
     */
    public function getRiskCount(): int
    {
        return $this->risks->count();
    }

    /**
     * Get display name for appetite level
     */
    public function getAppetiteLevelDisplayName(): string
    {
        return match($this->appetiteLevel) {
            'conservative' => 'Konservativ (Risikoavers)',
            'moderate' => 'Moderat (Ausgewogen)',
            'aggressive' => 'Aggressiv (Risikofreudig)',
            default => 'Unbekannt',
        };
    }
}
