<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentSectionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-section approval state machine for privacy sections inside a Policy
 * Document — Phase 4-C / Sprint W3-C ("DPO Veto Mechanic").
 *
 * Implements the section-level sub-workflow described in
 * `docs/plans/policy-wizard/06-dpo-input.md` §0.A. Each row represents
 * ONE section inside a host {@see Document}, identified by `sectionKey`
 * (matches the `body_translation_key` suffix in {@see PolicyTemplate},
 * e.g. `privacy_addendum`, `privacy_addendum_breach`). The section
 * carries its own approval lifecycle independent of the host document
 * so the DPO can sign off / reject a single privacy section without
 * forcing the whole host policy back to draft.
 *
 * Status state machine (see §0.A.2):
 *
 *   draft → dpo_sign_off → approved
 *                       └→ rejected (reverts to draft on next edit)
 *
 * Tenant-scoped (multi-tenancy mandate per CLAUDE.md). Unique on
 * (document_id, sectionKey) so each section appears at most once per
 * host document.
 */
#[ORM\Entity(repositoryClass: DocumentSectionRepository::class)]
#[ORM\Table(name: 'document_section')]
#[ORM\UniqueConstraint(name: 'uq_document_section_doc_key', columns: ['document_id', 'section_key'])]
#[ORM\Index(name: 'idx_document_section_doc_status', columns: ['document_id', 'status'])]
#[ORM\Index(name: 'idx_document_section_tenant', columns: ['tenant_id'])]
class DocumentSection
{
    public const string STATUS_DRAFT        = 'draft';
    public const string STATUS_DPO_SIGN_OFF = 'dpo_sign_off';
    public const string STATUS_APPROVED     = 'approved';
    public const string STATUS_REJECTED     = 'rejected';

    /** @var list<string> */
    public const array ALLOWED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_DPO_SIGN_OFF,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    /**
     * Suffix that identifies the section within the host PolicyTemplate's
     * body translation key (e.g. `privacy_addendum`,
     * `privacy_addendum_breach`, `privacy_addendum_int_transfers`).
     */
    #[ORM\Column(name: 'section_key', length: 100)]
    private ?string $sectionKey = null;

    #[ORM\Column(length: 32)]
    private string $status = self::STATUS_DRAFT;

    /**
     * Substituted body of this section. Captured at the moment the
     * section is approved so subsequent template edits don't silently
     * mutate published evidence.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $contentSnapshot = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approved_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedByUser = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $rejectedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'rejected_by_user_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $rejectedByUser = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rejectionReason = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): static
    {
        $this->document = $document;
        return $this;
    }

    public function getSectionKey(): ?string
    {
        return $this->sectionKey;
    }

    public function setSectionKey(string $sectionKey): static
    {
        $this->sectionKey = $sectionKey;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid DocumentSection status "%s". Allowed: %s',
                $status,
                implode(', ', self::ALLOWED_STATUSES),
            ));
        }
        $this->status = $status;
        return $this;
    }

    public function getContentSnapshot(): ?string
    {
        return $this->contentSnapshot;
    }

    public function setContentSnapshot(?string $contentSnapshot): static
    {
        $this->contentSnapshot = $contentSnapshot;
        return $this;
    }

    public function getApprovedAt(): ?DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    public function getApprovedByUser(): ?User
    {
        return $this->approvedByUser;
    }

    public function setApprovedByUser(?User $approvedByUser): static
    {
        $this->approvedByUser = $approvedByUser;
        return $this;
    }

    public function getRejectedAt(): ?DateTimeImmutable
    {
        return $this->rejectedAt;
    }

    public function setRejectedAt(?DateTimeImmutable $rejectedAt): static
    {
        $this->rejectedAt = $rejectedAt;
        return $this;
    }

    public function getRejectedByUser(): ?User
    {
        return $this->rejectedByUser;
    }

    public function setRejectedByUser(?User $rejectedByUser): static
    {
        $this->rejectedByUser = $rejectedByUser;
        return $this;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }

    public function setRejectionReason(?string $rejectionReason): static
    {
        $this->rejectionReason = $rejectionReason;
        return $this;
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Convenience: privacy sections are addressed via their key prefix.
     * `06-dpo-input.md` §0.A.4 grants ROLE_DPO exclusive write access to
     * sections whose key starts with `privacy_`.
     */
    public function isPrivacySection(): bool
    {
        return $this->sectionKey !== null && str_starts_with($this->sectionKey, 'privacy_');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }
}
