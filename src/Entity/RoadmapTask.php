<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoadmapTaskRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Roadmap-Task — a CATEGORY / template (NOT a work item).
 *
 * ActionItems are filed under a RoadmapTask; the task also appears as a
 * capacity row in the roadmap. A task flagged `isReactiveReservation` models
 * standing daily-business effort (e.g. "Incident handling 1 PT/week") whose
 * "Ist" comes from the operational modules.
 */
#[ORM\Entity(repositoryClass: RoadmapTaskRepository::class)]
#[ORM\Table(name: 'roadmap_tasks')]
#[ORM\Index(name: 'idx_roadmap_task_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_roadmap_task_active', columns: ['is_active'])]
class RoadmapTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: RoadmapGroup::class)]
    #[ORM\JoinColumn(name: 'group_id', nullable: true, onDelete: 'SET NULL')]
    private ?RoadmapGroup $group = null;

    /** Default planned effort per week (also the reservation "Soll" for reactive tasks). */
    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $defaultPtPerWeek = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $recurring = false;

    /** 'all' (everyone / no team) | 'team' (only visibleTeams). */
    #[ORM\Column(length: 10, options: ['default' => 'team'])]
    #[Assert\Choice(choices: ['all', 'team'])]
    private string $visibility = 'team';

    /**
     * Teams that may see / plan this task when visibility = 'team'.
     *
     * @var Collection<int, Team>
     */
    #[ORM\ManyToMany(targetEntity: Team::class)]
    #[ORM\JoinTable(name: 'roadmap_task_teams')]
    #[ORM\JoinColumn(name: 'roadmap_task_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'team_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $visibleTeams;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Team $defaultTeam = null;

    /** Optional Annex-A / ISMS domain for Cl. 9.3 PT roll-up. */
    #[ORM\Column(length: 60, nullable: true)]
    private ?string $ismsDomain = null;

    /** Standing daily-business reservation (Ist comes from operational modules). */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isReactiveReservation = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSystemTask = false;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->visibleTeams = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getGroup(): ?RoadmapGroup
    {
        return $this->group;
    }

    public function setGroup(?RoadmapGroup $group): static
    {
        $this->group = $group;
        return $this;
    }

    public function getDefaultPtPerWeek(): ?string
    {
        return $this->defaultPtPerWeek;
    }

    public function setDefaultPtPerWeek(?string $defaultPtPerWeek): static
    {
        $this->defaultPtPerWeek = $defaultPtPerWeek;
        return $this;
    }

    public function isRecurring(): bool
    {
        return $this->recurring;
    }

    public function setRecurring(bool $recurring): static
    {
        $this->recurring = $recurring;
        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): static
    {
        $this->visibility = $visibility;
        return $this;
    }

    /** @return Collection<int, Team> */
    public function getVisibleTeams(): Collection
    {
        return $this->visibleTeams;
    }

    public function addVisibleTeam(Team $team): static
    {
        if (!$this->visibleTeams->contains($team)) {
            $this->visibleTeams->add($team);
        }
        return $this;
    }

    public function removeVisibleTeam(Team $team): static
    {
        $this->visibleTeams->removeElement($team);
        return $this;
    }

    public function getDefaultTeam(): ?Team
    {
        return $this->defaultTeam;
    }

    public function setDefaultTeam(?Team $defaultTeam): static
    {
        $this->defaultTeam = $defaultTeam;
        return $this;
    }

    public function getIsmsDomain(): ?string
    {
        return $this->ismsDomain;
    }

    public function setIsmsDomain(?string $ismsDomain): static
    {
        $this->ismsDomain = $ismsDomain;
        return $this;
    }

    public function isReactiveReservation(): bool
    {
        return $this->isReactiveReservation;
    }

    public function setIsReactiveReservation(bool $isReactiveReservation): static
    {
        $this->isReactiveReservation = $isReactiveReservation;
        return $this;
    }

    public function isSystemTask(): bool
    {
        return $this->isSystemTask;
    }

    public function setIsSystemTask(bool $isSystemTask): static
    {
        $this->isSystemTask = $isSystemTask;
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
}
