<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActionItemRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * ActionItem (UI "Maßnahme") — the central work item of the KVP/PDCA process.
 *
 * Named `ActionItem` (not `Measure`) to avoid collision with ISO "measures" /
 * Annex-A controls and the existing CorrectiveAction. It is the universal
 * collector hub: native items plus auto-converted items from the ~16 source
 * modules (provenance via {@see ActionItemReference}, which is an association,
 * NOT a mirror — the source stays its own source-of-truth).
 *
 * Status is a plain string with a fixed transition matrix enforced by
 * ActionItemStatusService (no Doctrine enumType — avoids backup-restore enum
 * round-trip issues). Effort here is an estimate; the concrete KW distribution
 * lives in the roadmap.
 */
#[ORM\Entity(repositoryClass: ActionItemRepository::class)]
#[ORM\Table(name: 'action_items')]
#[ORM\Index(name: 'idx_action_item_tenant_status', columns: ['tenant_id', 'status'])]
#[ORM\Index(name: 'idx_action_item_tenant_due', columns: ['tenant_id', 'due_date'])]
#[ORM\Index(name: 'idx_action_item_task', columns: ['roadmap_task_id'])]
class ActionItem
{
    public const string STATUS_OPEN = 'open';
    public const string STATUS_PLANNED = 'planned';
    public const string STATUS_IN_PROGRESS = 'in_progress';
    public const string STATUS_DONE = 'done';
    public const string STATUS_DISMISSED = 'dismissed';

    /** @var list<string> */
    public const array STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_PLANNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_DONE,
        self::STATUS_DISMISSED,
    ];

    public const string ORIGIN_INTERNAL = 'internal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $title = null;

    /** Provenance badge: 'internal' (native KVP) or a source-adapter slug. */
    #[ORM\Column(length: 40, options: ['default' => self::ORIGIN_INTERNAL])]
    private string $origin = self::ORIGIN_INTERNAL;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $responsibleUser = null;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Person $responsiblePerson = null;

    /** @var Collection<int, Team> */
    #[ORM\ManyToMany(targetEntity: Team::class)]
    #[ORM\JoinTable(name: 'action_item_teams')]
    #[ORM\JoinColumn(name: 'action_item_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'team_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $teams;

    /**
     * Geltungsbereich — chosen scope labels (free tags backed by the tenant's
     * configurable scope list in PlanningSettings).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes = [];

    #[ORM\ManyToOne(targetEntity: RoadmapTask::class)]
    #[ORM\JoinColumn(name: 'roadmap_task_id', nullable: true, onDelete: 'SET NULL')]
    private ?RoadmapTask $roadmapTask = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?DateTimeImmutable $dueDate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $plannedEffortPt = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_OPEN])]
    #[Assert\Choice(choices: self::STATUSES)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $completedAt = null;

    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $evidenceDocument = null;

    /** null = one-off; else materialise a follow-up this many months after completion. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Positive]
    private ?int $recurrenceMonths = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'next_action_item_id', nullable: true, onDelete: 'SET NULL')]
    private ?ActionItem $nextActionItem = null;

    /** @var Collection<int, ActionItemReference> */
    #[ORM\OneToMany(mappedBy: 'actionItem', targetEntity: ActionItemReference::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $references;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->teams = new ArrayCollection();
        $this->references = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function setOrigin(string $origin): static
    {
        $this->origin = $origin;
        return $this;
    }

    public function getResponsibleUser(): ?User
    {
        return $this->responsibleUser;
    }

    public function setResponsibleUser(?User $responsibleUser): static
    {
        $this->responsibleUser = $responsibleUser;
        return $this;
    }

    public function getResponsiblePerson(): ?Person
    {
        return $this->responsiblePerson;
    }

    public function setResponsiblePerson(?Person $responsiblePerson): static
    {
        $this->responsiblePerson = $responsiblePerson;
        return $this;
    }

    /** @return Collection<int, Team> */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addTeam(Team $team): static
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
        }
        return $this;
    }

    public function removeTeam(Team $team): static
    {
        $this->teams->removeElement($team);
        return $this;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** @param list<string> $scopes */
    public function setScopes(array $scopes): static
    {
        $this->scopes = array_values($scopes);
        return $this;
    }

    public function getRoadmapTask(): ?RoadmapTask
    {
        return $this->roadmapTask;
    }

    public function setRoadmapTask(?RoadmapTask $roadmapTask): static
    {
        $this->roadmapTask = $roadmapTask;
        return $this;
    }

    public function getDueDate(): ?DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    public function getPlannedEffortPt(): ?string
    {
        return $this->plannedEffortPt;
    }

    public function setPlannedEffortPt(?string $plannedEffortPt): static
    {
        $this->plannedEffortPt = $plannedEffortPt;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Direct setter — call sites MUST go through ActionItemStatusService::transition()
     * which enforces the transition matrix + audit trail. Kept public for the
     * service + form hydration only.
     */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_DONE || $this->status === self::STATUS_DISMISSED;
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

    public function getEvidenceDocument(): ?Document
    {
        return $this->evidenceDocument;
    }

    public function setEvidenceDocument(?Document $evidenceDocument): static
    {
        $this->evidenceDocument = $evidenceDocument;
        return $this;
    }

    public function getRecurrenceMonths(): ?int
    {
        return $this->recurrenceMonths;
    }

    public function setRecurrenceMonths(?int $recurrenceMonths): static
    {
        $this->recurrenceMonths = $recurrenceMonths;
        return $this;
    }

    public function getNextActionItem(): ?ActionItem
    {
        return $this->nextActionItem;
    }

    public function setNextActionItem(?ActionItem $nextActionItem): static
    {
        $this->nextActionItem = $nextActionItem;
        return $this;
    }

    /** @return Collection<int, ActionItemReference> */
    public function getReferences(): Collection
    {
        return $this->references;
    }

    public function addReference(ActionItemReference $reference): static
    {
        if (!$this->references->contains($reference)) {
            $this->references->add($reference);
            $reference->setActionItem($this);
        }
        return $this;
    }

    public function removeReference(ActionItemReference $reference): static
    {
        if ($this->references->removeElement($reference)) {
            if ($reference->getActionItem() === $this) {
                $reference->setActionItem(null);
            }
        }
        return $this;
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

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
