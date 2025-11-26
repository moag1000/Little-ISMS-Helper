<?php

namespace App\Entity;

use App\Repository\RiskAppetiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * RiskAppetite Entity (ISO 27005:2022)
 *
 * Defines the organization's willingness to accept risk.
 * Supports both global and category-specific risk appetite levels.
 *
 * Phase 6F-B2: Risk appetite management for ISO 27001 compliance
 */
#[ORM\Entity(repositoryClass: RiskAppetiteRepository::class)]
#[ORM\Index(columns: ['category'], name: 'idx_risk_appetite_category')]
#[ORM\Index(columns: ['is_active'], name: 'idx_risk_appetite_active')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_risk_appetite_tenant')]
class RiskAppetite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['risk_appetite:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['risk_appetite:read'])]
    private ?Tenant $tenant = null;

    /**
     * Risk category (null = global appetite, otherwise category-specific)
     * Examples: "Financial", "Operational", "Compliance", "Strategic", "Reputational"
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['risk_appetite:read', 'risk_appetite:write'])]
    #[Assert\Length(max: 100, maxMessage: 'Category name cannot exceed {{ limit }} characters')]
    private ?string $category = null;

    /**
     * Maximum acceptable risk level (probability × impact)
     * Scale: 1-25 (5×5 matrix)
     * Example thresholds:
     * - 1-6: Low risk (generally acceptable)
     * - 7-12: Medium risk (requires justification)
     * - 13-25: High risk (requires executive approval)
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[Groups(['risk_appetite:read', 'risk_appetite:write'])]
    #[Assert\NotNull(message: 'Maximum acceptable risk level is required')]
    #[Assert\Range(
        min: 1,
        max: 25,
        notInRangeMessage: 'Maximum acceptable risk must be between {{ min }} and {{ max }}'
    )]
    private ?int $maxAcceptableRisk = null;

    /**
     * Risk appetite description and rationale
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['risk_appetite:read', 'risk_appetite:write'])]
    #[Assert\NotBlank(message: 'Risk appetite description is required')]
    private ?string $description = null;

    /**
     * Active status (only one appetite per category should be active)
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['risk_appetite:read', 'risk_appetite:write'])]
    private bool $isActive = true;

    /**
     * User who approved this risk appetite
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['risk_appetite:read', 'risk_appetite:write'])]
    private ?User $approvedBy = null;

    /**
     * Date when this risk appetite was approved
     */
    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['risk_appetite:read', 'risk_appetite:write'])]
    private ?\DateTimeInterface $approvedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['risk_appetite:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    #[Groups(['risk_appetite:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
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

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getMaxAcceptableRisk(): ?int
    {
        return $this->maxAcceptableRisk;
    }

    public function setMaxAcceptableRisk(int $maxAcceptableRisk): static
    {
        $this->maxAcceptableRisk = $maxAcceptableRisk;
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

    public function getApprovedAt(): ?\DateTimeInterface
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?\DateTimeInterface $approvedAt): static
    {
        $this->approvedAt = $approvedAt;
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
     * Check if this is a global risk appetite (applies to all risk categories)
     */
    #[Groups(['risk_appetite:read'])]
    public function isGlobal(): bool
    {
        return $this->category === null;
    }

    /**
     * Get display name for this appetite
     */
    #[Groups(['risk_appetite:read'])]
    public function getDisplayName(): string
    {
        return $this->isGlobal()
            ? 'Global Risk Appetite'
            : $this->category . ' Risk Appetite';
    }

    /**
     * Get risk level classification based on appetite
     * Data Reuse: Classify risk scores relative to appetite
     */
    public function getRiskLevelClassification(int $riskScore): string
    {
        if ($riskScore <= $this->maxAcceptableRisk) {
            return 'acceptable';
        } elseif ($riskScore <= $this->maxAcceptableRisk * 1.5) {
            return 'review_required';
        } else {
            return 'exceeds_appetite';
        }
    }

    /**
     * Check if a given risk score is within appetite
     * Data Reuse: Quick acceptance check for risk assessments
     */
    #[Groups(['risk_appetite:read'])]
    public function isRiskAcceptable(int $riskScore): bool
    {
        return $riskScore <= $this->maxAcceptableRisk;
    }

    /**
     * Get percentage of appetite consumed by a risk score
     * Data Reuse: Visual representation of risk vs appetite
     */
    #[Groups(['risk_appetite:read'])]
    public function getAppetitePercentage(int $riskScore): float
    {
        if ($this->maxAcceptableRisk === 0) {
            return 0.0;
        }
        return round(($riskScore / $this->maxAcceptableRisk) * 100, 2);
    }

    /**
     * Check if appetite is properly approved
     * ISO 27005 compliance: Risk appetite must be formally approved
     */
    #[Groups(['risk_appetite:read'])]
    public function isApproved(): bool
    {
        return $this->approvedBy !== null && $this->approvedAt !== null;
    }
}
