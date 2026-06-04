<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AnswerLibraryEntryRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * F44 — Inbound Security-Questionnaire Answer Library.
 *
 * Stores reusable answers to common security-questionnaire questions (RFP,
 * customer due-diligence, audit responses). Once answered, a question can be
 * retrieved and "used" again — each reuse is recorded via FteRecorderService
 * so it feeds the ROI/FTE counter (F11).
 *
 * Categories mirror the ISO 27001 control families to allow scoped lookups:
 *   access_control · encryption · bcm · privacy · incident_management ·
 *   physical_security · supplier_management · risk_management · general
 *
 * ISO 27001 Cl. 7.5 — dokumentierte Information.
 */
#[ORM\Entity(repositoryClass: AnswerLibraryEntryRepository::class)]
#[ORM\Table(name: 'answer_library_entry')]
#[ORM\Index(name: 'idx_ale_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_ale_category', columns: ['category'])]
#[ORM\Index(name: 'idx_ale_tenant_category', columns: ['tenant_id', 'category'])]
#[ORM\Index(name: 'idx_ale_use_count', columns: ['use_count'])]
#[ORM\HasLifecycleCallbacks]
class AnswerLibraryEntry
{
    // ── Valid category constants ──────────────────────────────────────────────
    public const string CATEGORY_ACCESS_CONTROL    = 'access_control';
    public const string CATEGORY_ENCRYPTION        = 'encryption';
    public const string CATEGORY_BCM               = 'bcm';
    public const string CATEGORY_PRIVACY           = 'privacy';
    public const string CATEGORY_INCIDENT          = 'incident_management';
    public const string CATEGORY_PHYSICAL          = 'physical_security';
    public const string CATEGORY_SUPPLIER          = 'supplier_management';
    public const string CATEGORY_RISK              = 'risk_management';
    public const string CATEGORY_GENERAL           = 'general';

    public const array VALID_CATEGORIES = [
        self::CATEGORY_ACCESS_CONTROL,
        self::CATEGORY_ENCRYPTION,
        self::CATEGORY_BCM,
        self::CATEGORY_PRIVACY,
        self::CATEGORY_INCIDENT,
        self::CATEGORY_PHYSICAL,
        self::CATEGORY_SUPPLIER,
        self::CATEGORY_RISK,
        self::CATEGORY_GENERAL,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /** The security-questionnaire question text. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 2000)]
    private string $question = '';

    /** The prepared answer text (may contain %PLACEHOLDER% variables). */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $answer = '';

    /**
     * Control-family category for scoped search.
     * One of CATEGORY_* constants.
     */
    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: AnswerLibraryEntry::VALID_CATEGORIES)]
    private string $category = self::CATEGORY_GENERAL;

    /**
     * Comma-separated or JSON-stored tags for cross-cutting search.
     * Stored as simple array of strings (JSON column).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $tags = [];

    /** Timestamp of the last "use/copy" action for freshness indicator. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $lastUsedAt = null;

    /** Number of times this entry has been reused (used/copied). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0, 'unsigned' => true])]
    private int $useCount = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    /**
     * Optimistic-lock version counter (P-4b lifecycle baseline).
     * Required for entities involved in concurrent update scenarios.
     */
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    // ── Getters / Setters ─────────────────────────────────────────────────────

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

    public function getQuestion(): string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = $question;
        return $this;
    }

    public function getAnswer(): string
    {
        return $this->answer;
    }

    public function setAnswer(string $answer): static
    {
        $this->answer = $answer;
        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;
        return $this;
    }

    /** @return list<string> */
    public function getTags(): array
    {
        return $this->tags;
    }

    /** @param list<string> $tags */
    public function setTags(array $tags): static
    {
        $this->tags = array_values(array_unique(array_filter($tags, static fn (string $s): bool => $s !== '')));
        return $this;
    }

    public function getLastUsedAt(): ?DateTimeInterface
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?DateTimeInterface $lastUsedAt): static
    {
        $this->lastUsedAt = $lastUsedAt;
        return $this;
    }

    public function getUseCount(): int
    {
        return $this->useCount;
    }

    public function setUseCount(int $useCount): static
    {
        $this->useCount = max(0, $useCount);
        return $this;
    }

    public function incrementUseCount(): static
    {
        ++$this->useCount;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }
}
