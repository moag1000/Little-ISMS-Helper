<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Repository\DocumentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentRepository::class)]
#[ORM\Index(name: 'idx_document_tenant', columns: ['tenant_id'])]
#[ORM\HasLifecycleCallbacks]
class Document
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    private ?string $filename = null;

    #[ORM\Column(length: 255)]
    private ?string $originalFilename = null;

    #[ORM\Column(length: 100)]
    private ?string $mimeType = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $fileSize = null;

    #[ORM\Column(length: 500)]
    private ?string $filePath = null;

    #[ORM\Column(length: 100)]
    private ?string $category = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $entityType = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $entityId = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    /**
     * Person-Rollout Phase A — governance-side document owner.
     *
     * `uploadedBy` (User) records who uploaded the file (audit-trail of
     * the system action). `ownerPerson` (Person) records who is
     * **accountable** for the document long-term — typically a CISO,
     * ISB, DPO or function-owner who may or may not have a system
     * login. External governance owners are common in DACH-Mittelstand
     * (outsourced DPO, fractional CISO).
     *
     * Backfill on initial migration copies `uploadedBy.linkedPerson.id`
     * here when the uploader already has a Person profile. Otherwise
     * the column is NULL and the owner is set explicitly through the
     * document edit form.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'owner_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Person $ownerPerson = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $uploadedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256Hash = null;

    /**
     * @deprecated since 2026-05-14 — no renderer, form, or query references this field.
     *             Column preserved (data-safe); remove in next major (v4.0).
     *             TODO: drop column in migration when confirmed unused post-v4.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isPublic = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isArchived = false;

    /**
     * Lifecycle status. Canonical 5-stage workflow:
     *   draft → in_review → approved → published → archived
     *
     * S3 P-4: legacy 6th status `active` removed; rows holding `active` were
     * migrated to `published` by the consolidated data-migration
     * (Version20260518150000_vvt_document_canonical_lifecycle).
     *
     * @see \App\Lifecycle\LifecycleRegistry::STANDARD_5_STAGE
     */
    #[ORM\Column(length: 50, options: ['default' => 'draft'])]
    private string $status = 'draft';

    /**
     * TISAX / VDA-ISA 6.0 information classification.
     * Values: public, internal, confidential, strictly_confidential, prototype.
     */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $tisaxInformationClassification = null;

    /**
     * Phase 9.P2.1 — Holding policy inheritance.
     *
     * inheritable: Holding-Tenant marks a document (typically a policy)
     * as downstream-visible. Subsidiaries will see it read-only in their
     * document register. Default false: only owner tenant sees it.
     *
     * overrideAllowed: when true, a subsidiary may author its own local
     * document with the same title/category; the inherited one remains
     * visible as reference but the local override takes precedence in
     * the tochter's operational view. When false, the holding mandates
     * the policy verbatim — the subsidiary has no legitimate local
     * alternative (typical for Top-Management-ISMS-Leitlinie, Code of
     * Conduct, Data-Protection-Policy under concentrated DPO).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $inheritable = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $overrideAllowed = true;

    /**
     * Policy-Wizard W3 — provenance link to the PolicyTemplate that
     * produced this document. Null when the document was uploaded
     * manually or imported from another flow.
     */
    #[ORM\ManyToOne(targetEntity: PolicyTemplate::class)]
    #[ORM\JoinColumn(name: 'generated_from_template_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PolicyTemplate $generatedFromTemplate = null;

    /**
     * Policy-Wizard W3 — provenance link to the WizardRun that
     * produced this document. Null when the document was not produced
     * by a Policy-Wizard run.
     */
    #[ORM\ManyToOne(targetEntity: WizardRun::class)]
    #[ORM\JoinColumn(name: 'generated_from_wizard_run_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?WizardRun $generatedFromWizardRun = null;

    /**
     * Policy-Wizard W3 — snapshot of the variable substitution map at
     * generation time. Drives §10 re-generation diffing (compute
     * stable hash for change detection) and serves as audit-trail
     * manifest for §11.2 (hidden-marker rendering).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(name: 'substitution_variables', type: Types::JSON, nullable: true)]
    private ?array $substitutionVariables = null;

    /**
     * Policy-Wizard W3 / §10 — once a generated policy reaches
     * `status='approved'`, the document is locked: the UI hides the
     * edit button and force-edit requires SUPER_ADMIN + audit entry.
     */
    #[ORM\Column(name: 'is_immutable', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isImmutable = false;

    /**
     * Policy-Wizard W3 / §10 — version chain. When a re-run produces
     * a changed policy, the new Document is created and points back
     * to the prior approved one via `supersedes`. The old document
     * stays approved + read-only as historical evidence.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'supersedes_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $supersedes = null;

    /**
     * Policy-Wizard — persisted rendered body of a wizard-generated
     * policy. Until W7-X this column was unset and the body lived
     * ONLY in the translation file (`policy.<standard>.<topic>.v<n>.body`)
     * + the `substitutionVariables` JSON map; the PDF exporter
     * re-rendered the body on every export.
     *
     * Storing the rendered body here unlocks tenant-specific
     * post-generation customisation (CISO appends ABC GmbH-specific
     * clauses) without losing the wizard-baseline reference. The
     * exporter prefers this column when set; null falls through to
     * the legacy translation re-render path (back-compat for legacy
     * rows pre-W7-X).
     *
     * Markdown encoded — same flavour as the wizard generator emits.
     */
    #[ORM\Column(name: 'policy_body', type: Types::TEXT, nullable: true)]
    private ?string $policyBody = null;

    /**
     * Policy-Wizard — timestamp of the most recent post-generation
     * edit of `policyBody`. NULL means "never manually edited" (the
     * persisted body is the wizard-baseline). When non-NULL the doc
     * is considered "drifted" from the wizard baseline; re-generation
     * preserves the edited body and surfaces the conflict in the
     * W7-C diff UI.
     */
    #[ORM\Column(name: 'policy_body_edited_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $policyBodyEditedAt = null;

    /**
     * Policy-Wizard — User who performed the most recent
     * post-generation edit of `policyBody`. Paired with
     * `policyBodyEditedAt` for the per-document audit-trail surface
     * ("Lokal angepasste Version — zuletzt bearbeitet von X am Y").
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'policy_body_edited_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $policyBodyEditedBy = null;

    /**
     * Effectiveness-Review (Auditor MINOR-NC reply, 2026-05-10).
     *
     * SoaAutoUpdateService bumps Annex-A controls from `not_implemented`
     * to `in_progress` when a policy is generated, but it NEVER bumps
     * to `implemented` — that decision requires a separate effectiveness
     * review (Wirksamkeitspruefung) by ISB or Auditor. These three
     * columns capture the explicit review event:
     *
     *  - `lastEffectivenessReviewAt` — timestamp of the most recent
     *    review (NULL = never reviewed).
     *  - `lastEffectivenessReviewBy` — User who performed the review.
     *    FK SET NULL: review evidence survives user deletion.
     *  - `effectivenessReviewNotes` — free-text rationale / observation
     *    captured during the review (auditor evidence).
     *
     * The review event itself does NOT mutate the SoA implementation
     * status — the ISB still has to decide manually whether the
     * effectiveness evidence warrants a status bump to `implemented`.
     * That separation preserves the auditor-mandated decision moment.
     */
    #[ORM\Column(name: 'last_effectiveness_review_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $lastEffectivenessReviewAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'last_effectiveness_review_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $lastEffectivenessReviewBy = null;

    #[ORM\Column(name: 'effectiveness_review_notes', type: Types::TEXT, nullable: true)]
    private ?string $effectivenessReviewNotes = null;

    /**
     * V3 W2-LB-8 — Review-cycle (formal scheduled review of the document
     * itself; complements the effectiveness-review event above).
     *
     * `nextReviewDate` is auto-populated by DocumentApprovalListener when
     * the document transitions to status='approved': set to
     * approvedAt + reviewIntervalMonths. Documents without a next-review
     * date are treated as "no scheduled review" — typically uploads from
     * before LB-8.
     */
    #[ORM\Column(name: 'next_review_date', type: Types::DATE_MUTABLE, nullable: true)]
    private ?DateTimeInterface $nextReviewDate = null;

    /**
     * V3 W2-LB-8 — Cadence in months for the review-cycle. Default 12
     * (annual) matches the ISO 27001 Cl.7.5.2 expectation for documented
     * information governing the ISMS.
     */
    #[ORM\Column(name: 'review_interval_months', type: Types::INTEGER, options: ['default' => 12])]
    private int $reviewIntervalMonths = 12;

    /**
     * V3 W2-Bug2 — Document version label.
     *
     * Stored as a string, not a numeric, so that semantic versioning
     * (`1.2.3-beta`) and date-stamped policies (`2026-Q2`) both fit.
     * Defaults to `'1.0'` for newly uploaded files; the
     * Auto-Acknowledgement-Campaign listener (W2-C4) requires a
     * non-empty value to satisfy the (tenant, document, user, version)
     * uniqueness constraint on `policy_acknowledgement`.
     */
    #[ORM\Column(name: 'version', length: 32, options: ['default' => '1.0'])]
    private string $version = '1.0';

    /**
     * V3 W2-Bug2 — Requires user acknowledgement.
     *
     * When true and the document transitions to `status='approved'`,
     * the {@see \App\EventListener\AutoReactionAcknowledgementCampaignListener}
     * fans out PolicyAcknowledgement (status=pending) rows to every
     * active user of the tenant — closing ISO 27001 A.6.3 ("policy
     * must be communicated and acknowledged"). Default false: opt-in
     * per document so uploaded evidence files do not silently spawn
     * acknowledgement campaigns.
     */
    #[ORM\Column(name: 'requires_acknowledgement', type: Types::BOOLEAN, options: ['default' => false])]
    private bool $requiresAcknowledgement = false;

    /**
     * Sprint-2 P-7 Wave-2 — explicit acknowledgement audience (ISO 27001 A.6.3).
     *
     * When `requiresAcknowledgement = true` and this collection is empty,
     * {@see App\EventListener\AutoReactionAcknowledgementCampaignListener}
     * defaults to fan-out across ALL active tenant users (legacy behaviour).
     * When this collection is non-empty, the campaign listener should
     * restrict the fan-out to listed users (smaller targeted audience).
     *
     * Owning side; no inverse on User (users are referenced from many
     * sides — adding inverse collections per usage would explode the
     * entity surface).
     *
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'document_acknowledgement_audience')]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $acknowledgementAudience;

    // ── F4 Evidence-Versioning fields ─────────────────────────────────────────

    /**
     * F4 — SHA-256 hex digest of the current file on disk.
     * Kept in sync with the currentVersion.contentHash by EvidenceVersioningService.
     * 64-char hex string; NULL for documents uploaded before F4 was deployed.
     */
    #[ORM\Column(name: 'content_hash', length: 64, nullable: true)]
    private ?string $contentHash = null;

    /**
     * F4 — FK to the currently active DocumentVersion.
     * NULL for legacy documents that pre-date the versioning feature.
     */
    #[ORM\ManyToOne(targetEntity: DocumentVersion::class)]
    #[ORM\JoinColumn(name: 'current_version_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?DocumentVersion $currentVersion = null;

    /**
     * F4 — all DocumentVersion rows for this document (ordered by version_number).
     *
     * @var Collection<int, DocumentVersion>
     */
    #[ORM\OneToMany(targetEntity: DocumentVersion::class, mappedBy: 'document', cascade: ['persist'], orphanRemoval: false)]
    #[ORM\OrderBy(['versionNumber' => 'ASC'])]
    private Collection $versions;

    // ── End F4 fields ─────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->uploadedAt = new DateTimeImmutable();
        $this->versions = new ArrayCollection();
        $this->acknowledgementAudience = new ArrayCollection();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    // Getters and Setters
    public function getId(): ?int { return $this->id; }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getFilename(): ?string { return $this->filename; }
    public function setFilename(string $filename): static { $this->filename = $filename; return $this; }
    public function getOriginalFilename(): ?string { return $this->originalFilename; }
    public function setOriginalFilename(string $originalFilename): static { $this->originalFilename = $originalFilename; return $this; }
    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(string $mimeType): static { $this->mimeType = $mimeType; return $this; }
    public function getFileSize(): ?int { return $this->fileSize; }
    public function setFileSize(int $fileSize): static { $this->fileSize = $fileSize; return $this; }

    public function getFileSizeFormatted(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = $this->fileSize;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }
        return round($size, 2) . ' ' . $units[$unit];
    }

    public function getFilePath(): ?string { return $this->filePath; }
    public function setFilePath(string $filePath): static { $this->filePath = $filePath; return $this; }
    public function getCategory(): ?string { return $this->category; }
    public function setCategory(string $category): static { $this->category = $category; return $this; }
    public function getDescription(): ?string { return $this->description; }
    public function setDescription(?string $description): static { $this->description = $description; return $this; }
    public function getEntityType(): ?string { return $this->entityType; }
    public function setEntityType(?string $entityType): static { $this->entityType = $entityType; return $this; }
    public function getEntityId(): ?int { return $this->entityId; }
    public function setEntityId(?int $entityId): static { $this->entityId = $entityId; return $this; }
    public function getUploadedBy(): ?User { return $this->uploadedBy; }
    public function setUploadedBy(?User $user): static { $this->uploadedBy = $user; return $this; }

    public function getOwnerPerson(): ?Person { return $this->ownerPerson; }
    public function setOwnerPerson(?Person $ownerPerson): static { $this->ownerPerson = $ownerPerson; return $this; }

    /**
     * Effective owner display: prefer `ownerPerson.fullName`, fall back to
     * `uploadedBy.fullName`. Returns null when neither is set (legacy
     * documents pre-Person-Rollout that never had an uploader either).
     */
    public function getEffectiveOwnerName(): ?string
    {
        return $this->ownerPerson?->getFullName()
            ?? $this->uploadedBy?->getFullName();
    }
    public function getUploadedAt(): ?DateTimeInterface { return $this->uploadedAt; }
    public function setUploadedAt(DateTimeInterface $uploadedAt): static { $this->uploadedAt = $uploadedAt; return $this; }
    public function getUpdatedAt(): ?DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
    public function getSha256Hash(): ?string { return $this->sha256Hash; }
    public function setSha256Hash(?string $sha256Hash): static { $this->sha256Hash = $sha256Hash; return $this; }
    /** @deprecated since 2026-05-14 — see field docblock */
    public function isPublic(): bool { return $this->isPublic; }
    /** @deprecated since 2026-05-14 — see field docblock */
    public function setIsPublic(bool $isPublic): static { $this->isPublic = $isPublic; return $this; }
    public function isArchived(): bool { return $this->isArchived; }
    public function setIsArchived(bool $isArchived): static { $this->isArchived = $isArchived; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    /**
     * Operationally visible = not soft-deleted or archived. Used for
     * default index listings; KPI / audit views may bypass this.
     */
    public function isOperational(): bool
    {
        return !in_array($this->status, ['deleted', 'archived'], true);
    }

    public function getTisaxInformationClassification(): ?string { return $this->tisaxInformationClassification; }
    public function setTisaxInformationClassification(?string $value): static { $this->tisaxInformationClassification = $value; return $this; }

    public function isInheritable(): bool { return $this->inheritable; }
    public function setInheritable(bool $value): static { $this->inheritable = $value; return $this; }

    public function isOverrideAllowed(): bool { return $this->overrideAllowed; }
    public function setOverrideAllowed(bool $value): static { $this->overrideAllowed = $value; return $this; }

    public function getFileExtension(): string
    {
        return pathinfo((string) $this->originalFilename, PATHINFO_EXTENSION);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mimeType, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mimeType === 'application/pdf';
    }

    public function getGeneratedFromTemplate(): ?PolicyTemplate
    {
        return $this->generatedFromTemplate;
    }

    public function setGeneratedFromTemplate(?PolicyTemplate $template): static
    {
        $this->generatedFromTemplate = $template;
        return $this;
    }

    public function getGeneratedFromWizardRun(): ?WizardRun
    {
        return $this->generatedFromWizardRun;
    }

    public function setGeneratedFromWizardRun(?WizardRun $run): static
    {
        $this->generatedFromWizardRun = $run;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getSubstitutionVariables(): ?array
    {
        return $this->substitutionVariables;
    }

    /** @param array<string, mixed>|null $variables */
    public function setSubstitutionVariables(?array $variables): static
    {
        $this->substitutionVariables = $variables;
        return $this;
    }

    public function isImmutable(): bool
    {
        return $this->isImmutable;
    }

    public function setIsImmutable(bool $isImmutable): static
    {
        $this->isImmutable = $isImmutable;
        return $this;
    }

    public function getSupersedes(): ?self
    {
        return $this->supersedes;
    }

    public function setSupersedes(?self $supersedes): static
    {
        $this->supersedes = $supersedes;
        return $this;
    }

    public function getPolicyBody(): ?string
    {
        return $this->policyBody;
    }

    public function setPolicyBody(?string $policyBody): static
    {
        $this->policyBody = $policyBody;
        return $this;
    }

    public function getPolicyBodyEditedAt(): ?DateTimeImmutable
    {
        return $this->policyBodyEditedAt;
    }

    public function setPolicyBodyEditedAt(?DateTimeImmutable $editedAt): static
    {
        $this->policyBodyEditedAt = $editedAt;
        return $this;
    }

    public function getPolicyBodyEditedBy(): ?User
    {
        return $this->policyBodyEditedBy;
    }

    public function setPolicyBodyEditedBy(?User $editedBy): static
    {
        $this->policyBodyEditedBy = $editedBy;
        return $this;
    }

    /**
     * Effective policy body for export / display. Returns the
     * persisted `policyBody` when set (post-W7-X writes), null
     * otherwise — callers fall back to translation-based re-render
     * for legacy rows. Empty string is treated as "intentionally
     * blank" and returned as-is so the editor can clear the body
     * without forcing a re-render fall-back.
     */
    public function getEffectivePolicyBody(): ?string
    {
        return $this->policyBody;
    }

    public function getLastEffectivenessReviewAt(): ?DateTimeImmutable
    {
        return $this->lastEffectivenessReviewAt;
    }

    public function setLastEffectivenessReviewAt(?DateTimeImmutable $reviewedAt): static
    {
        $this->lastEffectivenessReviewAt = $reviewedAt;
        return $this;
    }

    public function getLastEffectivenessReviewBy(): ?User
    {
        return $this->lastEffectivenessReviewBy;
    }

    public function setLastEffectivenessReviewBy(?User $reviewedBy): static
    {
        $this->lastEffectivenessReviewBy = $reviewedBy;
        return $this;
    }

    public function getEffectivenessReviewNotes(): ?string
    {
        return $this->effectivenessReviewNotes;
    }

    public function setEffectivenessReviewNotes(?string $notes): static
    {
        $this->effectivenessReviewNotes = $notes;
        return $this;
    }

    public function getNextReviewDate(): ?DateTimeInterface
    {
        return $this->nextReviewDate;
    }

    public function setNextReviewDate(?DateTimeInterface $nextReviewDate): static
    {
        $this->nextReviewDate = $nextReviewDate;
        return $this;
    }

    public function getReviewIntervalMonths(): int
    {
        return $this->reviewIntervalMonths;
    }

    public function setReviewIntervalMonths(int $reviewIntervalMonths): static
    {
        $this->reviewIntervalMonths = max(1, $reviewIntervalMonths);
        return $this;
    }

    /**
     * V3 W2-Bug2 — Document version label.
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        // Normalise empty / whitespace-only input to the default so the
        // Auto-Acknowledgement-Campaign listener never trips on a blank
        // version when `requiresAcknowledgement = true` is flipped.
        $trimmed = trim($version);
        $this->version = $trimmed === '' ? '1.0' : $trimmed;
        return $this;
    }

    /**
     * V3 W2-Bug2 — Requires user acknowledgement (ISO 27001 A.6.3).
     */
    public function getRequiresAcknowledgement(): bool
    {
        return $this->requiresAcknowledgement;
    }

    public function setRequiresAcknowledgement(bool $requiresAcknowledgement): static
    {
        $this->requiresAcknowledgement = $requiresAcknowledgement;
        return $this;
    }

    /**
     * Sprint-2 P-7 Wave-2 — explicit acknowledgement audience.
     *
     * @return Collection<int, User>
     */
    public function getAcknowledgementAudience(): Collection
    {
        return $this->acknowledgementAudience;
    }

    public function addAcknowledgementAudience(User $user): static
    {
        if (!$this->acknowledgementAudience->contains($user)) {
            $this->acknowledgementAudience->add($user);
        }
        return $this;
    }

    public function removeAcknowledgementAudience(User $user): static
    {
        $this->acknowledgementAudience->removeElement($user);
        return $this;
    }

    /** Convenience alias matching the boolean-getter idiom (`is*`). */
    public function isRequiresAcknowledgement(): bool
    {
        return $this->requiresAcknowledgement;
    }

    /**
     * V3 W2-LB-8 — true when nextReviewDate is set and is on / before today.
     */
    public function isReviewOverdue(?DateTimeInterface $now = null): bool
    {
        if (!$this->nextReviewDate instanceof DateTimeInterface) {
            return false;
        }
        $now ??= new DateTimeImmutable('today');
        return $this->nextReviewDate <= $now;
    }

    /**
     * Age (\DateInterval) since the last effectiveness review.
     * Returns NULL when no review has ever been recorded — callers
     * should treat NULL as "never reviewed" and rank it as the worst
     * possible age (most-overdue) for sorting / overdue logic.
     */
    public function getEffectivenessAge(): ?\DateInterval
    {
        if ($this->lastEffectivenessReviewAt === null) {
            return null;
        }
        return $this->lastEffectivenessReviewAt->diff(new DateTimeImmutable());
    }

    /**
     * True when the effectiveness review is overdue against the given
     * cadence (months). Conservative semantics:
     *  - never reviewed → ALWAYS overdue (returns true)
     *  - reviewed > $intervalMonths ago → overdue
     *  - reviewed within the interval → not overdue
     *
     * Caller chooses the cadence — typically the default review
     * interval from the Policy-Wizard Lifecycle step (12 months) or
     * a per-policy override.
     */
    public function isEffectivenessOverdue(int $intervalMonths): bool
    {
        if ($intervalMonths < 1) {
            return false; // defensive — no cadence ⇒ no overdue concept
        }
        if ($this->lastEffectivenessReviewAt === null) {
            return true;
        }
        $deadline = $this->lastEffectivenessReviewAt->modify(
            sprintf('+%d months', $intervalMonths),
        );
        return new DateTimeImmutable() > $deadline;
    }

    /**
     * True when a user has edited the policy body after the wizard
     * generated it. The signal drives:
     *  - the "Lokal angepasst" mini-chip in the document index
     *  - the W7-C re-generation diff `policyBodyDrift` flag
     *  - the PDF footer marker "Lokal angepasste Version"
     *  - the re-generation conflict path (preserve edited body, fork
     *    a new wizard-baseline version via `supersedes`)
     *
     * The `policyBodyEditedBy` arm catches the rare race where the
     * timestamp survived but the user reference was nulled (FK
     * SET NULL on user deletion).
     */
    public function hasPostGenerationEdits(): bool
    {
        return $this->policyBodyEditedAt !== null
            || $this->policyBodyEditedBy !== null;
    }

    /**
     * Compliance-Manager Wish — parse the `dora-validity:YYYY-MM-DD`
     * EntityTag name into an immutable date so the document show-view
     * and PDF cover can render the DORA-Stand prominently.
     *
     * Documents do not own their tags directly (the `EntityTag` table is
     * the join row); callers resolve the active tag-name list via
     * {@see \App\Repository\EntityTagRepository::findActiveFor} and pass
     * the names in. Returns null when no `dora-validity:*` tag is
     * present or when the date payload fails ISO-8601 parsing.
     *
     * @param iterable<int, string> $tagNames Active tag names for this document.
     */
    public static function parseDoraValidityFromTags(iterable $tagNames): ?DateTimeImmutable
    {
        foreach ($tagNames as $name) {
            if (!is_string($name) || $name === '') {
                continue;
            }
            if (!str_starts_with($name, 'dora-validity:')) {
                continue;
            }
            $payload = substr($name, strlen('dora-validity:'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payload)) {
                continue;
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $payload);
            // Strict round-trip check — `createFromFormat` silently
            // rolls over invalid dates (Feb 30 → Mar 2). Reject when
            // the formatted result diverges from the input payload.
            if ($parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $payload) {
                return $parsed;
            }
        }
        return null;
    }

    /**
     * Compliance-Manager Wish — true when the active tag set marks the
     * document as Climate-Change-aware (ISO 27001:2022 Amd. 1:2024).
     *
     * The wizard emits `climate-change:amended` on top-level ISO 27001
     * Information-Security-Policy renders. Show-view + PDF cover use
     * the flag to surface a prominent badge so auditors recognise the
     * 2024 amendment was applied without reading the body prose.
     *
     * @param iterable<int, string> $tagNames Active tag names for this document.
     */
    public static function isClimateChangeAwareFromTags(iterable $tagNames): bool
    {
        foreach ($tagNames as $name) {
            if (is_string($name) && $name === 'climate-change:amended') {
                return true;
            }
        }
        return false;
    }

    // ── F4 Evidence-Versioning getters/setters ─────────────────────────────

    public function getContentHash(): ?string
    {
        return $this->contentHash;
    }

    public function setContentHash(?string $contentHash): static
    {
        $this->contentHash = $contentHash;
        return $this;
    }

    public function getCurrentVersion(): ?DocumentVersion
    {
        return $this->currentVersion;
    }

    public function setCurrentVersion(?DocumentVersion $currentVersion): static
    {
        $this->currentVersion = $currentVersion;
        return $this;
    }

    /**
     * @return Collection<int, DocumentVersion>
     */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function addVersion(DocumentVersion $version): static
    {
        if (!$this->versions->contains($version)) {
            $this->versions->add($version);
            $version->setDocument($this);
        }
        return $this;
    }

    public function removeVersion(DocumentVersion $version): static
    {
        $this->versions->removeElement($version);
        return $this;
    }
}
