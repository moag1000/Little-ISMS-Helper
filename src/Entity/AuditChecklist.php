<?php

namespace App\Entity;

use App\Entity\Tenant;
use App\Repository\AuditChecklistRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Represents audit checklist items for verifying compliance requirements
 * during internal audits
 */
#[ORM\Entity(repositoryClass: AuditChecklistRepository::class)]
class AuditChecklist
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?InternalAudit $audit = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?ComplianceRequirement $requirement = null;

    #[ORM\Column(length: 50)]
    private string $verificationStatus = 'not_checked'; // not_checked, compliant, partial, non_compliant, not_applicable

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $auditNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $evidenceFound = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $findings = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $recommendations = null;

    #[ORM\Column]
    private int $complianceScore = 0; // 0-100

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $auditor = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $verifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAudit(): ?InternalAudit
    {
        return $this->audit;
    }

    public function setAudit(?InternalAudit $audit): static
    {
        $this->audit = $audit;
        return $this;
    }

    public function getRequirement(): ?ComplianceRequirement
    {
        return $this->requirement;
    }

    public function setRequirement(?ComplianceRequirement $requirement): static
    {
        $this->requirement = $requirement;
        return $this;
    }

    public function getVerificationStatus(): string
    {
        return $this->verificationStatus;
    }

    public function setVerificationStatus(string $verificationStatus): static
    {
        $this->verificationStatus = $verificationStatus;
        return $this;
    }

    public function getAuditNotes(): ?string
    {
        return $this->auditNotes;
    }

    public function setAuditNotes(?string $auditNotes): static
    {
        $this->auditNotes = $auditNotes;
        return $this;
    }

    public function getEvidenceFound(): ?string
    {
        return $this->evidenceFound;
    }

    public function setEvidenceFound(?string $evidenceFound): static
    {
        $this->evidenceFound = $evidenceFound;
        return $this;
    }

    public function getFindings(): ?string
    {
        return $this->findings;
    }

    public function setFindings(?string $findings): static
    {
        $this->findings = $findings;
        return $this;
    }

    public function getRecommendations(): ?string
    {
        return $this->recommendations;
    }

    public function setRecommendations(?string $recommendations): static
    {
        $this->recommendations = $recommendations;
        return $this;
    }

    public function getComplianceScore(): int
    {
        return $this->complianceScore;
    }

    public function setComplianceScore(int $complianceScore): static
    {
        $this->complianceScore = max(0, min(100, $complianceScore));
        return $this;
    }

    public function getAuditor(): ?string
    {
        return $this->auditor;
    }

    public function setAuditor(?string $auditor): static
    {
        $this->auditor = $auditor;
        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeInterface
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?\DateTimeInterface $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;
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
     * Get status badge class
     */
    public function getStatusBadgeClass(): string
    {
        return match($this->verificationStatus) {
            'compliant' => 'success',
            'partial' => 'warning',
            'non_compliant' => 'danger',
            'not_applicable' => 'secondary',
            'not_checked' => 'info',
            default => 'secondary',
        };
    }

    /**
     * Get human-readable status
     */
    public function getStatusLabel(): string
    {
        return match($this->verificationStatus) {
            'compliant' => 'Konform',
            'partial' => 'Teilweise konform',
            'non_compliant' => 'Nicht konform',
            'not_applicable' => 'Nicht anwendbar',
            'not_checked' => 'Nicht geprüft',
            default => 'Unbekannt',
        };
    }

    /**
     * Mark as verified
     */
    public function markAsVerified(string $auditor): static
    {
        $this->auditor = $auditor;
        $this->verifiedAt = new \DateTimeImmutable();
        return $this;
    }

    /**
     * Check if verified
     */
    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }

    /**
     * Check if has findings
     */
    public function hasFindings(): bool
    {
        return !empty($this->findings);
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
}
