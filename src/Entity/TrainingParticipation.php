<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TrainingParticipationStatus;
use App\Repository\TrainingParticipationRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Training Participation — structured M:N Training x User assignment.
 *
 * Audit V3 W2-C3: replaces the legacy free-text "[user:N:Name]" marker
 * pattern in {@see Training::$participants} with a real entity. Every
 * mandatory-training auto-assignment for a new user (see
 * {@see App\EventListener\AutoReactionTrainingAssignListener}) creates one
 * row here so audit-trail queries can answer the ISO 27001 §7.3-Awareness
 * questions:
 *
 *   - "Was user X assigned mandatory training Y?"           → row exists
 *   - "Did user X complete training Y, when?"               → completedAt
 *   - "Which mandatory trainings are still pending for X?"  → status='pending'
 *
 * Tenant isolation: tenant_id mirrors Training.tenant_id so cross-tenant
 * queries are caught by the standard tenant_id WHERE-filter.
 */
#[ORM\Entity(repositoryClass: TrainingParticipationRepository::class)]
#[ORM\Table(name: 'training_participation')]
#[ORM\UniqueConstraint(
    name: 'uq_training_participation_training_user',
    columns: ['training_id', 'user_id'],
)]
#[ORM\Index(name: 'idx_training_participation_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_training_participation_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_training_participation_status', columns: ['status'])]
class TrainingParticipation
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_WAIVED = 'waived';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_WAIVED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Training::class, cascade: ['persist'])]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Training $training = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 24)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?DateTimeInterface $assignedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $completedAt = null;

    /**
     * Free-text source of the assignment: "auto:user_create",
     * "manual:admin", "auto:role_change", etc.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $assignmentSource = null;

    /**
     * Score / pass-flag captured by training delivery. 0..100 percent
     * for e-learning, null for in-person.
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $score = null;

    public function __construct()
    {
        $this->assignedAt = new DateTimeImmutable();
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

    public function getTraining(): ?Training
    {
        return $this->training;
    }

    public function setTraining(?Training $training): static
    {
        $this->training = $training;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(TrainingParticipationStatus|string $status): static
    {
        // Accept both enum and string so new code can pass the typed enum
        // while existing string-passing callers keep working unchanged.
        $value = is_string($status) ? $status : $status->value;
        if (!in_array($value, self::ALLOWED_STATUSES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid TrainingParticipation status "%s". Allowed: %s',
                $value,
                implode(', ', self::ALLOWED_STATUSES),
            ));
        }
        $this->status = $value;
        return $this;
    }

    /** Typed status surface for enum-aware code. */
    public function getStatusEnum(): ?TrainingParticipationStatus
    {
        return TrainingParticipationStatus::tryFrom($this->status);
    }

    public function getAssignedAt(): ?DateTimeInterface
    {
        return $this->assignedAt;
    }

    public function setAssignedAt(DateTimeInterface $assignedAt): static
    {
        $this->assignedAt = $assignedAt;
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

    public function getAssignmentSource(): ?string
    {
        return $this->assignmentSource;
    }

    public function setAssignmentSource(?string $assignmentSource): static
    {
        $this->assignmentSource = $assignmentSource;
        return $this;
    }

    public function getScore(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): static
    {
        $this->score = $score;
        return $this;
    }
}
