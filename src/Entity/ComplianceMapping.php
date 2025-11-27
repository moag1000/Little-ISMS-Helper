<?php

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Repository\ComplianceMappingRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents cross-framework mappings between compliance requirements
 * Shows how fulfilling one requirement (source) satisfies another (target)
 */
#[ORM\Entity(repositoryClass: ComplianceMappingRepository::class)]
class ComplianceMapping
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ComplianceRequirement $sourceRequirement = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ComplianceRequirement $targetRequirement = null;

    /**
     * Percentage of target requirement satisfied by source (0-150)
     * 0-49: weak relationship
     * 50-99: partially satisfies
     * 100: fully satisfies
     * 101-150: exceeds/overachieves
     */
    #[ORM\Column]
    private int $mappingPercentage = 0;

    #[ORM\Column(length: 50)]
    private ?string $mappingType = null; // weak, partial, full, exceeds

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $mappingRationale = null;

    /**
     * Indicates if this is a bidirectional mapping
     */
    #[ORM\Column]
    private bool $bidirectional = false;

    /**
     * Confidence level of this mapping
     */
    #[ORM\Column(length: 20)]
    private string $confidence = 'medium'; // low, medium, high

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $verifiedBy = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $verificationDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    /**
     * Collection of gap items for this mapping
     */
    #[ORM\OneToMany(mappedBy: 'complianceMapping', targetEntity: MappingGapItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $gapItems;

    /**
     * Automatically calculated percentage based on text analysis and similarity algorithms
     */
    #[ORM\Column(nullable: true)]
    private ?int $calculatedPercentage = null;

    /**
     * Manually overridden percentage (takes precedence over calculated)
     */
    #[ORM\Column(nullable: true)]
    private ?int $manualPercentage = null;

    /**
     * Confidence score of the automated analysis (0-100)
     * Higher = more confident in the calculated percentage
     */
    #[ORM\Column(nullable: true)]
    private ?int $analysisConfidence = null;

    /**
     * Version of the analysis algorithm used
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $analysisAlgorithmVersion = null;

    /**
     * Whether this mapping requires manual review
     */
    #[ORM\Column]
    private bool $requiresReview = false;

    /**
     * Review status: unreviewed, in_review, approved, rejected
     */
    #[ORM\Column(length: 30)]
    private string $reviewStatus = 'unreviewed';

    /**
     * Overall quality score of this mapping (0-100)
     * Based on multiple factors: confidence, completeness, verification
     */
    #[ORM\Column(nullable: true)]
    private ?int $qualityScore = null;

    /**
     * Textual similarity score between source and target requirements (0-1)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, nullable: true)]
    private ?string $textualSimilarity = null;

    /**
     * Keyword overlap score (0-1)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, nullable: true)]
    private ?string $keywordOverlap = null;

    /**
     * Structural similarity score (0-1)
     * Based on category, scope, control type alignment
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 4, nullable: true)]
    private ?string $structuralSimilarity = null;

    /**
     * Notes from manual review
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewNotes = null;

    /**
     * User who reviewed this mapping
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reviewedBy = null;

    /**
     * Date when this mapping was reviewed
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $reviewedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->gapItems = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceRequirement(): ?ComplianceRequirement
    {
        return $this->sourceRequirement;
    }

    public function setSourceRequirement(?ComplianceRequirement $complianceRequirement): static
    {
        $this->sourceRequirement = $complianceRequirement;
        return $this;
    }

    public function getTargetRequirement(): ?ComplianceRequirement
    {
        return $this->targetRequirement;
    }

    public function setTargetRequirement(?ComplianceRequirement $complianceRequirement): static
    {
        $this->targetRequirement = $complianceRequirement;
        return $this;
    }

    public function getMappingPercentage(): int
    {
        return $this->mappingPercentage;
    }

    public function setMappingPercentage(int $mappingPercentage): static
    {
        $this->mappingPercentage = max(0, min(150, $mappingPercentage));

        // Auto-update mapping type based on percentage
        $this->updateMappingType();

        return $this;
    }

    public function getMappingType(): ?string
    {
        return $this->mappingType;
    }

    public function setMappingType(string $mappingType): static
    {
        $this->mappingType = $mappingType;
        return $this;
    }

    private function updateMappingType(): void
    {
        if ($this->mappingPercentage < 50) {
            $this->mappingType = 'weak';
        } elseif ($this->mappingPercentage < 100) {
            $this->mappingType = 'partial';
        } elseif ($this->mappingPercentage === 100) {
            $this->mappingType = 'full';
        } else {
            $this->mappingType = 'exceeds';
        }
    }

    public function getMappingRationale(): ?string
    {
        return $this->mappingRationale;
    }

    public function setMappingRationale(?string $mappingRationale): static
    {
        $this->mappingRationale = $mappingRationale;
        return $this;
    }

    public function isBidirectional(): bool
    {
        return $this->bidirectional;
    }

    public function setBidirectional(bool $bidirectional): static
    {
        $this->bidirectional = $bidirectional;
        return $this;
    }

    public function getConfidence(): string
    {
        return $this->confidence;
    }

    public function setConfidence(string $confidence): static
    {
        $this->confidence = $confidence;
        return $this;
    }

    public function getVerifiedBy(): ?string
    {
        return $this->verifiedBy;
    }

    public function setVerifiedBy(?string $verifiedBy): static
    {
        $this->verifiedBy = $verifiedBy;
        return $this;
    }

    public function getVerificationDate(): ?DateTimeInterface
    {
        return $this->verificationDate;
    }

    public function setVerificationDate(?DateTimeInterface $verificationDate): static
    {
        $this->verificationDate = $verificationDate;
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
     * Get badge class for mapping type
     */
    public function getMappingBadgeClass(): string
    {
        return match($this->mappingType) {
            'exceeds' => 'success',
            'full' => 'success',
            'partial' => 'warning',
            'weak' => 'secondary',
            default => 'secondary',
        };
    }

    /**
     * Get human-readable mapping description
     */
    public function getMappingDescription(): string
    {
        return match($this->mappingType) {
            'exceeds' => sprintf('Exceeds target requirement (%d%%)', $this->mappingPercentage),
            'full' => 'Fully satisfies target requirement (100%)',
            'partial' => sprintf('Partially satisfies target requirement (%d%%)', $this->mappingPercentage),
            'weak' => sprintf('Weak relationship (%d%%)', $this->mappingPercentage),
            default => sprintf('%d%% mapping', $this->mappingPercentage),
        };
    }

    /**
     * Calculate transitive fulfillment
     * If source is fulfilled, how much does it contribute to target?
     */
    public function calculateTransitiveFulfillment(): float
    {
        if (!$this->sourceRequirement instanceof ComplianceRequirement) {
            return 0.0;
        }

        $sourceFulfillment = $this->sourceRequirement->getFulfillmentPercentage();
        $mappingStrength = $this->mappingPercentage / 100;

        return round($sourceFulfillment * $mappingStrength, 2);
    }

    /**
     * @return Collection<int, MappingGapItem>
     */
    public function getGapItems(): Collection
    {
        return $this->gapItems;
    }

    public function addGapItem(MappingGapItem $mappingGapItem): static
    {
        if (!$this->gapItems->contains($mappingGapItem)) {
            $this->gapItems->add($mappingGapItem);
            $mappingGapItem->setMapping($this);
        }

        return $this;
    }

    public function removeGapItem(MappingGapItem $mappingGapItem): static
    {
        if ($this->gapItems->removeElement($mappingGapItem) && $mappingGapItem->getMapping() === $this) {
            $mappingGapItem->setMapping(null);
        }

        return $this;
    }

    public function getCalculatedPercentage(): ?int
    {
        return $this->calculatedPercentage;
    }

    public function setCalculatedPercentage(?int $calculatedPercentage): static
    {
        $this->calculatedPercentage = $calculatedPercentage !== null ? max(0, min(150, $calculatedPercentage)) : null;
        return $this;
    }

    public function getManualPercentage(): ?int
    {
        return $this->manualPercentage;
    }

    public function setManualPercentage(?int $manualPercentage): static
    {
        $this->manualPercentage = $manualPercentage !== null ? max(0, min(150, $manualPercentage)) : null;
        return $this;
    }

    /**
     * Get the final percentage (manual takes precedence over calculated)
     */
    public function getFinalPercentage(): int
    {
        return $this->manualPercentage ?? $this->calculatedPercentage ?? $this->mappingPercentage;
    }

    public function getAnalysisConfidence(): ?int
    {
        return $this->analysisConfidence;
    }

    public function setAnalysisConfidence(?int $analysisConfidence): static
    {
        $this->analysisConfidence = $analysisConfidence !== null ? max(0, min(100, $analysisConfidence)) : null;
        return $this;
    }

    public function getAnalysisAlgorithmVersion(): ?string
    {
        return $this->analysisAlgorithmVersion;
    }

    public function setAnalysisAlgorithmVersion(?string $analysisAlgorithmVersion): static
    {
        $this->analysisAlgorithmVersion = $analysisAlgorithmVersion;
        return $this;
    }

    public function isRequiresReview(): bool
    {
        return $this->requiresReview;
    }

    public function setRequiresReview(bool $requiresReview): static
    {
        $this->requiresReview = $requiresReview;
        return $this;
    }

    public function getReviewStatus(): string
    {
        return $this->reviewStatus;
    }

    public function setReviewStatus(string $reviewStatus): static
    {
        $this->reviewStatus = $reviewStatus;
        return $this;
    }

    public function getQualityScore(): ?int
    {
        return $this->qualityScore;
    }

    public function setQualityScore(?int $qualityScore): static
    {
        $this->qualityScore = $qualityScore !== null ? max(0, min(100, $qualityScore)) : null;
        return $this;
    }

    public function getTextualSimilarity(): ?float
    {
        return $this->textualSimilarity !== null ? (float) $this->textualSimilarity : null;
    }

    public function setTextualSimilarity(?float $textualSimilarity): static
    {
        $this->textualSimilarity = $textualSimilarity !== null ? (string) max(0, min(1, $textualSimilarity)) : null;
        return $this;
    }

    public function getKeywordOverlap(): ?float
    {
        return $this->keywordOverlap !== null ? (float) $this->keywordOverlap : null;
    }

    public function setKeywordOverlap(?float $keywordOverlap): static
    {
        $this->keywordOverlap = $keywordOverlap !== null ? (string) max(0, min(1, $keywordOverlap)) : null;
        return $this;
    }

    public function getStructuralSimilarity(): ?float
    {
        return $this->structuralSimilarity !== null ? (float) $this->structuralSimilarity : null;
    }

    public function setStructuralSimilarity(?float $structuralSimilarity): static
    {
        $this->structuralSimilarity = $structuralSimilarity !== null ? (string) max(0, min(1, $structuralSimilarity)) : null;
        return $this;
    }

    public function getReviewNotes(): ?string
    {
        return $this->reviewNotes;
    }

    public function setReviewNotes(?string $reviewNotes): static
    {
        $this->reviewNotes = $reviewNotes;
        return $this;
    }

    public function getReviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?string $reviewedBy): static
    {
        $this->reviewedBy = $reviewedBy;
        return $this;
    }

    public function getReviewedAt(): ?DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?DateTimeInterface $reviewedAt): static
    {
        $this->reviewedAt = $reviewedAt;
        return $this;
    }

    /**
     * Get badge class for review status
     */
    public function getReviewStatusBadgeClass(): string
    {
        return match($this->reviewStatus) {
            'approved' => 'success',
            'in_review' => 'warning',
            'rejected' => 'danger',
            'unreviewed' => 'secondary',
            default => 'secondary',
        };
    }

    /**
     * Check if mapping has been analyzed by the quality system
     */
    public function isAnalyzed(): bool
    {
        return $this->calculatedPercentage !== null && $this->analysisConfidence !== null;
    }

    /**
     * Check if mapping uses manual override
     */
    public function hasManualOverride(): bool
    {
        return $this->manualPercentage !== null;
    }

    /**
     * Get total percentage impact of all gaps
     */
    public function getTotalGapImpact(): int
    {
        $total = 0;
        foreach ($this->gapItems as $gapItem) {
            $total += $gapItem->getPercentageImpact();
        }
        return $total;
    }

    /**
     * Get count of unresolved gaps
     */
    public function getUnresolvedGapCount(): int
    {
        $count = 0;
        foreach ($this->gapItems as $gapItem) {
            if (!in_array($gapItem->getStatus(), ['resolved', 'wont_fix'], true)) {
                $count++;
            }
        }
        return $count;
    }
}

