<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccessReviewItemRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Access Review Item — per-user/role decision row for a UAR campaign.
 *
 * Mirrors TrainingParticipation: one row per in-scope user/role pair.
 * The reviewer records decision = approved | revoked | escalated per item.
 * Every decision is HMAC-chained through AuditLogger (ISO 27001 A.5.18 evidence).
 *
 * Tenant isolation: tenant_id mirrors campaign.tenant_id.
 *
 * @see AccessReviewCampaign for the parent campaign header
 */
#[ORM\Entity(repositoryClass: AccessReviewItemRepository::class)]
#[ORM\Table(name: 'access_review_item')]
#[ORM\UniqueConstraint(
    name: 'uq_access_review_item_campaign_user_role',
    columns: ['campaign_id', 'subject_user_id', 'reviewed_role'],
)]
#[ORM\Index(name: 'idx_ari_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_ari_campaign', columns: ['campaign_id'])]
#[ORM\Index(name: 'idx_ari_subject_user', columns: ['subject_user_id'])]
#[ORM\Index(name: 'idx_ari_decision', columns: ['decision'])]
class AccessReviewItem
{
    public const DECISION_PENDING   = 'pending';
    public const DECISION_APPROVED  = 'approved';
    public const DECISION_REVOKED   = 'revoked';
    public const DECISION_ESCALATED = 'escalated';

    public const ALLOWED_DECISIONS = [
        self::DECISION_PENDING,
        self::DECISION_APPROVED,
        self::DECISION_REVOKED,
        self::DECISION_ESCALATED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: AccessReviewCampaign::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AccessReviewCampaign $campaign = null;

    /**
     * The user whose access is being reviewed.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'subject_user_id', nullable: false, onDelete: 'CASCADE')]
    private ?User $subjectUser = null;

    /**
     * The Symfony role string being reviewed for this user (e.g. 'ROLE_ADMIN').
     * Stored as a string so the row survives role-key renames without a FK
     * cascade — the role name at review-time is what matters for the audit trail.
     */
    #[ORM\Column(name: 'reviewed_role', length: 100)]
    #[Assert\NotBlank]
    private ?string $reviewedRole = null;

    /**
     * Recertification decision: pending | approved | revoked | escalated.
     * pending   → not yet reviewed.
     * approved  → access confirmed as appropriate.
     * revoked   → access to be withdrawn (triggers downstream action).
     * escalated → referred to higher authority (e.g. CISO).
     */
    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: self::ALLOWED_DECISIONS)]
    private string $decision = self::DECISION_PENDING;

    /**
     * Who recorded the decision (reviewer). NULL = not yet decided.
     * onDelete: SET NULL so audit rows survive reviewer's account deletion.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $decidedAt = null;

    /**
     * Optional free-text justification for the decision (e.g. reason for revoke).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
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

    public function getCampaign(): ?AccessReviewCampaign
    {
        return $this->campaign;
    }

    public function setCampaign(?AccessReviewCampaign $campaign): static
    {
        $this->campaign = $campaign;
        return $this;
    }

    public function getSubjectUser(): ?User
    {
        return $this->subjectUser;
    }

    public function setSubjectUser(?User $subjectUser): static
    {
        $this->subjectUser = $subjectUser;
        return $this;
    }

    public function getReviewedRole(): ?string
    {
        return $this->reviewedRole;
    }

    public function setReviewedRole(?string $reviewedRole): static
    {
        $this->reviewedRole = $reviewedRole;
        return $this;
    }

    public function getDecision(): string
    {
        return $this->decision;
    }

    public function setDecision(string $decision): static
    {
        if (!in_array($decision, self::ALLOWED_DECISIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid AccessReviewItem decision "%s". Allowed: %s',
                $decision,
                implode(', ', self::ALLOWED_DECISIONS),
            ));
        }
        $this->decision = $decision;
        return $this;
    }

    public function isPending(): bool
    {
        return $this->decision === self::DECISION_PENDING;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): static
    {
        $this->decidedBy = $decidedBy;
        return $this;
    }

    public function getDecidedAt(): ?DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;
        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
}
