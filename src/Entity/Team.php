<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Generic Team for resource planning (ISO 27001 Cl. 6.2 "wer" / Cl. 7.1 resources).
 *
 * Modelled on {@see CrisisTeam} and set up as a Doctrine JOINED-inheritance base
 * from day 1 (discriminator `team_kind`). A later migration can make
 * `CrisisTeam extends Team` by adding the `crisis_team` discriminator entry +
 * copying rows — no remapping. Until then this base carries only the shared,
 * non-BCM-specific fields.
 *
 * Membership is editable from BOTH sides (Team → members, Person → teams) — the
 * plain M:N join `team_members` carries it. A Team grants NO system rights; its
 * only effect is Roadmap-Task visibility.
 */
#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\Table(name: 'teams')]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'team_kind', type: 'string', length: 32)]
#[ORM\DiscriminatorMap(['team' => Team::class])]
#[ORM\Index(name: 'idx_team_active', columns: ['is_active'])]
#[ORM\Index(name: 'idx_team_tenant', columns: ['tenant_id'])]
class Team
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Free-form team type (aligned with CrisisTeam.teamType for later migration). */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $teamLead = null;

    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Person $teamLeadPerson = null;

    /**
     * Team members. Join-table name mirrors CrisisTeam.personMembers
     * (`crisis_team_persons`) structure so the later CrisisTeam→Team
     * migration can re-point with minimal churn.
     *
     * @var Collection<int, Person>
     */
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'team_members')]
    #[ORM\JoinColumn(name: 'team_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $members;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validUntil = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->members = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;
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

    public function getTeamLead(): ?User
    {
        return $this->teamLead;
    }

    public function setTeamLead(?User $teamLead): static
    {
        $this->teamLead = $teamLead;
        return $this;
    }

    public function getTeamLeadPerson(): ?Person
    {
        return $this->teamLeadPerson;
    }

    public function setTeamLeadPerson(?Person $teamLeadPerson): static
    {
        $this->teamLeadPerson = $teamLeadPerson;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(Person $person): static
    {
        if (!$this->members->contains($person)) {
            $this->members->add($person);
        }
        return $this;
    }

    public function removeMember(Person $person): static
    {
        $this->members->removeElement($person);
        return $this;
    }

    public function getMemberCount(): int
    {
        return $this->members->count();
    }

    public function getValidFrom(): ?DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(?DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;
        return $this;
    }

    public function getValidUntil(): ?DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?DateTimeImmutable $validUntil): static
    {
        $this->validUntil = $validUntil;
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
