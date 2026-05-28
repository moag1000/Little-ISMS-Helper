<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditProgramRepository;
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
 * ISO 27001:2022 Cl. 9.2 — Internal audit programme
 */
#[ORM\Entity(repositoryClass: AuditProgramRepository::class)]
#[ORM\Table(name: 'audit_program')]
#[ORM\Index(name: 'idx_audit_program_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_audit_program_status', columns: ['status'])]
class AuditProgram
{
    public const STATUS_PLANNING  = 'planning';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ARCHIVED  = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'audit_program.validation.name_required')]
    #[Assert\Length(max: 255, maxMessage: 'audit_program.validation.name_max_length')]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** ISO 19011 §5.4.2 b — scope */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scope = null;

    /** ISO 19011 §5.4.2 a — objectives */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $objectives = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'audit_program.validation.start_date_required')]
    private ?DateTimeImmutable $startDate = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'audit_program.validation.end_date_required')]
    private ?DateTimeImmutable $endDate = null;

    /**
     * Lifecycle status — managed by audit_program_lifecycle Symfony Workflow.
     * Do NOT call setStatus() directly — use LifecycleService::transition().
     */
    #[ORM\Column(length: 30)]
    #[Assert\Choice(
        choices: ['planning', 'active', 'completed', 'archived'],
        message: 'audit_program.validation.status_invalid',
    )]
    private string $status = self::STATUS_PLANNING;

    /** ISO 19011 §5.4.4 — programme manager */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'programme_owner_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $programmeOwner = null;

    /**
     * Planned audits belonging to this programme (1 Programme → N InternalAudits).
     *
     * @var Collection<int, InternalAudit>
     */
    #[ORM\OneToMany(targetEntity: InternalAudit::class, mappedBy: 'auditProgram')]
    #[ORM\OrderBy(['plannedDate' => 'ASC'])]
    private Collection $internalAudits;

    /**
     * Risk areas covered by this programme (JSON list).
     *
     * @var list<string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $riskCategories = null;

    /** Repeat cadence: annual / biennial / quarterly / monthly */
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

    /** Optimistic-locking — required by Lifecycle Foundation P-4b. */
    #[ORM\Version]
    #[ORM\Column(name: 'lock_version', type: 'integer', options: ['default' => 0])]
    private int $lockVersion = 0;

    public function __construct()
    {
        $this->internalAudits = new ArrayCollection();
        $this->createdAt      = new DateTimeImmutable();
        $now                  = new DateTimeImmutable();
        $this->startDate      = $now->modify('first day of January this year');
        $this->endDate        = $now->modify('last day of December this year');
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getStatusTone(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE    => 'primary',
            self::STATUS_COMPLETED => 'success',
            default                => 'neutral',
        };
    }

    public function getProgrammeOwner(): ?User
    {
        return $this->programmeOwner;
    }

    public function setProgrammeOwner(?User $programmeOwner): static
    {
        $this->programmeOwner = $programmeOwner;
        return $this;
    }

    public function getEffectiveOwnerName(): ?string
    {
        if ($this->programmeOwner === null) {
            return null;
        }
        return $this->programmeOwner->getFullName()
            ?: ($this->programmeOwner->getEmail() ?? null);
    }

    /** @return Collection<int, InternalAudit> */
    public function getInternalAudits(): Collection
    {
        return $this->internalAudits;
    }

    public function addInternalAudit(InternalAudit $audit): static
    {
        if (!$this->internalAudits->contains($audit)) {
            $this->internalAudits->add($audit);
            $audit->setAuditProgram($this);
        }
        return $this;
    }

    public function removeInternalAudit(InternalAudit $audit): static
    {
        if ($this->internalAudits->removeElement($audit)) {
            if ($audit->getAuditProgram() === $this) {
                $audit->setAuditProgram(null);
            }
        }
        return $this;
    }

    public function getAuditCount(): int
    {
        return $this->internalAudits->count();
    }

    public function getCompletedAuditCount(): int
    {
        $count = 0;
        foreach ($this->internalAudits as $audit) {
            $statusVal = $audit->getStatus();
            if ($statusVal !== null && in_array((string) $statusVal, ['completed', 'closed', 'certified'], true)) {
                ++$count;
            }
        }
        return $count;
    }

    /** @return list<string>|null */
    public function getRiskCategories(): ?array
    {
        return $this->riskCategories;
    }

    /** @param list<string>|null $riskCategories */
    public function setRiskCategories(?array $riskCategories): static
    {
        $this->riskCategories = $riskCategories;
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
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

    public function __toString(): string
    {
        return $this->name ?? 'AuditProgram#' . ($this->id ?? '?');
    }
}
