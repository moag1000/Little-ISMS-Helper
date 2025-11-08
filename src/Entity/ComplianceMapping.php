<?php

namespace App\Entity;

use App\Repository\ComplianceMappingRepository;
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
    #[ORM\JoinColumn(nullable: false)]
    private ?ComplianceRequirement $sourceRequirement = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
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

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $verificationDate = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceRequirement(): ?ComplianceRequirement
    {
        return $this->sourceRequirement;
    }

    public function setSourceRequirement(?ComplianceRequirement $sourceRequirement): static
    {
        $this->sourceRequirement = $sourceRequirement;
        return $this;
    }

    public function getTargetRequirement(): ?ComplianceRequirement
    {
        return $this->targetRequirement;
    }

    public function setTargetRequirement(?ComplianceRequirement $targetRequirement): static
    {
        $this->targetRequirement = $targetRequirement;
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

    public function getVerificationDate(): ?\DateTimeInterface
    {
        return $this->verificationDate;
    }

    public function setVerificationDate(?\DateTimeInterface $verificationDate): static
    {
        $this->verificationDate = $verificationDate;
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
        $sourceFulfillment = $this->sourceRequirement->getFulfillmentPercentage();
        $mappingStrength = $this->mappingPercentage / 100;

        return round($sourceFulfillment * $mappingStrength, 2);
    }
}
