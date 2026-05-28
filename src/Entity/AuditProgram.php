<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditProgramRepository;
use App\Service\OwnerResolver;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * AuditProgram — ISO 19011 §5.4 documented Audit Programme.
 *
 * Container entity: 1 AuditProgram → N InternalAudits.
 * Lifecycle managed by `audit_program_lifecycle` Symfony Workflow.
 *
 * ISO 19011:2018 §5.4.2 — Programme objectives
 * ISO 19011:2018 §5.4.4 — Programme manager responsibilities
 * ISO 19011:2018 §5.4.5 — Programme resources (budget)
 * ISO 27001:2022 Cl. 9.2 — Internal audit programme
 */
#[ORM\Entity(repositoryClass: AuditProgramRepository::class)]
#[ORM\Table(name: 'audit_program')]
#[ORM\Index(name: 'idx_audit_program_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_audit_program_year', columns: ['year'])]
#[ORM\Index(name: 'idx_audit_program_status', columns: ['status'])]
class AuditProgram
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'audit_program.validation.name_required')]
    #[Assert\Length(max: 200, maxMessage: 'audit_program.validation.name_max_length')]
    private ?string $name = null;

    /**
     * Geschäftsjahr des Audit-Programms (ISO 19011 §5.4.1).
     */
    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\NotNull(message: 'audit_program.validation.year_required')]
    #[Assert\Range(min: 2000, max: 2100)]
    private ?int $year = null;

    /**
     * Anwendungsbereich des Programms (ISO 19011 §5.4.2 b — scope).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scope = null;

    /**
     * Programm-Ziele (ISO 19011 §5.4.2 a — objectives).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objectives = null;

    /**
     * Normen-Abdeckung (ISO 27001, BCM, DSGVO usw.).
     *
     * @var Collection<int, ComplianceFramework>
     */
    #[ORM\ManyToMany(targetEntity: ComplianceFramework::class)]
    #[ORM\JoinTable(name: 'audit_program_compliance_framework')]
    private Collection $frameworks;

    /**
     * Die geplanten Audits dieses Programms (1 Program → N InternalAudits).
     *
     * @var Collection<int, InternalAudit>
     */
    #[ORM\OneToMany(targetEntity: InternalAudit::class, mappedBy: 'program')]
    #[ORM\OrderBy(['plannedDate' => 'ASC'])]
    private Collection $audits;

    /**
     * Audit-Programm-Manager (ISO 19011 §5.4.4 — programme manager).
     * Preferred structured reference — falls back to responsiblePersonRef.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'responsible_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $responsiblePerson = null;

    /**
     * Externer Audit-Programm-Manager ohne App-Login (Stammdaten-Person).
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'responsible_person_ref_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Person $responsiblePersonRef = null;

    /**
     * Lifecycle-Status: draft → approved → active → completed → archived.
     * Managed exclusively by `audit_program_lifecycle` Symfony Workflow.
     * Do NOT call setStatus() directly — use LifecycleService::transition().
     */
    #[ORM\Column(length: 30)]
    #[Assert\Choice(
        choices: ['draft', 'approved', 'active', 'completed', 'archived'],
        message: 'audit_program.validation.status_invalid'
    )]
    private string $status = 'draft';

    /**
     * Freigabe durch (ISO 19011 §5.4.4 — programme manager authority).
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approved_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approvedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $approvedAt = null;

    /**
     * Ressourcen-Budget (ISO 19011 §5.4.5 — programme resources).
     * Stored as string to avoid currency/precision ambiguity.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $budget = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    /**
     * Optimistic-locking — required by Lifecycle Foundation P-4b.
     */
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    public function __construct()
    {
        $this->frameworks = new ArrayCollection();
        $this->audits     = new ArrayCollection();
        $this->createdAt  = new DateTimeImmutable();
        $this->year       = (int) (new DateTimeImmutable())->format('Y');
    }

    // ── Identifiers ────────────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    // ── Tenant ─────────────────────────────────────────────────────────────────

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(?Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    // ── Basic info ─────────────────────────────────────────────────────────────

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): static
    {
        $this->year = $year;
        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function getObjectives(): ?string
    {
        return $this->objectives;
    }

    public function setObjectives(?string $objectives): static
    {
        $this->objectives = $objectives;
        return $this;
    }

    // ── Frameworks (M2M) ───────────────────────────────────────────────────────

    /** @return Collection<int, ComplianceFramework> */
    public function getFrameworks(): Collection
    {
        return $this->frameworks;
    }

    public function addFramework(ComplianceFramework $framework): static
    {
        if (!$this->frameworks->contains($framework)) {
            $this->frameworks->add($framework);
        }
        return $this;
    }

    public function removeFramework(ComplianceFramework $framework): static
    {
        $this->frameworks->removeElement($framework);
        return $this;
    }

    // ── Audits (OneToMany) ─────────────────────────────────────────────────────

    /** @return Collection<int, InternalAudit> */
    public function getAudits(): Collection
    {
        return $this->audits;
    }

    // ── Responsible person (Pattern A dual-state) ──────────────────────────────

    public function getResponsiblePerson(): ?User
    {
        return $this->responsiblePerson;
    }

    public function setResponsiblePerson(?User $responsiblePerson): static
    {
        $this->responsiblePerson = $responsiblePerson;
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

    /**
     * P-15 DataReuse: prefer structured User, then Person, then null.
     */
    public function getEffectiveResponsibleName(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->responsiblePerson,
            $this->responsiblePersonRef,
            null,
        );
    }

    // ── Lifecycle ──────────────────────────────────────────────────────────────

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Called exclusively by Symfony Workflow's MethodMarkingStore.
     * Direct callers: LifecycleService::transition() only.
     */
    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function setApprovedBy(?User $approvedBy): static
    {
        $this->approvedBy = $approvedBy;
        return $this;
    }

    public function getApprovedAt(): ?DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function setApprovedAt(?DateTimeImmutable $approvedAt): static
    {
        $this->approvedAt = $approvedAt;
        return $this;
    }

    // ── Resources ──────────────────────────────────────────────────────────────

    public function getBudget(): ?string
    {
        return $this->budget;
    }

    public function setBudget(?string $budget): static
    {
        $this->budget = $budget;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    // ── Audit metadata ─────────────────────────────────────────────────────────

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
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

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * Return Aurora status-tone for use by `_fa_status_chip` macros.
     */
    public function getStatusTone(): string
    {
        return match ($this->status) {
            'draft'      => 'neutral',
            'approved'   => 'info',
            'active'     => 'primary',
            'completed'  => 'success',
            'archived'   => 'neutral',
            default      => 'neutral',
        };
    }

    public function getAuditCount(): int
    {
        return $this->audits->count();
    }

    public function getPlannedAuditCount(): int
    {
        return $this->audits->filter(
            static fn(InternalAudit $a): bool => $a->getStatus() === 'planned'
        )->count();
    }

    public function getCompletedAuditCount(): int
    {
        return $this->audits->filter(
            static fn(InternalAudit $a): bool => in_array($a->getStatus(), ['closed', 'completed', 'approved'], true)
        )->count();
    }

    public function __toString(): string
    {
        return $this->name ?? ('AuditProgram #' . ($this->id ?? '?'));
    }
}
