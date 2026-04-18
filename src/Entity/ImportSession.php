<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportSessionRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * ISB-Review Sprint-2 gate MINOR-1 (docs/DATA_REUSE_PLAN_REVIEW_ISB.md):
 * per-file audit header for every compliance-mapping CSV/XML import.
 *
 * One ImportSession is created when a file is uploaded (status=preview).
 * It records the uploading user, tenant, original + stored filenames, a
 * SHA-256 hash over the file bytes and the aggregate row counters that are
 * rolled up by the ImportSessionRecorder when the commit concludes.
 *
 * Related row-level trail: {@see ImportRowEvent}.
 */
#[ORM\Entity(repositoryClass: ImportSessionRepository::class)]
#[ORM\Table(name: 'import_session')]
#[ORM\Index(name: 'idx_import_session_tenant_uploaded', columns: ['tenant_id', 'uploaded_at'])]
#[ORM\Index(name: 'idx_import_session_status', columns: ['status'])]
class ImportSession
{
    public const STATUS_PREVIEW = 'preview';
    public const STATUS_COMMITTED = 'committed';
    public const STATUS_CANCELLED = 'cancelled';

    public const FORMAT_CSV = 'csv_generic_v1';
    public const FORMAT_BSI_XML = 'bsi_profile_xml_v1';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', referencedColumnName: 'id', nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $uploadedAt;

    #[ORM\Column(length: 255)]
    private string $originalFilename = '';

    #[ORM\Column(length: 255)]
    private string $storedFilename = '';

    #[ORM\Column(length: 64)]
    private string $fileSha256 = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $fileSizeBytes = 0;

    #[ORM\Column(length: 32)]
    private string $format = self::FORMAT_CSV;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $rowCountTotal = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $rowCountImported = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $rowCountSuperseded = 0;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $rowCountSkipped = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'four_eyes_approver_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $fourEyesApprover = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PREVIEW;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $committedAt = null;

    /**
     * @var Collection<int, ImportRowEvent>
     */
    #[ORM\OneToMany(mappedBy: 'session', targetEntity: ImportRowEvent::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $rowEvents;

    public function __construct()
    {
        $this->uploadedAt = new DateTimeImmutable();
        $this->rowEvents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): self
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getUploadedAt(): DateTimeImmutable
    {
        return $this->uploadedAt;
    }

    public function setUploadedAt(DateTimeImmutable $uploadedAt): self
    {
        $this->uploadedAt = $uploadedAt;

        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(string $storedFilename): self
    {
        $this->storedFilename = $storedFilename;

        return $this;
    }

    public function getFileSha256(): string
    {
        return $this->fileSha256;
    }

    public function setFileSha256(string $fileSha256): self
    {
        $this->fileSha256 = $fileSha256;

        return $this;
    }

    public function getFileSizeBytes(): int
    {
        return $this->fileSizeBytes;
    }

    public function setFileSizeBytes(int $fileSizeBytes): self
    {
        $this->fileSizeBytes = $fileSizeBytes;

        return $this;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function getRowCountTotal(): int
    {
        return $this->rowCountTotal;
    }

    public function setRowCountTotal(int $rowCountTotal): self
    {
        $this->rowCountTotal = $rowCountTotal;

        return $this;
    }

    public function getRowCountImported(): int
    {
        return $this->rowCountImported;
    }

    public function setRowCountImported(int $rowCountImported): self
    {
        $this->rowCountImported = $rowCountImported;

        return $this;
    }

    public function getRowCountSuperseded(): int
    {
        return $this->rowCountSuperseded;
    }

    public function setRowCountSuperseded(int $rowCountSuperseded): self
    {
        $this->rowCountSuperseded = $rowCountSuperseded;

        return $this;
    }

    public function getRowCountSkipped(): int
    {
        return $this->rowCountSkipped;
    }

    public function setRowCountSkipped(int $rowCountSkipped): self
    {
        $this->rowCountSkipped = $rowCountSkipped;

        return $this;
    }

    public function getFourEyesApprover(): ?User
    {
        return $this->fourEyesApprover;
    }

    public function setFourEyesApprover(?User $fourEyesApprover): self
    {
        $this->fourEyesApprover = $fourEyesApprover;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCommittedAt(): ?DateTimeImmutable
    {
        return $this->committedAt;
    }

    public function setCommittedAt(?DateTimeImmutable $committedAt): self
    {
        $this->committedAt = $committedAt;

        return $this;
    }

    /**
     * @return Collection<int, ImportRowEvent>
     */
    public function getRowEvents(): Collection
    {
        return $this->rowEvents;
    }

    public function addRowEvent(ImportRowEvent $event): self
    {
        if (!$this->rowEvents->contains($event)) {
            $this->rowEvents->add($event);
            $event->setSession($this);
        }

        return $this;
    }

    /**
     * Convenience: first 12 chars of the SHA-256 for human-friendly displays.
     */
    public function getShortHash(): string
    {
        return substr($this->fileSha256, 0, 12);
    }
}
