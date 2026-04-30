<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\CorrectiveActionRepository;
use App\Service\OwnerResolver;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * H-01: Corrective Action for an AuditFinding (ISO 27001 Clause 10.1).
 * Tracks the plan, execution and effectiveness review of a countermeasure.
 */
#[ORM\Entity(repositoryClass: CorrectiveActionRepository::class)]
#[ORM\Table(name: 'corrective_actions')]
#[ORM\Index(name: 'idx_ca_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_ca_finding', columns: ['finding_id'])]
#[ORM\Index(name: 'idx_ca_status', columns: ['status'])]
class CorrectiveAction
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VERIFIED_EFFECTIVE = 'verified_effective';
    public const STATUS_VERIFIED_INEFFECTIVE = 'verified_ineffective';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: AuditFinding::class, inversedBy: 'correctiveActions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?AuditFinding $finding = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rootCauseAnalysis = null;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PLANNED;

    /**
     * Responsible person (legacy User slot).
     * DB column kept as `responsible_person_id` for zero-data-loss rename.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'responsible_person_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $responsiblePersonUser = null;

    /**
     * Tri-State Person slot: responsible person as Person master-data record.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'responsible_person_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Person $responsiblePerson = null;

    /**
     * Deputy Persons for the responsible person slot.
     *
     * @var Collection<int, Person>
     */
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'ca_responsible_deputy')]
    #[ORM\JoinColumn(name: 'corrective_action_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $responsibleDeputyPersons;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $plannedCompletionDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $actualCompletionDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $effectivenessReviewDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $effectivenessNotes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->responsibleDeputyPersons = new ArrayCollection();
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

    public function getFinding(): ?AuditFinding
    {
        return $this->finding;
    }

    public function setFinding(?AuditFinding $finding): static
    {
        $this->finding = $finding;
        return $this;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getRootCauseAnalysis(): ?string
    {
        return $this->rootCauseAnalysis;
    }

    public function setRootCauseAnalysis(?string $rootCauseAnalysis): static
    {
        $this->rootCauseAnalysis = $rootCauseAnalysis;
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

    public function getResponsiblePersonUser(): ?User
    {
        return $this->responsiblePersonUser;
    }

    public function setResponsiblePersonUser(?User $user): static
    {
        $this->responsiblePersonUser = $user;
        return $this;
    }

    public function getResponsiblePerson(): ?Person
    {
        return $this->responsiblePerson;
    }

    public function setResponsiblePerson(?Person $person): static
    {
        $this->responsiblePerson = $person;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getResponsibleDeputyPersons(): Collection
    {
        return $this->responsibleDeputyPersons;
    }

    public function addResponsibleDeputyPerson(Person $person): static
    {
        if (!$this->responsibleDeputyPersons->contains($person)) {
            $this->responsibleDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeResponsibleDeputyPerson(Person $person): static
    {
        $this->responsibleDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective responsible person: prefer User, then Person, then null.
     */
    public function getEffectiveResponsiblePerson(): ?string
    {
        return OwnerResolver::resolveEffective($this->responsiblePersonUser, $this->responsiblePerson, null);
    }

    /**
     * All responsible persons (primary + deputies).
     *
     * @return list<string>
     */
    public function getAllResponsiblePersons(): array
    {
        return OwnerResolver::resolveAll(
            $this->responsiblePersonUser,
            $this->responsiblePerson,
            null,
            $this->responsibleDeputyPersons
        );
    }

    public function getPlannedCompletionDate(): ?DateTimeInterface
    {
        return $this->plannedCompletionDate;
    }

    public function setPlannedCompletionDate(?DateTimeInterface $date): static
    {
        $this->plannedCompletionDate = $date;
        return $this;
    }

    public function getActualCompletionDate(): ?DateTimeInterface
    {
        return $this->actualCompletionDate;
    }

    public function setActualCompletionDate(?DateTimeInterface $date): static
    {
        $this->actualCompletionDate = $date;
        return $this;
    }

    public function getEffectivenessReviewDate(): ?DateTimeInterface
    {
        return $this->effectivenessReviewDate;
    }

    public function setEffectivenessReviewDate(?DateTimeInterface $date): static
    {
        $this->effectivenessReviewDate = $date;
        return $this;
    }

    public function getEffectivenessNotes(): ?string
    {
        return $this->effectivenessNotes;
    }

    public function setEffectivenessNotes(?string $notes): static
    {
        $this->effectivenessNotes = $notes;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function isOverdue(): bool
    {
        if ($this->plannedCompletionDate === null
            || $this->status === self::STATUS_COMPLETED
            || $this->status === self::STATUS_VERIFIED_EFFECTIVE
            || $this->status === self::STATUS_VERIFIED_INEFFECTIVE) {
            return false;
        }
        return $this->plannedCompletionDate < new DateTimeImmutable();
    }
}
