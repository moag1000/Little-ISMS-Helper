<?php

namespace App\Entity;

use App\Repository\BusinessContinuityPlanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Business Continuity Plan Entity for ISO 22301
 *
 * Documents recovery procedures and strategies for business processes
 */
#[ORM\Entity(repositoryClass: BusinessContinuityPlanRepository::class)]
#[ORM\Index(columns: ['status'], name: 'idx_bc_plan_status')]
#[ORM\Index(columns: ['last_tested'], name: 'idx_bc_plan_last_tested')]
#[ORM\Index(columns: ['next_review_date'], name: 'idx_bc_plan_next_review')]
class BusinessContinuityPlan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Plan name is required')]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Associated business process
     */
    #[ORM\ManyToOne(targetEntity: BusinessProcess::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Business process is required')]
    private ?BusinessProcess $businessProcess = null;

    /**
     * Plan owner/responsible person
     */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private ?string $planOwner = null;

    /**
     * BC Team members
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bcTeam = null;

    /**
     * Status: draft, active, under_review, archived
     */
    #[ORM\Column(length: 50)]
    #[Assert\Choice(choices: ['draft', 'active', 'under_review', 'archived'])]
    private ?string $status = 'draft';

    /**
     * Activation criteria - when to activate this plan
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Activation criteria must be defined')]
    private ?string $activationCriteria = null;

    /**
     * Roles and responsibilities during incident
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rolesAndResponsibilities = null;

    /**
     * Response team structure (JSON)
     * {
     *   "incident_commander": "John Doe",
     *   "communications_lead": "Jane Smith",
     *   "recovery_lead": "Bob Johnson",
     *   "technical_lead": "Alice Williams"
     * }
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $responseTeam = null;

    /**
     * Recovery procedures (step-by-step)
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Recovery procedures must be documented')]
    private ?string $recoveryProcedures = null;

    /**
     * Communication plan
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $communicationPlan = null;

    /**
     * Internal communication procedures
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalCommunication = null;

    /**
     * External communication procedures
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $externalCommunication = null;

    /**
     * Stakeholder notification list (JSON)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $stakeholderContacts = null;

    /**
     * Alternative site/workaround location
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $alternativeSite = null;

    /**
     * Alternative site address
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $alternativeSiteAddress = null;

    /**
     * Alternative site capacity/readiness
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $alternativeSiteCapacity = null;

    /**
     * Backup procedures
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $backupProcedures = null;

    /**
     * Restore procedures
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $restoreProcedures = null;

    /**
     * Required resources (JSON)
     * {
     *   "personnel": 10,
     *   "equipment": ["Laptops", "Phones"],
     *   "supplies": ["Paper", "Toner"]
     * }
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $requiredResources = null;

    /**
     * Critical suppliers for this plan
     */
    #[ORM\ManyToMany(targetEntity: Supplier::class)]
    #[ORM\JoinTable(name: 'bc_plan_supplier')]
    private Collection $criticalSuppliers;

    /**
     * Critical assets for this plan
     */
    #[ORM\ManyToMany(targetEntity: Asset::class)]
    #[ORM\JoinTable(name: 'bc_plan_asset')]
    private Collection $criticalAssets;

    /**
     * Documents related to this plan
     */
    #[ORM\ManyToMany(targetEntity: Document::class)]
    #[ORM\JoinTable(name: 'bc_plan_document')]
    private Collection $documents;

    /**
     * Version number
     */
    #[ORM\Column(length: 20)]
    private ?string $version = '1.0';

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastTested = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $nextTestDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastReviewDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $nextReviewDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reviewNotes = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->criticalSuppliers = new ArrayCollection();
        $this->criticalAssets = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
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

    public function getBusinessProcess(): ?BusinessProcess
    {
        return $this->businessProcess;
    }

    public function setBusinessProcess(?BusinessProcess $businessProcess): static
    {
        $this->businessProcess = $businessProcess;
        return $this;
    }

    public function getPlanOwner(): ?string
    {
        return $this->planOwner;
    }

    public function setPlanOwner(string $planOwner): static
    {
        $this->planOwner = $planOwner;
        return $this;
    }

    public function getBcTeam(): ?string
    {
        return $this->bcTeam;
    }

    public function setBcTeam(?string $bcTeam): static
    {
        $this->bcTeam = $bcTeam;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getActivationCriteria(): ?string
    {
        return $this->activationCriteria;
    }

    public function setActivationCriteria(string $activationCriteria): static
    {
        $this->activationCriteria = $activationCriteria;
        return $this;
    }

    public function getRolesAndResponsibilities(): ?string
    {
        return $this->rolesAndResponsibilities;
    }

    public function setRolesAndResponsibilities(?string $rolesAndResponsibilities): static
    {
        $this->rolesAndResponsibilities = $rolesAndResponsibilities;
        return $this;
    }

    public function getResponseTeam(): ?array
    {
        return $this->responseTeam;
    }

    public function setResponseTeam(?array $responseTeam): static
    {
        $this->responseTeam = $responseTeam;
        return $this;
    }

    public function getRecoveryProcedures(): ?string
    {
        return $this->recoveryProcedures;
    }

    public function setRecoveryProcedures(string $recoveryProcedures): static
    {
        $this->recoveryProcedures = $recoveryProcedures;
        return $this;
    }

    public function getCommunicationPlan(): ?string
    {
        return $this->communicationPlan;
    }

    public function setCommunicationPlan(?string $communicationPlan): static
    {
        $this->communicationPlan = $communicationPlan;
        return $this;
    }

    public function getInternalCommunication(): ?string
    {
        return $this->internalCommunication;
    }

    public function setInternalCommunication(?string $internalCommunication): static
    {
        $this->internalCommunication = $internalCommunication;
        return $this;
    }

    public function getExternalCommunication(): ?string
    {
        return $this->externalCommunication;
    }

    public function setExternalCommunication(?string $externalCommunication): static
    {
        $this->externalCommunication = $externalCommunication;
        return $this;
    }

    public function getStakeholderContacts(): ?array
    {
        return $this->stakeholderContacts;
    }

    public function setStakeholderContacts(?array $stakeholderContacts): static
    {
        $this->stakeholderContacts = $stakeholderContacts;
        return $this;
    }

    public function getAlternativeSite(): ?string
    {
        return $this->alternativeSite;
    }

    public function setAlternativeSite(?string $alternativeSite): static
    {
        $this->alternativeSite = $alternativeSite;
        return $this;
    }

    public function getAlternativeSiteAddress(): ?string
    {
        return $this->alternativeSiteAddress;
    }

    public function setAlternativeSiteAddress(?string $alternativeSiteAddress): static
    {
        $this->alternativeSiteAddress = $alternativeSiteAddress;
        return $this;
    }

    public function getAlternativeSiteCapacity(): ?string
    {
        return $this->alternativeSiteCapacity;
    }

    public function setAlternativeSiteCapacity(?string $alternativeSiteCapacity): static
    {
        $this->alternativeSiteCapacity = $alternativeSiteCapacity;
        return $this;
    }

    public function getBackupProcedures(): ?string
    {
        return $this->backupProcedures;
    }

    public function setBackupProcedures(?string $backupProcedures): static
    {
        $this->backupProcedures = $backupProcedures;
        return $this;
    }

    public function getRestoreProcedures(): ?string
    {
        return $this->restoreProcedures;
    }

    public function setRestoreProcedures(?string $restoreProcedures): static
    {
        $this->restoreProcedures = $restoreProcedures;
        return $this;
    }

    public function getRequiredResources(): ?array
    {
        return $this->requiredResources;
    }

    public function setRequiredResources(?array $requiredResources): static
    {
        $this->requiredResources = $requiredResources;
        return $this;
    }

    /**
     * @return Collection<int, Supplier>
     */
    public function getCriticalSuppliers(): Collection
    {
        return $this->criticalSuppliers;
    }

    public function addCriticalSupplier(Supplier $supplier): static
    {
        if (!$this->criticalSuppliers->contains($supplier)) {
            $this->criticalSuppliers->add($supplier);
        }
        return $this;
    }

    public function removeCriticalSupplier(Supplier $supplier): static
    {
        $this->criticalSuppliers->removeElement($supplier);
        return $this;
    }

    /**
     * @return Collection<int, Asset>
     */
    public function getCriticalAssets(): Collection
    {
        return $this->criticalAssets;
    }

    public function addCriticalAsset(Asset $asset): static
    {
        if (!$this->criticalAssets->contains($asset)) {
            $this->criticalAssets->add($asset);
        }
        return $this;
    }

    public function removeCriticalAsset(Asset $asset): static
    {
        $this->criticalAssets->removeElement($asset);
        return $this;
    }

    /**
     * @return Collection<int, Document>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(Document $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
        }
        return $this;
    }

    public function removeDocument(Document $document): static
    {
        $this->documents->removeElement($document);
        return $this;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

    public function getLastTested(): ?\DateTimeInterface
    {
        return $this->lastTested;
    }

    public function setLastTested(?\DateTimeInterface $lastTested): static
    {
        $this->lastTested = $lastTested;
        return $this;
    }

    public function getNextTestDate(): ?\DateTimeInterface
    {
        return $this->nextTestDate;
    }

    public function setNextTestDate(?\DateTimeInterface $nextTestDate): static
    {
        $this->nextTestDate = $nextTestDate;
        return $this;
    }

    public function getLastReviewDate(): ?\DateTimeInterface
    {
        return $this->lastReviewDate;
    }

    public function setLastReviewDate(?\DateTimeInterface $lastReviewDate): static
    {
        $this->lastReviewDate = $lastReviewDate;
        return $this;
    }

    public function getNextReviewDate(): ?\DateTimeInterface
    {
        return $this->nextReviewDate;
    }

    public function setNextReviewDate(?\DateTimeInterface $nextReviewDate): static
    {
        $this->nextReviewDate = $nextReviewDate;
        return $this;
    }

    public function getReviewNotes(): ?string
    {
        return $this->reviewNotes;
    }

    public function setReviewNotes(?string $reviewNotes): static
    {
        $this->reviewNotes = $reviewNotes;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * Check if plan testing is overdue
     */
    public function isTestOverdue(): bool
    {
        if (!$this->nextTestDate) {
            // If never tested and status is active, it's overdue
            return $this->status === 'active' && $this->lastTested === null;
        }

        return $this->nextTestDate < new \DateTime();
    }

    /**
     * Check if plan review is overdue
     */
    public function isReviewOverdue(): bool
    {
        if (!$this->nextReviewDate) {
            return false;
        }

        return $this->nextReviewDate < new \DateTime();
    }

    /**
     * Get plan readiness score (0-100)
     * Data Reuse: Aggregates multiple factors
     */
    public function getReadinessScore(): int
    {
        $score = 0;

        // Has all required sections (40%)
        if (!empty($this->activationCriteria)) $score += 10;
        if (!empty($this->recoveryProcedures)) $score += 10;
        if (!empty($this->communicationPlan)) $score += 10;
        if (!empty($this->responseTeam)) $score += 10;

        // Tested recently (30%)
        if ($this->lastTested) {
            $monthsSinceTest = (new \DateTime())->diff($this->lastTested)->m;
            if ($monthsSinceTest <= 6) $score += 30;
            elseif ($monthsSinceTest <= 12) $score += 20;
            elseif ($monthsSinceTest <= 24) $score += 10;
        }

        // Reviewed recently (20%)
        if ($this->lastReviewDate) {
            $monthsSinceReview = (new \DateTime())->diff($this->lastReviewDate)->m;
            if ($monthsSinceReview <= 6) $score += 20;
            elseif ($monthsSinceReview <= 12) $score += 10;
        }

        // Status is active (10%)
        if ($this->status === 'active') $score += 10;

        return $score;
    }

    /**
     * Get plan completeness percentage
     */
    public function getCompletenessPercentage(): int
    {
        $total = 13; // Total number of key fields
        $completed = 0;

        if (!empty($this->name)) $completed++;
        if (!empty($this->planOwner)) $completed++;
        if (!empty($this->activationCriteria)) $completed++;
        if (!empty($this->recoveryProcedures)) $completed++;
        if (!empty($this->communicationPlan)) $completed++;
        if (!empty($this->responseTeam)) $completed++;
        if (!empty($this->rolesAndResponsibilities)) $completed++;
        if (!empty($this->alternativeSite)) $completed++;
        if (!empty($this->backupProcedures)) $completed++;
        if (!empty($this->restoreProcedures)) $completed++;
        if (!$this->criticalAssets->isEmpty()) $completed++;
        if (!empty($this->stakeholderContacts)) $completed++;
        if (!empty($this->requiredResources)) $completed++;

        return (int)(($completed / $total) * 100);
    }
}
