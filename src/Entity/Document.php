<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Repository\DocumentRepository;
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $uploadedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256Hash = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isPublic = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isArchived = false;

    #[ORM\Column(length: 50)]
    private string $status = 'active';

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

    public function __construct()
    {
        $this->uploadedAt = new DateTimeImmutable();
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
    public function getUploadedAt(): ?DateTimeInterface { return $this->uploadedAt; }
    public function setUploadedAt(DateTimeInterface $uploadedAt): static { $this->uploadedAt = $uploadedAt; return $this; }
    public function getUpdatedAt(): ?DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?DateTimeInterface $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
    public function getSha256Hash(): ?string { return $this->sha256Hash; }
    public function setSha256Hash(?string $sha256Hash): static { $this->sha256Hash = $sha256Hash; return $this; }
    public function isPublic(): bool { return $this->isPublic; }
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
}
