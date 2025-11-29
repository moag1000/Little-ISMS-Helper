<?php

namespace App\Entity;

use App\Repository\ConsentRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Consent Entity - GDPR Art. 7 Compliance
 *
 * Internal workflow for managing data subject consents.
 * Designed for internal users (DPO, Data Owners) to document
 * consents obtained through external channels (email, phone, paper, etc.)
 *
 * Key Principles:
 * - dataSubjectIdentifier: External person (NOT User entity)
 * - documentedBy: Internal user who recorded the consent
 * - verifiedBy: DPO who verified the consent
 * - proofDocument: Evidence (email, scanned form, etc.)
 *
 * GDPR Requirements:
 * - Art. 7 Abs. 1: Proof of consent (nachweispflicht)
 * - Art. 7 Abs. 3: Right to withdraw consent
 * - Art. 5 Abs. 1 lit. b: Purpose limitation
 */
#[ORM\Entity(repositoryClass: ConsentRepository::class)]
#[ORM\Index(name: 'idx_consent_data_subject', columns: ['data_subject_identifier'])]
#[ORM\Index(name: 'idx_consent_status', columns: ['status'])]
#[ORM\Index(name: 'idx_consent_granted_at', columns: ['granted_at'])]
#[ORM\Index(name: 'idx_consent_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_consent_processing_activity', columns: ['processing_activity_id'])]
class Consent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    // ═══════════════════════════════════════════════════════════
    // BETROFFENE PERSON (External Data Subject)
    // ═══════════════════════════════════════════════════════════

    /**
     * Identifier of the external data subject
     * NOT a User from the User table - this is an external person
     * Examples: email, customer ID, pseudonym, etc.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Data subject identifier is required')]
    #[Assert\Length(max: 255, maxMessage: 'Identifier cannot exceed {{ limit }} characters')]
    private ?string $dataSubjectIdentifier = null;

    /**
     * Type of identifier for documentation purposes
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Identifier type is required')]
    #[Assert\Choice(
        choices: ['email', 'customer_id', 'pseudonym', 'phone', 'other'],
        message: 'Invalid identifier type'
    )]
    private ?string $identifierType = null;

    // ═══════════════════════════════════════════════════════════
    // VERARBEITUNGSTÄTIGKEIT (Processing Activity)
    // ═══════════════════════════════════════════════════════════

    /**
     * Link to Processing Activity (VVT)
     * Data Reuse: ProcessingActivity with legalBasis = 'consent' (Art. 6(1)(a) GDPR)
     */
    #[ORM\ManyToOne(targetEntity: ProcessingActivity::class, inversedBy: 'consents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'Processing activity is required')]
    private ?ProcessingActivity $processingActivity = null;

    /**
     * Granular purposes within the processing activity
     * Example: ProcessingActivity = "Newsletter", purposes = ['marketing', 'profiling']
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $purposes = [];

    // ═══════════════════════════════════════════════════════════
    // EINWILLIGUNGSERTEILUNG (Grant)
    // ═══════════════════════════════════════════════════════════

    /**
     * Date/time when consent was granted by data subject
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Assert\NotNull(message: 'Grant date is required')]
    private ?DateTimeImmutable $grantedAt = null;

    /**
     * Method of consent
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Consent method is required')]
    #[Assert\Choice(
        choices: ['double_opt_in', 'written_form', 'checkbox', 'oral', 'email', 'other'],
        message: 'Invalid consent method'
    )]
    private ?string $consentMethod = null;

    /**
     * Exact wording of the consent at time of grant
     * IMPORTANT: GDPR Art. 7 Abs. 1 - proof requirement
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Consent text is required for proof')]
    private ?string $consentText = null;

    /**
     * Channel through which consent was obtained
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Choice(
        choices: ['website', 'email', 'paper_form', 'phone', 'in_person', 'other'],
        message: 'Invalid consent channel'
    )]
    private ?string $consentChannel = null;

    // ═══════════════════════════════════════════════════════════
    // NACHWEISDOKUMENTATION (Proof)
    // ═══════════════════════════════════════════════════════════

    /**
     * Link to proof document (e.g., scanned consent form, email)
     */
    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $proofDocument = null;

    /**
     * Additional proof metadata (JSON)
     * Example: {"ip": "192.168.1.1", "user_agent": "...", "form_version": "1.2"}
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $proofMetadata = [];

    // ═══════════════════════════════════════════════════════════
    // INTERNE VERWALTUNG (Internal Management)
    // ═══════════════════════════════════════════════════════════

    /**
     * Internal user who documented this consent
     * IMPORTANT: NOT the data subject! Internal user (DPO, Data Owner)
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'documented_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $documentedBy = null;

    /**
     * Timestamp when consent was documented internally
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $documentedAt = null;

    /**
     * Internal status
     */
    #[ORM\Column(length: 50)]
    #[Assert\Choice(
        choices: ['active', 'revoked', 'expired', 'pending_verification', 'rejected'],
        message: 'Invalid consent status'
    )]
    private ?string $status = 'pending_verification';

    /**
     * DPO verification flag
     */
    #[ORM\Column]
    private bool $isVerifiedByDpo = false;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'verified_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $verifiedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $verifiedAt = null;

    // ═══════════════════════════════════════════════════════════
    // WIDERRUF (Revocation)
    // ═══════════════════════════════════════════════════════════

    /**
     * Consent has been revoked?
     */
    #[ORM\Column]
    private bool $isRevoked = false;

    /**
     * Timestamp when consent was revoked by data subject
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    /**
     * Method of revocation
     */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Choice(
        choices: ['email', 'phone', 'letter', 'website', 'in_person', 'other'],
        message: 'Invalid revocation method'
    )]
    private ?string $revocationMethod = null;

    /**
     * Internal user who documented the revocation
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'revocation_documented_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $revocationDocumentedBy = null;

    /**
     * Revocation proof document (e.g., email with revocation request)
     */
    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $revocationProofDocument = null;

    // ═══════════════════════════════════════════════════════════
    // ABLAUF & GÜLTIGKEIT (Expiry & Validity)
    // ═══════════════════════════════════════════════════════════

    /**
     * Expiry date of consent (optional)
     * Example: Consent granted for 2 years
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    /**
     * Internal notes
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    // ═══════════════════════════════════════════════════════════
    // AUDIT TRAIL
    // ═══════════════════════════════════════════════════════════

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->documentedAt = new DateTimeImmutable();
        $this->purposes = [];
        $this->proofMetadata = [];
    }

    // ═══════════════════════════════════════════════════════════
    // GETTERS & SETTERS
    // ═══════════════════════════════════════════════════════════

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

    public function getDataSubjectIdentifier(): ?string
    {
        return $this->dataSubjectIdentifier;
    }

    public function setDataSubjectIdentifier(string $dataSubjectIdentifier): static
    {
        $this->dataSubjectIdentifier = $dataSubjectIdentifier;
        return $this;
    }

    public function getIdentifierType(): ?string
    {
        return $this->identifierType;
    }

    public function setIdentifierType(string $identifierType): static
    {
        $this->identifierType = $identifierType;
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

    public function getPurposes(): ?array
    {
        return $this->purposes;
    }

    public function setPurposes(?array $purposes): static
    {
        $this->purposes = $purposes ?? [];
        return $this;
    }

    public function getGrantedAt(): ?DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function setGrantedAt(DateTimeImmutable $grantedAt): static
    {
        $this->grantedAt = $grantedAt;
        return $this;
    }

    public function getConsentMethod(): ?string
    {
        return $this->consentMethod;
    }

    public function setConsentMethod(string $consentMethod): static
    {
        $this->consentMethod = $consentMethod;
        return $this;
    }

    public function getConsentText(): ?string
    {
        return $this->consentText;
    }

    public function setConsentText(string $consentText): static
    {
        $this->consentText = $consentText;
        return $this;
    }

    public function getConsentChannel(): ?string
    {
        return $this->consentChannel;
    }

    public function setConsentChannel(?string $consentChannel): static
    {
        $this->consentChannel = $consentChannel;
        return $this;
    }

    public function getProofDocument(): ?Document
    {
        return $this->proofDocument;
    }

    public function setProofDocument(?Document $proofDocument): static
    {
        $this->proofDocument = $proofDocument;
        return $this;
    }

    public function getProofMetadata(): ?array
    {
        return $this->proofMetadata;
    }

    public function setProofMetadata(?array $proofMetadata): static
    {
        $this->proofMetadata = $proofMetadata ?? [];
        return $this;
    }

    public function getDocumentedBy(): ?User
    {
        return $this->documentedBy;
    }

    public function setDocumentedBy(?User $documentedBy): static
    {
        $this->documentedBy = $documentedBy;
        return $this;
    }

    public function getDocumentedAt(): ?DateTimeImmutable
    {
        return $this->documentedAt;
    }

    public function setDocumentedAt(DateTimeImmutable $documentedAt): static
    {
        $this->documentedAt = $documentedAt;
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

    public function isVerifiedByDpo(): bool
    {
        return $this->isVerifiedByDpo;
    }

    public function setIsVerifiedByDpo(bool $isVerifiedByDpo): static
    {
        $this->isVerifiedByDpo = $isVerifiedByDpo;
        return $this;
    }

    public function getVerifiedBy(): ?User
    {
        return $this->verifiedBy;
    }

    public function setVerifiedBy(?User $verifiedBy): static
    {
        $this->verifiedBy = $verifiedBy;
        return $this;
    }

    public function getVerifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?DateTimeImmutable $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;
        return $this;
    }

    public function isRevoked(): bool
    {
        return $this->isRevoked;
    }

    public function setIsRevoked(bool $isRevoked): static
    {
        $this->isRevoked = $isRevoked;
        return $this;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?DateTimeImmutable $revokedAt): static
    {
        $this->revokedAt = $revokedAt;
        return $this;
    }

    public function getRevocationMethod(): ?string
    {
        return $this->revocationMethod;
    }

    public function setRevocationMethod(?string $revocationMethod): static
    {
        $this->revocationMethod = $revocationMethod;
        return $this;
    }

    public function getRevocationDocumentedBy(): ?User
    {
        return $this->revocationDocumentedBy;
    }

    public function setRevocationDocumentedBy(?User $revocationDocumentedBy): static
    {
        $this->revocationDocumentedBy = $revocationDocumentedBy;
        return $this;
    }

    public function getRevocationProofDocument(): ?Document
    {
        return $this->revocationProofDocument;
    }

    public function setRevocationProofDocument(?Document $revocationProofDocument): static
    {
        $this->revocationProofDocument = $revocationProofDocument;
        return $this;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;
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

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    // ═══════════════════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════════════════

    /**
     * Check if consent is currently valid
     */
    public function isValid(): bool
    {
        if ($this->isRevoked) {
            return false;
        }

        if ($this->status !== 'active') {
            return false;
        }

        if ($this->expiresAt !== null && $this->expiresAt < new DateTimeImmutable()) {
            return false;
        }

        return true;
    }

    /**
     * Check if consent is expiring soon (within 30 days)
     */
    public function isExpiringSoon(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        $now = new DateTimeImmutable();
        $thirtyDaysFromNow = $now->modify('+30 days');

        return $this->expiresAt <= $thirtyDaysFromNow && $this->expiresAt > $now;
    }

    /**
     * Get display name for data subject
     */
    public function getDataSubjectDisplay(): string
    {
        return sprintf('%s (%s)', $this->dataSubjectIdentifier, $this->identifierType);
    }

    /**
     * Get status badge color for UI
     */
    public function getStatusBadgeColor(): string
    {
        return match ($this->status) {
            'active' => 'success',
            'pending_verification' => 'warning',
            'revoked' => 'danger',
            'expired' => 'secondary',
            'rejected' => 'danger',
            default => 'secondary',
        };
    }
}
