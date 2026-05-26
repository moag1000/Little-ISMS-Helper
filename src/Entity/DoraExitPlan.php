<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DoraExitPlanRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * DORA Art. 28 RT_06 — ICT Third-Party Provider Exit Plan.
 *
 * Captures what happens when an ICT contract terminates:
 *  - data-return path + format
 *  - data-deletion confirmation + supporting evidence
 *  - migration path (alternative provider or in-house)
 *  - rehearsal date (DORA expects testing of exit strategy)
 *  - estimated duration + cost (for board reporting)
 *
 * One exit plan per critical Supplier (unique on supplier_id). Wired into
 * the DORA RoI XBRL export as the long-deferred RT_06 sub-table.
 *
 * References:
 *  - DORA Art. 28(8) — exit-strategy requirement
 *  - ESA Joint Guidelines JC 2023/86 — RT_06 decommission-plan
 *  - ISO 27001 A.5.22 (monitoring/review of supplier services)
 */
#[ORM\Entity(repositoryClass: DoraExitPlanRepository::class)]
#[ORM\Table(name: 'dora_exit_plan')]
#[ORM\UniqueConstraint(name: 'uniq_dora_exit_plan_supplier', columns: ['supplier_id'])]
#[ORM\Index(name: 'idx_dora_exit_plan_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_dora_exit_plan_tested_at', columns: ['tested_at'])]
#[ORM\HasLifecycleCallbacks]
class DoraExitPlan
{
    public const string TRIGGER_PLANNED_RENEWAL    = 'planned-renewal';
    public const string TRIGGER_CONCENTRATION_RISK = 'concentration-risk';
    public const string TRIGGER_FORCE_MAJEURE      = 'force-majeure';
    public const string TRIGGER_BREACH             = 'breach';
    public const string TRIGGER_INSOLVENCY         = 'insolvency';

    /** @var list<string> */
    public const array EXIT_TRIGGERS = [
        self::TRIGGER_PLANNED_RENEWAL,
        self::TRIGGER_CONCENTRATION_RISK,
        self::TRIGGER_FORCE_MAJEURE,
        self::TRIGGER_BREACH,
        self::TRIGGER_INSOLVENCY,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * One exit plan per critical supplier — unique constraint at the
     * DB-level. ON DELETE CASCADE: removing the supplier wipes the plan.
     */
    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'dora_exit_plan.validation.supplier_required')]
    private ?Supplier $supplier = null;

    /**
     * Exit trigger — one of EXIT_TRIGGERS. Choice validation in FormType.
     */
    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'dora_exit_plan.validation.trigger_required')]
    #[Assert\Choice(choices: self::EXIT_TRIGGERS, message: 'dora_exit_plan.validation.trigger_invalid')]
    private ?string $exitTrigger = self::TRIGGER_PLANNED_RENEWAL;

    /**
     * Free-text description of the data-return mechanism — e.g.
     * "CSV via SFTP within 30 days" or "S3 bucket export, GPG-encrypted".
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'dora_exit_plan.validation.data_return_format_max')]
    private ?string $dataReturnFormat = null;

    /**
     * Whether the supplier has contractually confirmed deletion of all
     * data once the exit is completed (DORA Art. 28(8) requirement).
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $dataDeletionConfirmation = false;

    /**
     * Optional FK to the deletion certificate / contractual confirmation
     * document (e.g. signed letter of destruction). SET NULL on document
     * delete so the exit plan survives document retention sweeps.
     */
    #[ORM\ManyToOne(targetEntity: Document::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Document $deletionCertificateDoc = null;

    /**
     * Migration path — alternative provider name, in-house ramp-up plan,
     * or hybrid description. Bounded to 1000 chars (DB column TEXT, but
     * we constrain at form level for sanity).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000, maxMessage: 'dora_exit_plan.validation.migration_path_max')]
    private ?string $migrationPath = null;

    /**
     * Last date the exit strategy was rehearsed (table-top or live).
     * Drives the ExitPlanRehearsalOverdueRule Alva-Hint at >12 months.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $testedAt = null;

    /**
     * Estimated wall-clock days to complete the exit (for board reporting).
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\PositiveOrZero(message: 'dora_exit_plan.validation.duration_non_negative')]
    private ?int $estimatedDurationDays = null;

    /**
     * Estimated one-off cost of executing the exit, in tenant reporting
     * currency. Decimal(15,2) to match the rest of the cost-tracking
     * schema (Supplier.annualSpend etc).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 15, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero(message: 'dora_exit_plan.validation.cost_non_negative')]
    private ?string $estimatedCost = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }

    public function getSupplier(): ?Supplier { return $this->supplier; }
    public function setSupplier(?Supplier $supplier): static { $this->supplier = $supplier; return $this; }

    public function getExitTrigger(): ?string { return $this->exitTrigger; }
    public function setExitTrigger(?string $exitTrigger): static { $this->exitTrigger = $exitTrigger; return $this; }

    public function getDataReturnFormat(): ?string { return $this->dataReturnFormat; }
    public function setDataReturnFormat(?string $dataReturnFormat): static { $this->dataReturnFormat = $dataReturnFormat; return $this; }

    public function isDataDeletionConfirmation(): bool { return $this->dataDeletionConfirmation; }
    public function setDataDeletionConfirmation(bool $dataDeletionConfirmation): static { $this->dataDeletionConfirmation = $dataDeletionConfirmation; return $this; }

    public function getDeletionCertificateDoc(): ?Document { return $this->deletionCertificateDoc; }
    public function setDeletionCertificateDoc(?Document $deletionCertificateDoc): static { $this->deletionCertificateDoc = $deletionCertificateDoc; return $this; }

    public function getMigrationPath(): ?string { return $this->migrationPath; }
    public function setMigrationPath(?string $migrationPath): static { $this->migrationPath = $migrationPath; return $this; }

    public function getTestedAt(): ?DateTimeImmutable { return $this->testedAt; }
    public function setTestedAt(?DateTimeImmutable $testedAt): static { $this->testedAt = $testedAt; return $this; }

    public function getEstimatedDurationDays(): ?int { return $this->estimatedDurationDays; }
    public function setEstimatedDurationDays(?int $estimatedDurationDays): static { $this->estimatedDurationDays = $estimatedDurationDays; return $this; }

    public function getEstimatedCost(): ?string { return $this->estimatedCost; }
    public function setEstimatedCost(?string $estimatedCost): static { $this->estimatedCost = $estimatedCost; return $this; }

    public function getCreatedAt(): ?DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): ?DateTimeImmutable { return $this->updatedAt; }

    /**
     * True when the last rehearsal is older than 12 months (or never tested).
     * Used by the Alva-Hint rule to surface overdue exit-plan tests.
     *
     * @param DateTimeImmutable|null $now injection seam for tests
     */
    public function isRehearsalOverdue(?DateTimeImmutable $now = null): bool
    {
        if ($this->testedAt === null) {
            return true;
        }
        $now ??= new DateTimeImmutable();
        $threshold = $now->modify('-12 months');

        return $this->testedAt < $threshold;
    }
}
