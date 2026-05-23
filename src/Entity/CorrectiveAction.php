<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CorrectiveActionStatus;
use App\Repository\CorrectiveActionRepository;
use App\Service\OwnerResolver;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// Junior-ISB-Audit C4-02 — Control linkage is intentionally multi-valued
// (ISO 27001 Cl. 10.1; A.8.15 + A.8.16 findings frequently touch >1 control).

/**
 * H-01: Corrective Action for an AuditFinding (ISO 27001 Clause 10.1).
 * Tracks the plan, execution and effectiveness review of a countermeasure.
 */
#[ORM\Entity(repositoryClass: CorrectiveActionRepository::class)]
#[ORM\Table(name: 'corrective_actions')]
#[ORM\Index(name: 'idx_ca_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_ca_finding', columns: ['finding_id'])]
#[ORM\Index(name: 'idx_ca_source_incident', columns: ['source_incident_id'])]
#[ORM\Index(name: 'idx_ca_source_change_request', columns: ['source_change_request_id'])]
#[ORM\Index(name: 'idx_ca_source_type', columns: ['source_type'])]
#[ORM\Index(name: 'idx_ca_status', columns: ['status'])]
class CorrectiveAction
{
    public const STATUS_PLANNED = 'planned';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    // Junior-ISB-Audit-2026-05-22 CAPA-Lifecycle: forced `completed → verified`
    // intermediate state — ISO 27001 Cl. 10.1 d.
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_VERIFIED_EFFECTIVE = 'verified_effective';
    public const STATUS_VERIFIED_INEFFECTIVE = 'verified_ineffective';

    public const ACTION_TYPE_CORRECTIVE = 'corrective';
    public const ACTION_TYPE_PREVENTIVE = 'preventive';
    public const ACTION_TYPE_IMPROVEMENT = 'improvement';

    /**
     * Junior-ISB-Audit-2026-05-22 M-07 / C2-05 — CAPA-Canonical-Process trigger source.
     * Identifies which closure-loop produced this CA (per ADR 2026-05-23).
     */
    public const SOURCE_TYPE_AUDIT_FINDING = 'audit_finding';
    public const SOURCE_TYPE_INCIDENT      = 'incident';
    public const SOURCE_TYPE_MANUAL        = 'manual';
    public const SOURCE_TYPE_CHANGE_REQUEST = 'change_request';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    /**
     * Junior-ISB-Audit-2026-05-22 M-07 / C2-05: now nullable. Was `nullable: false`
     * before CAPA-Canonical-Process (ADR 2026-05-23). A CorrectiveAction may now
     * be sourced from an Incident, manual entry, or change-request — see
     * `sourceType` + `sourceIncident`.
     */
    #[ORM\ManyToOne(targetEntity: AuditFinding::class, inversedBy: 'correctiveActions')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?AuditFinding $finding = null;

    /**
     * Junior-ISB-Audit-2026-05-22 C2-05 — Incident source link.
     * Set when this CA was auto-materialised from a high/critical Incident
     * with a non-empty root-cause by
     * {@see \App\Listener\AutoReactionCorrectiveActionListenerForIncident}.
     */
    #[ORM\ManyToOne(targetEntity: Incident::class)]
    #[ORM\JoinColumn(name: 'source_incident_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Incident $sourceIncident = null;

    /**
     * Junior-ISB-Audit-2026-05-22 M-07 Phase-1 — ChangeRequest source link.
     * Set when this CA was opened *because of* a routine ChangeRequest that
     * surfaced a nonconformity. Inverse direction of ChangeRequest.relatedCorrectiveAction
     * (which links operational executions back to a triggering CA).
     */
    #[ORM\ManyToOne(targetEntity: ChangeRequest::class)]
    #[ORM\JoinColumn(name: 'source_change_request_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?ChangeRequest $sourceChangeRequest = null;

    /**
     * Junior-ISB-Audit-2026-05-22 C2-05 — Source / trigger type.
     * One of {@see SOURCE_TYPE_AUDIT_FINDING}, {@see SOURCE_TYPE_INCIDENT},
     * {@see SOURCE_TYPE_MANUAL}, {@see SOURCE_TYPE_CHANGE_REQUEST}.
     */
    #[ORM\Column(name: 'source_type', length: 30, options: ['default' => self::SOURCE_TYPE_AUDIT_FINDING])]
    private string $sourceType = self::SOURCE_TYPE_AUDIT_FINDING;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rootCauseAnalysis = null;

    #[ORM\Column(length: 30)]
    private string $status = self::STATUS_PLANNED;

    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    /**
     * Action type — ISO 27001 §10.1+§10.2.
     * Values: corrective | preventive | improvement (proactive variant)
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $actionType = null;

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

    /**
     * S3 P0-32 — Pflicht-Beleg der Wirksamkeitsbewertung (ISO 27001 Cl. 10.1).
     * Wird beim Transition completed → verified_* zwingend gefordert.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $effectivenessEvidence = null;

    /**
     * S3 P0-32 — Verifier (Benutzer, der die Wirksamkeit bestätigt/widerlegt hat).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'verified_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $verifiedBy = null;

    /**
     * S3 P0-32 — Timestamp der Wirksamkeitsbewertung (immutable).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $verifiedAt = null;

    /**
     * S3 P0-30 — Selbstreferenz zur unwirksamen Vorgänger-CAPA.
     * Bildet die Maßnahmen-Kette für den Audit-Trail (Cl. 10.1 b).
     */
    #[ORM\ManyToOne(targetEntity: CorrectiveAction::class)]
    #[ORM\JoinColumn(name: 'previous_capa_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?CorrectiveAction $previousCapa = null;

    /**
     * Junior-ISB-Audit C4-02 — multi-control linkage.
     *
     * ISO 27001 Cl. 10.1: A finding (and the corrective action that
     * remediates it) frequently spans multiple Annex-A controls — a
     * logging gap typically hits A.8.15 + A.8.16; a vendor-onboarding gap
     * typically hits A.5.19 + A.5.20 + A.5.21. The previous singular
     * `relatedControl` slot silently forced the user to drop information.
     *
     * @var Collection<int, Control>
     */
    #[ORM\ManyToMany(targetEntity: Control::class)]
    #[ORM\JoinTable(name: 'corrective_action_controls')]
    #[ORM\JoinColumn(name: 'corrective_action_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'control_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $relatedControls;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->responsibleDeputyPersons = new ArrayCollection();
        $this->relatedControls = new ArrayCollection();
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

    public function getSourceIncident(): ?Incident
    {
        return $this->sourceIncident;
    }

    public function setSourceIncident(?Incident $incident): static
    {
        $this->sourceIncident = $incident;
        return $this;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function setSourceType(string $sourceType): static
    {
        $this->sourceType = $sourceType;
        return $this;
    }

    // Junior-ISB-Audit-2026-05-22 M-07 Phase-1
    public function getSourceChangeRequest(): ?ChangeRequest
    {
        return $this->sourceChangeRequest;
    }

    // Junior-ISB-Audit-2026-05-22 M-07 Phase-1
    public function setSourceChangeRequest(?ChangeRequest $changeRequest): static
    {
        $this->sourceChangeRequest = $changeRequest;
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

    public function setStatus(CorrectiveActionStatus|string $status): static
    {
        // Accept both enum and string so new code can pass the typed enum while
        // existing string-passing callers keep working unchanged.
        $this->status = is_string($status) ? $status : $status->value;
        return $this;
    }

    /** Typed status surface for enum-aware code. */
    public function getStatusEnum(): ?CorrectiveActionStatus
    {
        return CorrectiveActionStatus::tryFrom($this->status);
    }

    public function getLockVersion(): int
    {
        return $this->lockVersion;
    }

    public function getActionType(): ?string
    {
        return $this->actionType;
    }

    public function setActionType(?string $actionType): static
    {
        $this->actionType = $actionType;
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
        // Junior-ISB-Audit-2026-05-22 CAPA-Lifecycle: `verified` joins the
        // post-completion set — once verification has opened, the operational
        // due-date is irrelevant (Cl. 10.1 d evidence-review owns the SLA).
        if ($this->plannedCompletionDate === null
            || $this->status === self::STATUS_COMPLETED
            || $this->status === self::STATUS_VERIFIED
            || $this->status === self::STATUS_VERIFIED_EFFECTIVE
            || $this->status === self::STATUS_VERIFIED_INEFFECTIVE) {
            return false;
        }
        return $this->plannedCompletionDate < new DateTimeImmutable();
    }

    public function getEffectivenessEvidence(): ?string
    {
        return $this->effectivenessEvidence;
    }

    public function setEffectivenessEvidence(?string $evidence): static
    {
        $this->effectivenessEvidence = $evidence;
        return $this;
    }

    public function getVerifiedBy(): ?User
    {
        return $this->verifiedBy;
    }

    public function setVerifiedBy(?User $user): static
    {
        $this->verifiedBy = $user;
        return $this;
    }

    public function getVerifiedAt(): ?DateTimeInterface
    {
        return $this->verifiedAt;
    }

    public function setVerifiedAt(?DateTimeInterface $verifiedAt): static
    {
        $this->verifiedAt = $verifiedAt;
        return $this;
    }

    public function getPreviousCapa(): ?CorrectiveAction
    {
        return $this->previousCapa;
    }

    public function setPreviousCapa(?CorrectiveAction $previousCapa): static
    {
        $this->previousCapa = $previousCapa;
        return $this;
    }

    /**
     * S3 P0-30 — Convenience accessor: did Cl. 10.1 b kick in for this CAPA?
     * If true, the show-view surfaces a banner offering to create a Folge-CAPA.
     */
    public function isVerifiedIneffective(): bool
    {
        return $this->status === self::STATUS_VERIFIED_INEFFECTIVE;
    }

    /**
     * Junior-ISB-Audit C4-02 — related controls (M2M, ISO 27001 Cl. 10.1).
     *
     * @return Collection<int, Control>
     */
    public function getRelatedControls(): Collection
    {
        return $this->relatedControls;
    }

    public function addRelatedControl(Control $control): static
    {
        if (!$this->relatedControls->contains($control)) {
            $this->relatedControls->add($control);
        }
        return $this;
    }

    public function removeRelatedControl(Control $control): static
    {
        $this->relatedControls->removeElement($control);
        return $this;
    }

    /**
     * S3 P0-31 — Cl. 9.1 reminder window check.
     * True if the entity has an effectivenessReviewDate within the next $days
     * and is still in `completed` (i.e. not yet verified).
     */
    public function isEffectivenessReviewDueWithin(int $days): bool
    {
        if ($this->effectivenessReviewDate === null) {
            return false;
        }
        if ($this->status !== self::STATUS_COMPLETED) {
            return false;
        }
        $threshold = (new DateTimeImmutable())->modify("+{$days} days");
        return $this->effectivenessReviewDate <= $threshold;
    }
}
