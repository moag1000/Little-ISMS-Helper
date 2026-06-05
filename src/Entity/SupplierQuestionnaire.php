<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SupplierQuestionnaireRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F23 — outbound supplier security questionnaire (the counterpart to the F44
 * inbound Answer-Library).
 *
 * A tenant sends a questionnaire to a supplier via a signed public link; the
 * supplier answers it in an unauthenticated portal (no login). Only this token
 * surface is public — guarded by a constant-time token compare, mirroring the
 * F43 Trust-Center pattern.
 */
#[ORM\Entity(repositoryClass: SupplierQuestionnaireRepository::class)]
#[ORM\Table(name: 'supplier_questionnaires')]
class SupplierQuestionnaire
{
    public const string STATUS_DRAFT = 'draft';
    public const string STATUS_SENT = 'sent';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_REVIEWED = 'reviewed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Supplier $supplier = null;

    #[ORM\Column(length: 200)]
    private string $title = 'Security questionnaire';

    #[ORM\Column(name: 'public_token', length: 64, unique: true)]
    private string $publicToken;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    /**
     * Ordered list of questions: [{id, text}, ...].
     *
     * @var list<array{id: string, text: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $questions = [];

    /**
     * Supplier answers keyed by question id: {questionId: answerText}.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $answers = [];

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'sent_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $sentAt = null;

    #[ORM\Column(name: 'completed_at', type: 'datetime_immutable', nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function isOpenForResponse(): bool
    {
        return in_array($this->status, [self::STATUS_SENT], true);
    }

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

    public function getSupplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function setSupplier(?Supplier $supplier): static
    {
        $this->supplier = $supplier;
        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getPublicToken(): string
    {
        return $this->publicToken;
    }

    public function setPublicToken(string $publicToken): static
    {
        $this->publicToken = $publicToken;
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

    /**
     * @return list<array{id: string, text: string}>
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    /**
     * @param list<array{id: string, text: string}> $questions
     */
    public function setQuestions(array $questions): static
    {
        $this->questions = $questions;
        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getAnswers(): array
    {
        return $this->answers;
    }

    /**
     * @param array<string, string> $answers
     */
    public function setAnswers(array $answers): static
    {
        $this->answers = $answers;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?DateTimeImmutable $sentAt): static
    {
        $this->sentAt = $sentAt;
        return $this;
    }

    public function getCompletedAt(): ?DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?DateTimeImmutable $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }
}
