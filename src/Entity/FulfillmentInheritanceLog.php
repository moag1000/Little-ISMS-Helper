<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FulfillmentInheritanceLogRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FulfillmentInheritanceLogRepository::class)]
#[ORM\Table(name: 'fulfillment_inheritance_log')]
#[ORM\Index(name: 'idx_fil_fulfillment', columns: ['fulfillment_id'])]
#[ORM\Index(name: 'idx_fil_status', columns: ['review_status'])]
#[ORM\Index(name: 'idx_fil_tenant_status', columns: ['tenant_id', 'review_status'])]
class FulfillmentInheritanceLog
{
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_OVERRIDDEN = 'overridden';
    public const STATUS_SOURCE_UPDATED = 'source_updated';
    public const STATUS_IMPLEMENTED = 'implemented';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: ComplianceRequirementFulfillment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ComplianceRequirementFulfillment $fulfillment = null;

    #[ORM\ManyToOne(targetEntity: ComplianceMapping::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?ComplianceMapping $derivedFromMapping = null;

    #[ORM\Column]
    private int $mappingVersionUsed = 1;

    #[ORM\Column]
    private int $suggestedPercentage = 0;

    #[ORM\Column(length: 30)]
    private string $reviewStatus = self::STATUS_PENDING_REVIEW;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $reviewedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $reviewedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewComment = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $fourEyesApprovedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $fourEyesApprovedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $overriddenBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $overriddenAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $overrideReason = null;

    #[ORM\Column(nullable: true)]
    private ?int $overrideValue = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    public function __construct()
    {
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

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getFulfillment(): ?ComplianceRequirementFulfillment
    {
        return $this->fulfillment;
    }

    public function setFulfillment(ComplianceRequirementFulfillment $fulfillment): static
    {
        $this->fulfillment = $fulfillment;
        return $this;
    }

    public function getDerivedFromMapping(): ?ComplianceMapping
    {
        return $this->derivedFromMapping;
    }

    public function setDerivedFromMapping(ComplianceMapping $mapping): static
    {
        $this->derivedFromMapping = $mapping;
        $this->mappingVersionUsed = $mapping->getVersion();
        return $this;
    }

    public function getMappingVersionUsed(): int
    {
        return $this->mappingVersionUsed;
    }

    public function setMappingVersionUsed(int $version): static
    {
        $this->mappingVersionUsed = $version;
        return $this;
    }

    public function getSuggestedPercentage(): int
    {
        return $this->suggestedPercentage;
    }

    public function setSuggestedPercentage(int $percentage): static
    {
        $this->suggestedPercentage = max(0, min(150, $percentage));
        return $this;
    }

    public function getReviewStatus(): string
    {
        return $this->reviewStatus;
    }

    public function setReviewStatus(string $status): static
    {
        $this->reviewStatus = $status;
        return $this;
    }

    public function isPendingReview(): bool
    {
        return $this->reviewStatus === self::STATUS_PENDING_REVIEW
            || $this->reviewStatus === self::STATUS_SOURCE_UPDATED;
    }

    public function getReviewedBy(): ?User
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?User $user): static
    {
        $this->reviewedBy = $user;
        return $this;
    }

    public function getReviewedAt(): ?DateTimeInterface
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?DateTimeInterface $at): static
    {
        $this->reviewedAt = $at;
        return $this;
    }

    public function getReviewComment(): ?string
    {
        return $this->reviewComment;
    }

    public function setReviewComment(?string $comment): static
    {
        $this->reviewComment = $comment;
        return $this;
    }

    public function getFourEyesApprovedBy(): ?User
    {
        return $this->fourEyesApprovedBy;
    }

    public function setFourEyesApprovedBy(?User $user): static
    {
        $this->fourEyesApprovedBy = $user;
        return $this;
    }

    public function getFourEyesApprovedAt(): ?DateTimeInterface
    {
        return $this->fourEyesApprovedAt;
    }

    public function setFourEyesApprovedAt(?DateTimeInterface $at): static
    {
        $this->fourEyesApprovedAt = $at;
        return $this;
    }

    public function getOverriddenBy(): ?User
    {
        return $this->overriddenBy;
    }

    public function setOverriddenBy(?User $user): static
    {
        $this->overriddenBy = $user;
        return $this;
    }

    public function getOverriddenAt(): ?DateTimeInterface
    {
        return $this->overriddenAt;
    }

    public function setOverriddenAt(?DateTimeInterface $at): static
    {
        $this->overriddenAt = $at;
        return $this;
    }

    public function getOverrideReason(): ?string
    {
        return $this->overrideReason;
    }

    public function setOverrideReason(?string $reason): static
    {
        $this->overrideReason = $reason;
        return $this;
    }

    public function getOverrideValue(): ?int
    {
        return $this->overrideValue;
    }

    public function setOverrideValue(?int $value): static
    {
        $this->overrideValue = $value;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getEffectivePercentage(): int
    {
        if ($this->reviewStatus === self::STATUS_OVERRIDDEN && $this->overrideValue !== null) {
            return $this->overrideValue;
        }
        return $this->suggestedPercentage;
    }
}
