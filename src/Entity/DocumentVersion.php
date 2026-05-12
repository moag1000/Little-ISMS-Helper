<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DocumentVersionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F4 Evidence-Versioning — immutable document version snapshot.
 *
 * Once publishedAt is set, this entity MUST NOT be deleted (enforced by
 * DocumentVersionVoter denying DELETE). The version history is the
 * auditable evidence chain required by ISO 27001 Cl.7.5.3.
 *
 * Immutability rules:
 *  - No UPDATE on any field after publishedAt is set (enforced at service layer).
 *  - DELETE is blocked by the voter (only SUPER_ADMIN can force-delete via CLI).
 *  - `replacedBy` (nullable self-FK) records which later version superseded this one.
 */
#[ORM\Entity(repositoryClass: DocumentVersionRepository::class)]
#[ORM\Table(name: 'document_version')]
#[ORM\Index(name: 'idx_docver_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_docver_document', columns: ['document_id'])]
#[ORM\Index(name: 'idx_docver_active', columns: ['is_active'])]
class DocumentVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * Parent document this version belongs to.
     */
    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'versions')]
    #[ORM\JoinColumn(name: 'document_id', nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    /**
     * Monotonically increasing version number within the document. Starts at 1.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $versionNumber = 1;

    /**
     * SHA-256 hex digest of the file content at upload time (streamed via ContentHashCalculator).
     * 64-char hex string. Used for hash-match detection to avoid storing duplicates.
     */
    #[ORM\Column(length: 64)]
    private string $contentHash = '';

    /**
     * Stored filename on disk (UUID-based, opaque to users).
     */
    #[ORM\Column(length: 255)]
    private string $fileName = '';

    /**
     * Absolute or project-relative path to the stored file.
     */
    #[ORM\Column(length: 500)]
    private string $filePath = '';

    /**
     * File size in bytes.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $fileSize = 0;

    #[ORM\Column(length: 100)]
    private string $mimeType = '';

    /**
     * User who uploaded / created this version.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $uploadedAt;

    /**
     * Timestamp when this version was officially published (made active).
     * NULL means the version is still a draft and can be undone within the 5s buffer.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $publishedAt = null;

    /**
     * Retention deadline for compliance archiving. NULL = no explicit deadline.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $retentionUntil = null;

    /**
     * FK to the version that replaced this one (newer version).
     * NULL when this is still the current version.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'replaced_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?self $replacedBy = null;

    /**
     * True when this version is the currently active one on the parent document.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    public function __construct()
    {
        $this->uploadedAt = new DateTimeImmutable();
    }

    // -------------------------------------------------------------------------
    // Getters / setters
    // -------------------------------------------------------------------------

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

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): static
    {
        $this->document = $document;
        return $this;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function setVersionNumber(int $versionNumber): static
    {
        $this->versionNumber = $versionNumber;
        return $this;
    }

    public function getContentHash(): string
    {
        return $this->contentHash;
    }

    public function setContentHash(string $contentHash): static
    {
        $this->contentHash = $contentHash;
        return $this;
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    public function setFileName(string $fileName): static
    {
        $this->fileName = $fileName;
        return $this;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;
        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): static
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): static
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;
        return $this;
    }

    public function getUploadedAt(): DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(DateTimeImmutable $uploadedAt): static
    {
        $this->uploadedAt = $uploadedAt;
        return $this;
    }

    public function getPublishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function setPublishedAt(?DateTimeImmutable $publishedAt): static
    {
        $this->publishedAt = $publishedAt;
        return $this;
    }

    /**
     * True when this version has been officially published (5s-undo window has passed).
     */
    public function isPublished(): bool
    {
        return $this->publishedAt !== null;
    }

    public function getRetentionUntil(): ?DateTimeImmutable
    {
        return $this->retentionUntil;
    }

    public function setRetentionUntil(?DateTimeImmutable $retentionUntil): static
    {
        $this->retentionUntil = $retentionUntil;
        return $this;
    }

    public function getReplacedBy(): ?self
    {
        return $this->replacedBy;
    }

    public function setReplacedBy(?self $replacedBy): static
    {
        $this->replacedBy = $replacedBy;
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

    /**
     * Human-readable formatted file size (B / KB / MB / GB).
     */
    public function getFileSizeFormatted(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->fileSize;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024.0;
            ++$unit;
        }
        return round($size, 2) . ' ' . $units[$unit];
    }
}
