<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BulkImportRowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * BulkImportRow — per-row outcome record for a bulk-import operation.
 *
 * Allows error-CSV regeneration + row-level audit-trail without inflating
 * the HMAC-chain. Live HMAC-chain entries are still emitted per-row via
 * AuditLogger::logBulk() (referenced by batch_id); this entity is the
 * UI-friendly mirror.
 */
#[ORM\Entity(repositoryClass: BulkImportRowRepository::class)]
#[ORM\Table(name: 'bulk_import_row')]
#[ORM\Index(columns: ['batch_id', 'status'], name: 'idx_bulk_import_row_batch_status')]
class BulkImportRow
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_CREATED = 'created';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_UNCHANGED = 'unchanged';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_ERROR = 'error';

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';
    public const ACTION_NOOP = 'noop';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: BulkImportBatch::class, inversedBy: 'rows')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BulkImportBatch $batch = null;

    /**
     * 1-based row-number in the source spreadsheet (header = 1).
     */
    #[ORM\Column(name: '`row_number`')]
    private int $rowNumber;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $action = null;

    /**
     * Foreign-key id of the created/updated entity (e.g. Asset.id). Null on error.
     */
    #[ORM\Column(nullable: true)]
    private ?int $entityId = null;

    /**
     * Raw parsed row-data {column => value} as captured pre-mapping.
     */
    #[ORM\Column(type: Types::JSON)]
    private array $parsedData = [];

    /**
     * Old values when status=updated (for diff-view).
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $oldValues = null;

    /**
     * New values written (status=created|updated).
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $newValues = null;

    /**
     * Human-readable error message when status=error.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBatch(): ?BulkImportBatch
    {
        return $this->batch;
    }

    public function setBatch(?BulkImportBatch $batch): static
    {
        $this->batch = $batch;
        return $this;
    }

    public function getRowNumber(): int
    {
        return $this->rowNumber;
    }

    public function setRowNumber(int $rowNumber): static
    {
        $this->rowNumber = $rowNumber;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(?string $action): static
    {
        $this->action = $action;
        return $this;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): static
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getParsedData(): array
    {
        return $this->parsedData;
    }

    public function setParsedData(array $parsedData): static
    {
        $this->parsedData = $parsedData;
        return $this;
    }

    public function getOldValues(): ?array
    {
        return $this->oldValues;
    }

    public function setOldValues(?array $oldValues): static
    {
        $this->oldValues = $oldValues;
        return $this;
    }

    public function getNewValues(): ?array
    {
        return $this->newValues;
    }

    public function setNewValues(?array $newValues): static
    {
        $this->newValues = $newValues;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }
}
