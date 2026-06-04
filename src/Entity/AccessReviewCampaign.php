<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AccessReviewCampaignRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Access Review Campaign — campaign header for User-Access-Recertification (UAR).
 *
 * ISO 27001 A.5.18 / A.8.2 — periodic recertification of who holds which access.
 * NIS2 Art. 21(2)(e) — identity and access management as mandatory security measure.
 * BSI ORP.4 — Identitäts- und Berechtigungsmanagement.
 *
 * Pattern: mirrors Training (campaign-header) + TrainingParticipation (per-user rows).
 * One campaign generates one AccessReviewItem per in-scope user/role assignment.
 * The reviewer approves or revokes each item; decisions are HMAC-chained via AuditLogger.
 *
 * @see AccessReviewItem for per-decision rows
 * @see App\Service\AccessReviewCampaignService for creation + close logic
 */
#[ORM\Entity(repositoryClass: AccessReviewCampaignRepository::class)]
#[ORM\Table(name: 'access_review_campaign')]
#[ORM\Index(name: 'idx_arc_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_arc_status', columns: ['status'])]
#[ORM\Index(name: 'idx_arc_due_date', columns: ['due_date'])]
class AccessReviewCampaign
{
    public const STATUS_OPEN   = 'open';
    public const STATUS_CLOSED = 'closed';

    public const SCOPE_ALL_USERS  = 'all_users';
    public const SCOPE_PRIVILEGED = 'privileged';

    public const ALLOWED_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
    ];

    public const ALLOWED_SCOPES = [
        self::SCOPE_ALL_USERS,
        self::SCOPE_PRIVILEGED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    /**
     * Scope determines which user/role assignments are recertified:
     *   all_users  → every active user/role pair in this tenant
     *   privileged → only users holding ROLE_ADMIN, ROLE_MANAGER, or ROLE_SUPER_ADMIN
     */
    #[ORM\Column(length: 24)]
    #[Assert\Choice(choices: self::ALLOWED_SCOPES)]
    private string $scope = self::SCOPE_ALL_USERS;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotNull]
    private ?DateTimeInterface $dueDate = null;

    #[ORM\Column(length: 16)]
    #[Assert\Choice(choices: self::ALLOWED_STATUSES)]
    private string $status = self::STATUS_OPEN;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /**
     * Optimistic-locking version field (Lifecycle Foundation P-4b).
     * Required for concurrent decision/close transitions.
     */
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    /**
     * @var Collection<int, AccessReviewItem>
     */
    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: AccessReviewItem::class, fetch: 'EXTRA_LAZY')]
    private Collection $items;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $closedAt = null;

    public function __construct()
    {
        $this->items     = new ArrayCollection();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): static
    {
        if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid AccessReviewCampaign scope "%s". Allowed: %s',
                $scope,
                implode(', ', self::ALLOWED_SCOPES),
            ));
        }
        $this->scope = $scope;
        return $this;
    }

    public function getDueDate(): ?DateTimeInterface
    {
        return $this->dueDate;
    }

    public function setDueDate(?DateTimeInterface $dueDate): static
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid AccessReviewCampaign status "%s". Allowed: %s',
                $status,
                implode(', ', self::ALLOWED_STATUSES),
            ));
        }
        $this->status = $status;
        return $this;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
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

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    /** @return Collection<int, AccessReviewItem> */
    public function getItems(): Collection
    {
        return $this->items ??= new ArrayCollection();
    }

    public function addItem(AccessReviewItem $item): static
    {
        if (!$this->getItems()->contains($item)) {
            $this->getItems()->add($item);
            $item->setCampaign($this);
        }
        return $this;
    }

    public function removeItem(AccessReviewItem $item): static
    {
        $this->getItems()->removeElement($item);
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getClosedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(?DateTimeImmutable $closedAt): static
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    // ── Derived helpers used in Twig ──────────────────────────────────────────

    /**
     * Count of pending items — safe for EXTRA_LAZY (issues one COUNT query).
     */
    public function getPendingCount(): int
    {
        $count = 0;
        foreach ($this->getItems() as $item) {
            if ($item->getDecision() === AccessReviewItem::DECISION_PENDING) {
                $count++;
            }
        }
        return $count;
    }

    public function getTotalCount(): int
    {
        return $this->getItems()->count();
    }
}
