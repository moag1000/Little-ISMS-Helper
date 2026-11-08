<?php

declare(strict_types=1);

namespace App\Entity;

use DateTimeInterface;
use DateTimeImmutable;
use App\Entity\Tenant;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Enum\ISMSObjectiveStatus;
use App\Repository\ISMSObjectiveRepository;
use App\State\TenantAwareStateProcessor;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use App\Service\OwnerResolver;
use App\Util\Cadence;
use App\Util\LegalPeriod;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Validator\Constraint\SameTenantOwner;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Entity(repositoryClass: ISMSObjectiveRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Get(security: "is_granted('API_VIEW', object)"),
        new Post(securityPostDenormalize: "is_granted('API_CREATE', object)"),
        new Put(security: "is_granted('API_EDIT', object)"),
        new Patch(security: "is_granted('API_EDIT', object)"),
        new Delete(security: "is_granted('API_DELETE', object)"),
    ],
    normalizationContext: ['groups' => ['isms_objective:read']],
    denormalizationContext: ['groups' => ['isms_objective:write']],
    processor: TenantAwareStateProcessor::class
)]
class ISMSObjective
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['isms_objective:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\NotBlank]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\NotBlank]
    private ?string $description = null;

    #[ORM\Column(length: 100)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['availability', 'confidentiality', 'integrity', 'compliance', 'risk_management', 'incident_response', 'awareness', 'continual_improvement'])]
    private ?string $category = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $measurableIndicators = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $targetValue = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $currentValue = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $unit = null;

    /**
     * ISO 27001 §6.2.b — wer fuer Messung verantwortlich ist.
     * Often overlaps with responsiblePerson but kept separate to allow
     * "objective owner" vs "data steward" split.
     */
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $responsibleForMeasurement = null;

    /**
     * ISO 27001 6.2 / 9.1: the person who measures (structured; the free text
     * above is legacy and only shown while still filled). Receives the
     * measurement deadline ({@see \App\Service\Deadline\Source\ObjectiveMeasurementSource}).
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'measurement_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[SameTenantOwner]
    private ?Person $measurementPerson = null;

    /**
     * ISO 27001 9.1 c/d: when the current value was last measured. Set when the
     * value changes or when the measurement is confirmed; the next measurement
     * is due one cadence later.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Groups(['isms_objective:read'])]
    private ?DateTimeImmutable $lastMeasuredAt = null;

    /**
     * ISO 27001 §6.2.b — wann gemessen wird.
     */
    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Choice(choices: ['daily', 'weekly', 'monthly', 'quarterly', 'biannually', 'annually', 'on_event'])]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $measurementFrequency = null;

    /**
     * ISO 27001 §6.2.b — wie gemessen wird (z.B. "Auswertung Audit-Logs",
     * "KPI aus Dashboard", "Manuelles Review im Quartals-Meeting").
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $measurementMethod = null;

    /**
     * Pattern A dual-state: preferred structured owner. Falls back to string responsiblePerson.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'responsible_person_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[SameTenantOwner]
    private ?User $responsiblePersonUser = null;

    /**
     * Person-based responsible person: for contacts without a system login.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'responsible_person_ref_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[SameTenantOwner]
    private ?Person $responsiblePersonRef = null;

    /**
     * Deputies / Vertretung — n additional Persons sharing responsibility.
     *
     * @var Collection<int, Person>
     */
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'isms_objective_responsible_deputy')]
    #[ORM\JoinColumn(name: 'objective_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $responsibleDeputyPersons;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\NotBlank]
    private ?DateTimeInterface $targetDate = null;

    /**
     * Optional KPI-catalog key: the current value is then computed instead of
     * maintained by hand ({@see \App\Service\Kpi\ObjectiveKpiProgress}).
     */
    #[ORM\Column(length: 64, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    #[Assert\Length(max: 64)]
    private ?string $kpiKey = null;

    /** Optional scope of a location KPI: one site; null = the whole organisation. */
    #[ORM\ManyToOne(targetEntity: Location::class)]
    #[ORM\JoinColumn(name: 'kpi_location_id', nullable: true, onDelete: 'SET NULL')]
    private ?Location $kpiLocation = null;

    #[ORM\Column(length: 50)]
    #[Groups(['isms_objective:read'])]
    #[Assert\Choice(choices: ['not_started', 'in_progress', 'achieved', 'delayed', 'cancelled'])]
    private string $status = 'in_progress';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['isms_objective:read', 'isms_objective:write'])]
    private ?string $progressNotes = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['isms_objective:read'])]
    private ?DateTimeInterface $achievedDate = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['isms_objective:read'])]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['isms_objective:read'])]
    private ?DateTimeInterface $updatedAt = null;

    
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    /**
     * Optimistic locking version — Lifecycle X.1.
     * Prevents concurrent status-transition conflicts (409 response).
     */
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

public function __construct()
    {
        $this->responsibleDeputyPersons = new ArrayCollection();
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): static
    {
        $this->category = $category;
        return $this;
    }

    public function getMeasurableIndicators(): ?string
    {
        return $this->measurableIndicators;
    }

    public function setMeasurableIndicators(?string $measurableIndicators): static
    {
        $this->measurableIndicators = $measurableIndicators;
        return $this;
    }

    public function getTargetValue(): ?string
    {
        return $this->targetValue;
    }

    public function setTargetValue(?string $targetValue): static
    {
        $this->targetValue = $targetValue;
        return $this;
    }

    public function getCurrentValue(): ?string
    {
        return $this->currentValue;
    }

    public function setCurrentValue(?string $currentValue): static
    {
        if ($currentValue !== null && $currentValue !== '' && $currentValue !== $this->currentValue) {
            $this->lastMeasuredAt = new DateTimeImmutable('today');
        }
        $this->currentValue = $currentValue;
        return $this;
    }

    public function getMeasurementPerson(): ?Person
    {
        return $this->measurementPerson;
    }

    public function setMeasurementPerson(?Person $measurementPerson): static
    {
        $this->measurementPerson = $measurementPerson;
        return $this;
    }

    /** Who measures: the person, else the legacy free text, else the objective's owner. */
    public function getEffectiveResponsibleForMeasurement(): ?string
    {
        $legacy = trim((string) $this->responsibleForMeasurement);

        return $this->measurementPerson?->getFullName()
            ?? ($legacy !== '' ? $legacy : $this->getEffectiveResponsiblePerson());
    }

    /**
     * ISO 27001 9.1: next manual measurement — last measurement (or creation)
     * plus the cadence. Null for KPI-linked objectives (the tool measures),
     * finished ones and cadences without a month interval.
     */
    public function getNextMeasurementDue(): ?DateTimeImmutable
    {
        $months = Cadence::months($this->measurementFrequency);
        $since = $this->lastMeasuredAt ?? $this->createdAt;
        if ($months === null || $this->kpiKey !== null || $since === null
            || in_array($this->status, ['achieved', 'cancelled'], true)) {
            return null;
        }

        return LegalPeriod::addMonths(DateTimeImmutable::createFromInterface($since), $months);
    }

    public function getLastMeasuredAt(): ?DateTimeImmutable
    {
        return $this->lastMeasuredAt;
    }

    public function setLastMeasuredAt(?DateTimeImmutable $lastMeasuredAt): static
    {
        $this->lastMeasuredAt = $lastMeasuredAt;
        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(?string $unit): static
    {
        $this->unit = $unit;
        return $this;
    }

    public function getKpiKey(): ?string
    {
        return $this->kpiKey;
    }

    public function setKpiKey(?string $kpiKey): static
    {
        $this->kpiKey = $kpiKey !== '' ? $kpiKey : null;

        return $this;
    }

    public function getKpiLocation(): ?Location
    {
        return $this->kpiLocation;
    }

    public function setKpiLocation(?Location $kpiLocation): static
    {
        $this->kpiLocation = $kpiLocation;

        return $this;
    }

    public function getTargetDate(): ?DateTimeInterface
    {
        return $this->targetDate;
    }

    public function setTargetDate(?DateTimeInterface $targetDate): static
    {
        $this->targetDate = $targetDate;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(ISMSObjectiveStatus|string $status): static
    {
        // Accept both enum and string so new code can pass the typed enum while
        // existing string-passing callers keep working unchanged.
        $this->status = is_string($status) ? $status : $status->value;
        return $this;
    }

    /** Typed status surface for enum-aware code. */
    public function getStatusEnum(): ?ISMSObjectiveStatus
    {
        return ISMSObjectiveStatus::tryFrom($this->status);
    }

    public function getProgressNotes(): ?string
    {
        return $this->progressNotes;
    }

    public function setProgressNotes(?string $progressNotes): static
    {
        $this->progressNotes = $progressNotes;
        return $this;
    }

    public function getAchievedDate(): ?DateTimeInterface
    {
        return $this->achievedDate;
    }

    public function setAchievedDate(?DateTimeInterface $achievedDate): static
    {
        $this->achievedDate = $achievedDate;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    #[Groups(['isms_objective:read'])]
    public function getProgressPercentage(): int
    {
        if ($this->targetValue && $this->currentValue && (float)$this->targetValue > 0) {
            return (int)(((float)$this->currentValue / (float)$this->targetValue) * 100);
        }
        return 0;
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

    public function getResponsibleForMeasurement(): ?string
    {
        return $this->responsibleForMeasurement;
    }

    public function setResponsibleForMeasurement(?string $responsibleForMeasurement): static
    {
        $this->responsibleForMeasurement = $responsibleForMeasurement;
        return $this;
    }

    public function getMeasurementFrequency(): ?string
    {
        return $this->measurementFrequency;
    }

    public function setMeasurementFrequency(?string $measurementFrequency): static
    {
        $this->measurementFrequency = $measurementFrequency;
        return $this;
    }

    public function getMeasurementMethod(): ?string
    {
        return $this->measurementMethod;
    }

    public function setMeasurementMethod(?string $measurementMethod): static
    {
        $this->measurementMethod = $measurementMethod;
        return $this;
    }

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function getResponsiblePersonUser(): ?User
    {
        return $this->responsiblePersonUser;
    }

    public function setResponsiblePersonUser(?User $responsiblePersonUser): static
    {
        $this->responsiblePersonUser = $responsiblePersonUser;
        return $this;
    }

    public function getResponsiblePersonRef(): ?Person
    {
        return $this->responsiblePersonRef;
    }

    public function setResponsiblePersonRef(?Person $responsiblePersonRef): static
    {
        $this->responsiblePersonRef = $responsiblePersonRef;
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
     * Effective responsiblePerson: prefer responsiblePersonUser.fullName,
     * then responsiblePersonRef (Person).
     */
    #[Groups(['isms_objective:read'])]
    #[SerializedName('responsiblePerson')]
    public function getEffectiveResponsiblePerson(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->responsiblePersonUser,
            $this->responsiblePersonRef,
        );
    }

    /**
     * Full responsible-person roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllResponsiblePersons(): array
    {
        return OwnerResolver::resolveAll(
            $this->responsiblePersonUser,
            $this->responsiblePersonRef,
            $this->responsibleDeputyPersons,
        );
    }

    /**
     * ISO 27001 6.2: an objective needs a responsible party (user or person).
     * Enforced here (not only in the form) so API / service writes are covered too.
     */
    #[Assert\Callback]
    public function validateResponsibleParty(ExecutionContextInterface $context): void
    {
        if ($this->responsiblePersonUser === null && $this->responsiblePersonRef === null) {
            $context->buildViolation('objective.validation.owner_required')
                ->atPath('responsiblePersonUser')
                ->addViolation();
        }
    }
}
