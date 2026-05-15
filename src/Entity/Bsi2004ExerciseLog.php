<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\Bsi2004ExerciseLogRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * BSI-Standard 200-4 Übungs-Logbuch
 *
 * Strukturiertes Logbuch für BCM-Übungen gemäß BSI 200-4 Kapitel 9.
 * Ergänzt den bestehenden BCExercise-Datensatz um den vollständigen
 * BSI-konformen Übungsbericht mit Lessons-Learned und Maßnahmentracking.
 *
 * Workflow: draft → submitted (by ROLE_MANAGER) → confirmed (by ROLE_AUDITOR)
 */
#[ORM\Entity(repositoryClass: Bsi2004ExerciseLogRepository::class)]
#[ORM\Table(name: 'bsi_2004_exercise_log')]
#[ORM\Index(name: 'idx_bsi_log_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_bsi_log_submitted_at', columns: ['submitted_at'])]
#[ORM\HasLifecycleCallbacks]
class Bsi2004ExerciseLog
{
    public const string EXERCISE_TYPE_TABLETOP          = 'tabletop';
    public const string EXERCISE_TYPE_WALKTHROUGH       = 'walkthrough';
    public const string EXERCISE_TYPE_SIMULATION        = 'simulation';
    public const string EXERCISE_TYPE_FULL_SCALE        = 'full_scale';
    public const string EXERCISE_TYPE_CRISIS_TEAM_CALL  = 'crisis_team_call';
    public const string EXERCISE_TYPE_TECHNICAL_RECOVERY = 'technical_recovery';

    public const array EXERCISE_TYPES = [
        self::EXERCISE_TYPE_TABLETOP,
        self::EXERCISE_TYPE_WALKTHROUGH,
        self::EXERCISE_TYPE_SIMULATION,
        self::EXERCISE_TYPE_FULL_SCALE,
        self::EXERCISE_TYPE_CRISIS_TEAM_CALL,
        self::EXERCISE_TYPE_TECHNICAL_RECOVERY,
    ];

    public const string TEMPLATE_SIMPLE        = 'simple';
    public const string TEMPLATE_STANDARD      = 'standard';
    public const string TEMPLATE_COMPREHENSIVE = 'comprehensive';

    public const array TEMPLATES = [
        self::TEMPLATE_SIMPLE,
        self::TEMPLATE_STANDARD,
        self::TEMPLATE_COMPREHENSIVE,
    ];

    public const string RATING_POOR      = 'poor';
    public const string RATING_FAIR      = 'fair';
    public const string RATING_GOOD      = 'good';
    public const string RATING_EXCELLENT = 'excellent';

    public const array RATINGS = [
        self::RATING_POOR,
        self::RATING_FAIR,
        self::RATING_GOOD,
        self::RATING_EXCELLENT,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\OneToOne(targetEntity: BCExercise::class, inversedBy: 'exerciseLog')]
    #[ORM\JoinColumn(name: 'bc_exercise_id', nullable: false, unique: true, onDelete: 'CASCADE')]
    private ?BCExercise $bcExercise = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::EXERCISE_TYPES)]
    private string $exerciseType = self::EXERCISE_TYPE_TABLETOP;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    #[Assert\Choice(choices: self::TEMPLATES)]
    private string $bsi2004Template = self::TEMPLATE_STANDARD;

    /**
     * Array of participant records: [{user_id?: int, name: string, role?: string}, ...]
     *
     * @var array<int, array<string, mixed>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $participants = [];

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $scenarioSummary = '';

    /**
     * List of exercise objectives (strings)
     *
     * @var array<int, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $objectives = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $actionsBefore = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $actionsDuring = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $actionsAfter = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lessonsLearned = null;

    /**
     * Structured improvement actions: [{description: string, owner_user_id?: int, due_date?: string (Y-m-d), completed?: bool}, ...]
     *
     * @var array<int, array<string, mixed>>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $improvementActions = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Choice(choices: self::RATINGS)]
    private ?string $overallRating = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'submitted_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $submittedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $submittedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'confirmed_by_auditor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $confirmedByAuditor = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getBcExercise(): ?BCExercise
    {
        return $this->bcExercise;
    }

    public function setBcExercise(?BCExercise $bcExercise): static
    {
        $this->bcExercise = $bcExercise;
        return $this;
    }

    public function getExerciseType(): string
    {
        return $this->exerciseType;
    }

    public function setExerciseType(string $exerciseType): static
    {
        $this->exerciseType = $exerciseType;
        return $this;
    }

    public function getBsi2004Template(): string
    {
        return $this->bsi2004Template;
    }

    public function setBsi2004Template(string $bsi2004Template): static
    {
        $this->bsi2004Template = $bsi2004Template;
        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function getParticipants(): array
    {
        return $this->participants;
    }

    /** @param array<int, array<string, mixed>> $participants */
    public function setParticipants(array $participants): static
    {
        $this->participants = $participants;
        return $this;
    }

    public function getScenarioSummary(): string
    {
        return $this->scenarioSummary;
    }

    public function setScenarioSummary(string $scenarioSummary): static
    {
        $this->scenarioSummary = $scenarioSummary;
        return $this;
    }

    /** @return array<int, string> */
    public function getObjectives(): array
    {
        return $this->objectives;
    }

    /** @param array<int, string> $objectives */
    public function setObjectives(array $objectives): static
    {
        $this->objectives = $objectives;
        return $this;
    }

    public function getActionsBefore(): ?string
    {
        return $this->actionsBefore;
    }

    public function setActionsBefore(?string $actionsBefore): static
    {
        $this->actionsBefore = $actionsBefore;
        return $this;
    }

    public function getActionsDuring(): ?string
    {
        return $this->actionsDuring;
    }

    public function setActionsDuring(?string $actionsDuring): static
    {
        $this->actionsDuring = $actionsDuring;
        return $this;
    }

    public function getActionsAfter(): ?string
    {
        return $this->actionsAfter;
    }

    public function setActionsAfter(?string $actionsAfter): static
    {
        $this->actionsAfter = $actionsAfter;
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

    /** @return array<int, array<string, mixed>>|null */
    public function getImprovementActions(): ?array
    {
        return $this->improvementActions;
    }

    /** @param array<int, array<string, mixed>>|null $improvementActions */
    public function setImprovementActions(?array $improvementActions): static
    {
        $this->improvementActions = $improvementActions;
        return $this;
    }

    public function getOverallRating(): ?string
    {
        return $this->overallRating;
    }

    public function setOverallRating(?string $overallRating): static
    {
        $this->overallRating = $overallRating;
        return $this;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function setSubmittedBy(?User $submittedBy): static
    {
        $this->submittedBy = $submittedBy;
        return $this;
    }

    public function getSubmittedAt(): ?DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?DateTimeImmutable $submittedAt): static
    {
        $this->submittedAt = $submittedAt;
        return $this;
    }

    public function getConfirmedByAuditor(): ?User
    {
        return $this->confirmedByAuditor;
    }

    public function setConfirmedByAuditor(?User $confirmedByAuditor): static
    {
        $this->confirmedByAuditor = $confirmedByAuditor;
        return $this;
    }

    public function getConfirmedAt(): ?DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?DateTimeImmutable $confirmedAt): static
    {
        $this->confirmedAt = $confirmedAt;
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

    public function isSubmitted(): bool
    {
        return $this->submittedAt !== null;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmedAt !== null;
    }

    /**
     * Returns improvement actions that are past their due date and not completed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getOverdueImprovementActions(): array
    {
        if ($this->improvementActions === null) {
            return [];
        }
        $today = new \DateTimeImmutable('today');
        return array_values(array_filter(
            $this->improvementActions,
            static function (array $action) use ($today): bool {
                if (!empty($action['completed'])) {
                    return false;
                }
                if (empty($action['due_date'])) {
                    return false;
                }
                return new \DateTimeImmutable($action['due_date']) < $today;
            }
        ));
    }

    public function hasOverdueImprovementActions(): bool
    {
        return $this->getOverdueImprovementActions() !== [];
    }
}
