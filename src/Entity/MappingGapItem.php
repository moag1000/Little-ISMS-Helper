<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Repository\MappingGapItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents a specific gap in a compliance mapping
 * Describes what is missing to achieve full compliance
 */
#[ORM\Entity(repositoryClass: MappingGapItemRepository::class)]
class MappingGapItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ComplianceMapping::class, inversedBy: 'gapItems')]
    #[ORM\JoinColumn(name: 'mapping_id', nullable: false, onDelete: 'CASCADE')]
    private ?ComplianceMapping $complianceMapping = null;

    /**
     * Type of gap identified
     * - missing_control: Control requirement not addressed at all
     * - partial_coverage: Control partially addressed but incomplete
     * - scope_difference: Different scope or interpretation
     * - additional_requirement: Target has additional requirements beyond source
     * - evidence_gap: Control exists but lacks documentation
     */
    #[ORM\Column(length: 50)]
    private ?string $gapType = null;

    /**
     * Human-readable description of the gap
     */
    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    /**
     * Keywords/concepts missing in source requirement
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $missingKeywords = [];

    /**
     * Recommended action to close this gap
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recommendedAction = null;

    /**
     * Priority of addressing this gap
     */
    #[ORM\Column(length: 20)]
    private string $priority = 'medium'; // critical, high, medium, low

    /**
     * Estimated effort in hours to close this gap
     */
    #[ORM\Column(nullable: true)]
    private ?int $estimatedEffort = null;

    /**
     * Impact on mapping percentage (how much % this gap costs)
     */
    #[ORM\Column]
    private int $percentageImpact = 0;

    /**
     * Source of gap identification (algorithm/manual)
     */
    #[ORM\Column(length: 50)]
    private string $identificationSource = 'algorithm';

    /**
     * Confidence in this gap identification (0-100)
     */
    #[ORM\Column]
    private int $confidence = 50;

    /**
     * Status of gap remediation
     */
    #[ORM\Column(length: 30)]
    private string $status = 'identified'; // identified, planned, in_progress, resolved, wont_fix

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMapping(): ?ComplianceMapping
    {
        return $this->complianceMapping;
    }

    public function setMapping(?ComplianceMapping $complianceMapping): static
    {
        $this->complianceMapping = $complianceMapping;
        return $this;
    }

    public function getGapType(): ?string
    {
        return $this->gapType;
    }

    public function setGapType(string $gapType): static
    {
        $this->gapType = $gapType;
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

    public function getMissingKeywords(): ?array
    {
        return $this->missingKeywords;
    }

    public function setMissingKeywords(?array $missingKeywords): static
    {
        $this->missingKeywords = $missingKeywords;
        return $this;
    }

    public function getRecommendedAction(): ?string
    {
        return $this->recommendedAction;
    }

    public function setRecommendedAction(?string $recommendedAction): static
    {
        $this->recommendedAction = $recommendedAction;
        return $this;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function getEstimatedEffort(): ?int
    {
        return $this->estimatedEffort;
    }

    public function setEstimatedEffort(?int $estimatedEffort): static
    {
        $this->estimatedEffort = $estimatedEffort;
        return $this;
    }

    public function getPercentageImpact(): int
    {
        return $this->percentageImpact;
    }

    public function setPercentageImpact(int $percentageImpact): static
    {
        $this->percentageImpact = max(0, min(100, $percentageImpact));
        return $this;
    }

    public function getIdentificationSource(): string
    {
        return $this->identificationSource;
    }

    public function setIdentificationSource(string $identificationSource): static
    {
        $this->identificationSource = $identificationSource;
        return $this;
    }

    public function getConfidence(): int
    {
        return $this->confidence;
    }

    public function setConfidence(int $confidence): static
    {
        $this->confidence = max(0, min(100, $confidence));
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
     * Get badge class for priority
     */
    public function getPriorityBadgeClass(): string
    {
        return match($this->priority) {
            'critical' => 'danger',
            'high' => 'warning',
            'medium' => 'info',
            'low' => 'secondary',
            default => 'secondary',
        };
    }

    /**
     * Get human-readable gap type
     */
    public function getGapTypeLabel(): string
    {
        return match($this->gapType) {
            'missing_control' => 'Fehlende Kontrolle',
            'partial_coverage' => 'Teilweise Abdeckung',
            'scope_difference' => 'Scope-Unterschied',
            'additional_requirement' => 'Zusätzliche Anforderung',
            'evidence_gap' => 'Fehlende Evidenz',
            default => $this->gapType,
        };
    }

    /**
     * Get status badge class
     */
    public function getStatusBadgeClass(): string
    {
        return match($this->status) {
            'identified' => 'secondary',
            'planned' => 'info',
            'in_progress' => 'warning',
            'resolved' => 'success',
            'wont_fix' => 'dark',
            default => 'secondary',
        };
    }
}
