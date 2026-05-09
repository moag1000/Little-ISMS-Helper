<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WizardRunRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Execution log of a single Policy-Wizard run.
 *
 * Captures which standards were adopted, which mode (full / targeted /
 * sandbox) was selected, the inputs collected at each step, the
 * generated Document IDs (empty in sandbox mode) and the lifecycle
 * timestamps. Persistable per-step so users can resume after closing
 * the browser. See `05-architecture.md` §4.1 + §6.
 */
#[ORM\Entity(repositoryClass: WizardRunRepository::class)]
#[ORM\Table(name: 'wizard_run')]
#[ORM\Index(name: 'idx_wizard_run_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_wizard_run_status', columns: ['status'])]
#[ORM\Index(name: 'idx_wizard_run_started_at', columns: ['started_at'])]
class WizardRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * Standards adopted in this run, e.g. ['iso27001','dora'].
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $standardsAdopted = null;

    /**
     * Run mode. Allowed: full | targeted | sandbox (P1 ISB+UX).
     */
    #[ORM\Column(length: 16)]
    private string $mode = 'full';

    /**
     * Subset of topics for targeted re-runs (P1 ISB).
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $targetedTopics = null;

    /**
     * Optional reference to an Audit-Finding that triggered this run
     * (P1 ISB — surfaces in audit log).
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $findingReference = null;

    /**
     * Business-functions touched by this run (P1 Risk-Owner). Aggregated
     * from the affectedFunctions of every emitted PolicyTemplate.
     *
     * @var array<int, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $affectedFunctions = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $completedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'started_by_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $startedByUser = null;

    /**
     * Current step key, e.g. `organisation_scope`.
     */
    #[ORM\Column(length: 64)]
    private string $step = 'welcome';

    /**
     * Full settings snapshot keyed by step.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $inputs = null;

    /**
     * Lifecycle status. Allowed:
     * in_progress | completed | cancelled | failed | sandbox
     */
    #[ORM\Column(length: 16, options: ['default' => 'in_progress'])]
    private string $status = 'in_progress';

    /**
     * Document IDs created by this run; empty for sandbox.
     *
     * @var array<int, int>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $generatedDocumentIds = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    public function __construct()
    {
        $this->startedAt = new DateTimeImmutable();
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

    /**
     * Convenience accessor for tenant_id (multi-tenancy contract).
     */
    public function getTenantId(): ?int
    {
        return $this->tenant?->getId();
    }

    /** @return array<int, string>|null */
    public function getStandardsAdopted(): ?array
    {
        return $this->standardsAdopted;
    }

    /** @param array<int, string>|null $standardsAdopted */
    public function setStandardsAdopted(?array $standardsAdopted): static
    {
        $this->standardsAdopted = $standardsAdopted;
        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): static
    {
        $this->mode = $mode;
        return $this;
    }

    /** @return array<int, string>|null */
    public function getTargetedTopics(): ?array
    {
        return $this->targetedTopics;
    }

    /** @param array<int, string>|null $targetedTopics */
    public function setTargetedTopics(?array $targetedTopics): static
    {
        $this->targetedTopics = $targetedTopics;
        return $this;
    }

    public function getFindingReference(): ?string
    {
        return $this->findingReference;
    }

    public function setFindingReference(?string $findingReference): static
    {
        $this->findingReference = $findingReference;
        return $this;
    }

    /** @return array<int, string>|null */
    public function getAffectedFunctions(): ?array
    {
        return $this->affectedFunctions;
    }

    /** @param array<int, string>|null $affectedFunctions */
    public function setAffectedFunctions(?array $affectedFunctions): static
    {
        $this->affectedFunctions = $affectedFunctions;
        return $this;
    }

    public function getStartedAt(): ?DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setStartedAt(DateTimeInterface $startedAt): static
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?DateTimeInterface $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getStartedByUser(): ?User
    {
        return $this->startedByUser;
    }

    public function setStartedByUser(?User $startedByUser): static
    {
        $this->startedByUser = $startedByUser;
        return $this;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function setStep(string $step): static
    {
        $this->step = $step;
        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getInputs(): ?array
    {
        return $this->inputs;
    }

    /** @param array<string, mixed>|null $inputs */
    public function setInputs(?array $inputs): static
    {
        $this->inputs = $inputs;
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

    /** @return array<int, int>|null */
    public function getGeneratedDocumentIds(): ?array
    {
        return $this->generatedDocumentIds;
    }

    /** @param array<int, int>|null $generatedDocumentIds */
    public function setGeneratedDocumentIds(?array $generatedDocumentIds): static
    {
        $this->generatedDocumentIds = $generatedDocumentIds;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): static
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }
}
