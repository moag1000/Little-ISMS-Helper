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

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'audit_program.validation.name_required')]
    #[Assert\Length(max: 255, maxMessage: 'audit_program.validation.name_max_length')]
    private ?string $name = null;

    /**
     * Programm-Beschreibung.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

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

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'audit_program.validation.start_date_required')]
    private ?DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'audit_program.validation.end_date_required')]
    private ?DateTimeImmutable $endDate = null;

    /**
     * Lifecycle-Status: planning → active → completed → archived.
     * Managed exclusively by `audit_program_lifecycle` Symfony Workflow.
     * Do NOT call setStatus() directly — use LifecycleService::transition().
     */
    #[ORM\Column(length: 30)]
    #[Assert\Choice(
        choices: ['planning', 'active', 'completed', 'archived'],
        message: 'audit_program.validation.status_invalid'
    )]
    private string $status = 'planning';

    /**
     * Audit-Programm-Manager (ISO 19011 §5.4.4 — programme manager).
     * Preferred structured reference — falls back to freetext.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'programme_owner_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $programmeOwner = null;

    /**
     * Die geplanten Audits dieses Programms (1 Program → N InternalAudits).
     *
     * @var Collection<int, InternalAudit>
     */
    #[ORM\OneToMany(targetEntity: InternalAudit::class, mappedBy: 'auditProgram')]
    #[ORM\OrderBy(['plannedDate' => 'ASC'])]
    private Collection $internalAudits;

    /**
     * Risikobereiche, die dieses Programm abdeckt (JSON-Liste).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $riskCategories = null;

    /**
     * Wiederholungsfrequenz: 'annual', 'biennial', 'quarterly', etc.
     */
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $frequency = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

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
        $this->internalAudits = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
        $now = new DateTimeImmutable();
        $this->startDate = $now->modify('first day of January this year');
        $this->endDate = $now->modify('last day of December this year');
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
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

    public function getStartDate(): ?DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): ?DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(?DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getFrequency(): ?string
    {
        return $this->frequency;
    }

    public function setFrequency(?string $frequency): static
    {
        $this->frequency = $frequency;
        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getRiskCategories(): ?array
    {
        return $this->riskCategories;
    }

    /**
     * @param list<string>|null $riskCategories
     */
    public function setRiskCategories(?array $riskCategories): static
    {
        $this->riskCategories = $riskCategories;
        return $this;
    }

    // ── Audits (OneToMany) ─────────────────────────────────────────────────────

    /** @return Collection<int, InternalAudit> */
    public function getInternalAudits(): Collection
    {
        return $this->internalAudits;
    }

    // ── Programme owner ────────────────────────────────────────────────────────

    public function getProgrammeOwner(): ?User
    {
        return $this->programmeOwner;
    }

    public function setProgrammeOwner(?User $programmeOwner): static
    {
        $this->programmeOwner = $programmeOwner;
        return $this;
    }

    /**
     * Resolve effective owner name (P-15 DataReuse pattern).
     */
    public function getEffectiveOwnerName(): ?string
    {
        return OwnerResolver::resolveEffective($this->programmeOwner, null, null);
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

    public function getArchivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?DateTimeImmutable $archivedAt): static
    {
        $this->archivedAt = $archivedAt;
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
     * Return Aurora status-tone for use by status-chip macros.
     */
    public function getStatusTone(): string
    {
        return match ($this->status) {
            'planning'  => 'neutral',
            'active'    => 'primary',
            'completed' => 'success',
            'archived'  => 'neutral',
            default     => 'neutral',
        };
    }

    public function getAuditCount(): int
    {
        return $this->internalAudits->count();
    }

    public function getCompletedAuditCount(): int
    {
        return $this->internalAudits->filter(
            static fn(InternalAudit $a): bool => in_array($a->getStatus(), ['closed', 'completed', 'approved'], true)
        )->count();
    }

    public function __toString(): string
    {
        return $this->name ?? ('AuditProgram #' . ($this->id ?? '?'));
    }
}
