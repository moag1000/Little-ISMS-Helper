<?php

declare(strict_types=1);

namespace App\Entity;

use App\Attribute\ApiReadOnlyEvidence;
use App\Enum\SuggestionOrigin;
use App\Enum\SuggestionStatus;
use App\Repository\SuggestionRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entry of the suggestion queue (Paket 5): something the tool proposes —
 * derived from the organisation's data, inherited, imported or written by a
 * language model — that a person accepts (optionally after editing the text)
 * or rejects with a reason. Nothing changes a record before that decision.
 *
 * `kind` names the App\Service\Suggestion\SuggestionKind that created the
 * entry and applies it; `dedupKey` keeps a rejected suggestion from coming
 * back with the same content (unique per organisation and kind).
 */
#[ORM\Entity(repositoryClass: SuggestionRepository::class)]
#[ORM\Table(name: 'suggestion')]
#[ORM\UniqueConstraint(name: 'uniq_suggestion_dedup', columns: ['tenant_id', 'kind', 'dedup_key'])]
#[ORM\Index(name: 'idx_suggestion_tenant_status', columns: ['tenant_id', 'status'])]
#[ORM\Index(name: 'idx_suggestion_subject', columns: ['subject_class', 'subject_id'])]
class Suggestion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(length: 80)]
    private string $kind;

    #[ORM\Column(length: 20, enumType: SuggestionOrigin::class)]
    private SuggestionOrigin $origin;

    /** FQCN of the record the suggestion is about; null = organisation-wide. */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $subjectClass = null;

    #[ORM\Column(nullable: true)]
    private ?int $subjectId = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: Types::TEXT)]
    private string $rationale;

    /** @var array<string, mixed> what the kind needs to apply the suggestion */
    #[ORM\Column(type: Types::JSON)]
    private array $payload = [];

    /** Text the person may edit before accepting (justification, description). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $editableText = null;

    #[ORM\Column(nullable: true)]
    private ?float $confidence = null;

    /** Language model that wrote the suggestion (origin ai), for the audit trail. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 64)]
    private string $dedupKey;

    #[ORM\Column(length: 20, enumType: SuggestionStatus::class)]
    private SuggestionStatus $status = SuggestionStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[ApiReadOnlyEvidence('suggestion queue accept/reject (S26)')]
    private ?DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[ApiReadOnlyEvidence('suggestion queue accept/reject (S26)')]
    private ?User $decidedBy = null;

    /** Comment on acceptance, reason on rejection, cause on supersession. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $decisionNote = null;

    /** Record created or changed by the acceptance. */
    #[ORM\Column(length: 150, nullable: true)]
    private ?string $resultClass = null;

    #[ORM\Column(nullable: true)]
    private ?int $resultId = null;

    public function __construct(Tenant $tenant, string $kind, SuggestionOrigin $origin, string $title, string $rationale, string $dedupKey)
    {
        $this->tenant = $tenant;
        $this->kind = $kind;
        $this->origin = $origin;
        $this->title = mb_substr($title, 0, 255);
        $this->rationale = $rationale;
        $this->dedupKey = $dedupKey;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getOrigin(): SuggestionOrigin
    {
        return $this->origin;
    }

    public function getSubjectClass(): ?string
    {
        return $this->subjectClass;
    }

    public function getSubjectId(): ?int
    {
        return $this->subjectId;
    }

    public function setSubject(?string $class, ?int $id): static
    {
        $this->subjectClass = $class;
        $this->subjectId = $id;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getRationale(): string
    {
        return $this->rationale;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /** @param array<string, mixed> $payload */
    public function setPayload(array $payload): static
    {
        $this->payload = $payload;
        return $this;
    }

    public function getEditableText(): ?string
    {
        return $this->editableText;
    }

    public function setEditableText(?string $editableText): static
    {
        $this->editableText = $editableText;
        return $this;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function setConfidence(?float $confidence): static
    {
        $this->confidence = $confidence === null ? null : max(0.0, min(1.0, $confidence));
        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model === null ? null : mb_substr($model, 0, 120);
        return $this;
    }

    public function getDedupKey(): string
    {
        return $this->dedupKey;
    }

    public function getStatus(): SuggestionStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === SuggestionStatus::Pending;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDecidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function getResultClass(): ?string
    {
        return $this->resultClass;
    }

    public function getResultId(): ?int
    {
        return $this->resultId;
    }

    /** A superseded proposal whose condition holds again comes back as pending. */
    public function reopen(string $title, string $rationale): static
    {
        $this->status = SuggestionStatus::Pending;
        $this->title = mb_substr($title, 0, 255);
        $this->rationale = $rationale;
        $this->decidedAt = null;
        $this->decidedBy = null;
        $this->decisionNote = null;
        $this->createdAt = new DateTimeImmutable();

        return $this;
    }

    /** Records the decision; only the queue calls this (pending → final). */
    public function decide(SuggestionStatus $status, ?User $by, ?string $note, ?object $result = null): static
    {
        $this->status = $status;
        $this->decidedBy = $by;
        $this->decidedAt = new DateTimeImmutable();
        $this->decisionNote = $note !== null && trim($note) !== '' ? trim($note) : null;
        if ($result !== null && method_exists($result, 'getId')) {
            $this->resultClass = $result::class;
            $this->resultId = $result->getId();
        }
        return $this;
    }
}
