<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportRowEventRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * ISB-Review Sprint-2 gate MINOR-1 (docs/DATA_REUSE_PLAN_REVIEW_ISB.md):
 * per-row audit trail for a single imported CSV/XML record.
 *
 * Each row of every imported file produces exactly one ImportRowEvent with:
 *   - line number within the source file (1-based, excluding comments/blanks)
 *   - decision (import | update | skip | merge | error)
 *   - before/after JSON snapshots (truncated to 4 KB each)
 *   - raw source row (for re-reading the exact input bytes)
 *   - optional target entity reference so auditors can answer
 *     "show me exactly how mapping NIS2-3.2 was created on 2026-08-12"
 *     via ImportRowEventRepository::findByTarget().
 */
#[ORM\Entity(repositoryClass: ImportRowEventRepository::class)]
#[ORM\Table(name: 'import_row_event')]
#[ORM\Index(name: 'idx_import_row_event_session_decision', columns: ['session_id', 'decision'])]
#[ORM\Index(name: 'idx_import_row_event_target', columns: ['target_entity_type', 'target_entity_id'])]
class ImportRowEvent
{
    public const DECISION_IMPORT = 'import';
    public const DECISION_UPDATE = 'update';
    public const DECISION_SKIP = 'skip';
    public const DECISION_MERGE = 'merge';
    public const DECISION_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ImportSession::class, cascade: ['persist'], inversedBy: 'rowEvents')]
    #[ORM\JoinColumn(name: 'session_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ImportSession $session = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $lineNumber = 0;

    #[ORM\Column(length: 20)]
    private string $decision = self::DECISION_IMPORT;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $targetEntityType = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $targetEntityId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $beforeState = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $afterState = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $sourceRowRaw = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSession(): ?ImportSession
    {
        return $this->session;
    }

    public function setSession(?ImportSession $session): self
    {
        $this->session = $session;

        return $this;
    }

    public function getLineNumber(): int
    {
        return $this->lineNumber;
    }

    public function setLineNumber(int $lineNumber): self
    {
        $this->lineNumber = $lineNumber;

        return $this;
    }

    public function getDecision(): string
    {
        return $this->decision;
    }

    public function setDecision(string $decision): self
    {
        $this->decision = $decision;

        return $this;
    }

    public function getTargetEntityType(): ?string
    {
        return $this->targetEntityType;
    }

    public function setTargetEntityType(?string $targetEntityType): self
    {
        $this->targetEntityType = $targetEntityType;

        return $this;
    }

    public function getTargetEntityId(): ?int
    {
        return $this->targetEntityId;
    }

    public function setTargetEntityId(?int $targetEntityId): self
    {
        $this->targetEntityId = $targetEntityId;

        return $this;
    }

    public function getBeforeState(): ?string
    {
        return $this->beforeState;
    }

    public function setBeforeState(?string $beforeState): self
    {
        $this->beforeState = $beforeState;

        return $this;
    }

    public function getAfterState(): ?string
    {
        return $this->afterState;
    }

    public function setAfterState(?string $afterState): self
    {
        $this->afterState = $afterState;

        return $this;
    }

    public function getSourceRowRaw(): ?string
    {
        return $this->sourceRowRaw;
    }

    public function setSourceRowRaw(?string $sourceRowRaw): self
    {
        $this->sourceRowRaw = $sourceRowRaw;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getBeforeStateArray(): ?array
    {
        return $this->beforeState !== null ? json_decode($this->beforeState, true) : null;
    }

    public function getAfterStateArray(): ?array
    {
        return $this->afterState !== null ? json_decode($this->afterState, true) : null;
    }

    public function getSourceRowRawArray(): ?array
    {
        return $this->sourceRowRaw !== null ? json_decode($this->sourceRowRaw, true) : null;
    }
}
