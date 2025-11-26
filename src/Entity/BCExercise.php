<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Delete;

use App\Repository\BCExerciseRepository;
use App\Entity\Tenant;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * BC Exercise/Test Entity for ISO 22301
 *
 * Documents BC plan testing, exercises, and drills
 */
#[ApiResource(
    operations: [
        new Get(security: "is_granted('ROLE_USER')"),
        new GetCollection(security: "is_granted('ROLE_USER')"),
        new Post(security: "is_granted('ROLE_USER')"),
        new Put(security: "is_granted('ROLE_USER')"),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ]
)]
#[ORM\Entity(repositoryClass: BCExerciseRepository::class)]
#[ORM\Table(name: 'bc_exercise')]
#[ORM\Index(columns: ['exercise_type'], name: 'idx_bc_exercise_type')]
#[ORM\Index(columns: ['exercise_date'], name: 'idx_bc_exercise_date')]
#[ORM\Index(columns: ['status'], name: 'idx_bc_exercise_status')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_bc_exercise_tenant')]
#[ORM\HasLifecycleCallbacks]
class BCExercise
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['bc_exercise:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['bc_exercise:read'])]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Exercise name is required')]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $name = null;

    /**
     * Type of exercise:
     * - tabletop: Discussion-based exercise
     * - walkthrough: Step-by-step review
     * - simulation: Simulated incident
     * - full_test: Complete activation test
     * - component_test: Test of specific component
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['tabletop', 'walkthrough', 'simulation', 'full_test', 'component_test'])]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $exerciseType = 'tabletop';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $description = null;

    /**
     * BC Plan(s) being tested
     */
    #[ORM\ManyToMany(targetEntity: BusinessContinuityPlan::class)]
    #[ORM\JoinTable(name: 'bc_exercise_plan')]
    #[Groups(['bc_exercise:read'])]
    private Collection $testedPlans;

    /**
     * Scope and objectives
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Scope must be defined')]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $scope = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Objectives must be defined')]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $objectives = null;

    /**
     * Exercise scenario
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $scenario = null;

    /**
     * Exercise date
     */
    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotNull]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?\DateTimeInterface $exerciseDate = null;

    /**
     * Duration in hours
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 1, max: 168)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?int $durationHours = null;

    /**
     * Participants
     */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'Participants must be documented')]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $participants = null;

    /**
     * Exercise facilitator/lead
     */
    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $facilitator = null;

    /**
     * Observers
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $observers = null;

    /**
     * Status: planned, in_progress, completed, cancelled
     */
    #[ORM\Column(length: 50)]
    #[Assert\Choice(choices: ['planned', 'in_progress', 'completed', 'cancelled'])]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $status = 'planned';

    /**
     * Results and observations
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $results = null;

    /**
     * What went well (WWW)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $whatWentWell = null;

    /**
     * Areas for improvement (AFI)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $areasForImprovement = null;

    /**
     * Findings (issues discovered)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $findings = null;

    /**
     * Action items resulting from exercise
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $actionItems = null;

    /**
     * Lessons learned
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $lessonsLearned = null;

    /**
     * Plan updates required (based on findings)
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?string $planUpdatesRequired = null;

    /**
     * Success criteria met (JSON)
     * {
     *   "RTO_met": true,
     *   "RPO_met": true,
     *   "communication_effective": true,
     *   "team_prepared": false
     * }
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?array $successCriteria = null;

    /**
     * Overall success rating (1-5)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 1, max: 5)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?int $successRating = null;

    /**
     * Report completed
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private bool $reportCompleted = false;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    #[Groups(['bc_exercise:read', 'bc_exercise:write'])]
    private ?\DateTimeInterface $reportDate = null;

    /**
     * Documents related to this exercise
     */
    #[ORM\ManyToMany(targetEntity: Document::class)]
    #[ORM\JoinTable(name: 'bc_exercise_document')]
    #[Groups(['bc_exercise:read'])]
    private Collection $documents;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['bc_exercise:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['bc_exercise:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->testedPlans = new ArrayCollection();
        $this->documents = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
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

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getExerciseType(): ?string
    {
        return $this->exerciseType;
    }

    public function setExerciseType(string $exerciseType): static
    {
        $this->exerciseType = $exerciseType;
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

    /**
     * @return Collection<int, BusinessContinuityPlan>
     */
    public function getTestedPlans(): Collection
    {
        return $this->testedPlans;
    }

    public function addTestedPlan(BusinessContinuityPlan $plan): static
    {
        if (!$this->testedPlans->contains($plan)) {
            $this->testedPlans->add($plan);
        }
        return $this;
    }

    public function removeTestedPlan(BusinessContinuityPlan $plan): static
    {
        $this->testedPlans->removeElement($plan);
        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(string $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    public function getObjectives(): ?string
    {
        return $this->objectives;
    }

    public function setObjectives(string $objectives): static
    {
        $this->objectives = $objectives;
        return $this;
    }

    public function getScenario(): ?string
    {
        return $this->scenario;
    }

    public function setScenario(?string $scenario): static
    {
        $this->scenario = $scenario;
        return $this;
    }

    public function getExerciseDate(): ?\DateTimeInterface
    {
        return $this->exerciseDate;
    }

    public function setExerciseDate(\DateTimeInterface $exerciseDate): static
    {
        $this->exerciseDate = $exerciseDate;
        return $this;
    }

    public function getDurationHours(): ?int
    {
        return $this->durationHours;
    }

    public function setDurationHours(?int $durationHours): static
    {
        $this->durationHours = $durationHours;
        return $this;
    }

    public function getParticipants(): ?string
    {
        return $this->participants;
    }

    public function setParticipants(string $participants): static
    {
        $this->participants = $participants;
        return $this;
    }

    public function getFacilitator(): ?string
    {
        return $this->facilitator;
    }

    public function setFacilitator(string $facilitator): static
    {
        $this->facilitator = $facilitator;
        return $this;
    }

    public function getObservers(): ?string
    {
        return $this->observers;
    }

    public function setObservers(?string $observers): static
    {
        $this->observers = $observers;
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

    public function getResults(): ?string
    {
        return $this->results;
    }

    public function setResults(?string $results): static
    {
        $this->results = $results;
        return $this;
    }

    public function getWhatWentWell(): ?string
    {
        return $this->whatWentWell;
    }

    public function setWhatWentWell(?string $whatWentWell): static
    {
        $this->whatWentWell = $whatWentWell;
        return $this;
    }

    public function getAreasForImprovement(): ?string
    {
        return $this->areasForImprovement;
    }

    public function setAreasForImprovement(?string $areasForImprovement): static
    {
        $this->areasForImprovement = $areasForImprovement;
        return $this;
    }

    public function getFindings(): ?string
    {
        return $this->findings;
    }

    public function setFindings(?string $findings): static
    {
        $this->findings = $findings;
        return $this;
    }

    public function getActionItems(): ?string
    {
        return $this->actionItems;
    }

    public function setActionItems(?string $actionItems): static
    {
        $this->actionItems = $actionItems;
        return $this;
    }

    public function getLessonsLearned(): ?string
    {
        return $this->lessonsLearned;
    }

    public function setLessonsLearned(?string $lessonsLearned): static
    {
        $this->lessonsLearned = $lessonsLearned;
        return $this;
    }

    public function getPlanUpdatesRequired(): ?string
    {
        return $this->planUpdatesRequired;
    }

    public function setPlanUpdatesRequired(?string $planUpdatesRequired): static
    {
        $this->planUpdatesRequired = $planUpdatesRequired;
        return $this;
    }

    public function getSuccessCriteria(): ?array
    {
        return $this->successCriteria;
    }

    public function setSuccessCriteria(?array $successCriteria): static
    {
        $this->successCriteria = $successCriteria;
        return $this;
    }

    public function getSuccessRating(): ?int
    {
        return $this->successRating;
    }

    public function setSuccessRating(?int $successRating): static
    {
        $this->successRating = $successRating;
        return $this;
    }

    public function isReportCompleted(): bool
    {
        return $this->reportCompleted;
    }

    public function setReportCompleted(bool $reportCompleted): static
    {
        $this->reportCompleted = $reportCompleted;
        return $this;
    }

    public function getReportDate(): ?\DateTimeInterface
    {
        return $this->reportDate;
    }

    public function setReportDate(?\DateTimeInterface $reportDate): static
    {
        $this->reportDate = $reportDate;
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
     * Check if exercise is complete with report
     */
    public function isFullyComplete(): bool
    {
        return $this->status === 'completed' && $this->reportCompleted;
    }

    /**
     * Calculate success percentage from success criteria
     */
    public function getSuccessPercentage(): int
    {
        if (empty($this->successCriteria)) {
            return 0;
        }

        $total = count($this->successCriteria);
        $met = count(array_filter($this->successCriteria, fn($value) => $value === true));

        return $total > 0 ? (int)(($met / $total) * 100) : 0;
    }

    /**
     * Get exercise effectiveness score (0-100)
     * Data Reuse: Combines multiple factors
     */
    public function getEffectivenessScore(): int
    {
        $score = 0;

        // Success rating (40%)
        if ($this->successRating) {
            $score += ($this->successRating / 5) * 40;
        }

        // Success criteria met (30%)
        $score += $this->getSuccessPercentage() * 0.3;

        // Report completed (20%)
        if ($this->reportCompleted) $score += 20;

        // Action items documented (10%)
        if (!empty($this->actionItems)) $score += 10;

        return (int)$score;
    }

    /**
     * Get exercise type description
     */
    public function getExerciseTypeDescription(): string
    {
        return match($this->exerciseType) {
            'tabletop' => 'Tabletop Exercise (Discussion-based)',
            'walkthrough' => 'Walkthrough (Step-by-step review)',
            'simulation' => 'Simulation (Simulated incident)',
            'full_test' => 'Full Test (Complete activation)',
            'component_test' => 'Component Test (Specific component)',
            default => 'Unknown'
        };
    }
}
