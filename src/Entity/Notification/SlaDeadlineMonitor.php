<?php

declare(strict_types=1);

namespace App\Entity\Notification;

use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SlaDeadlineStatus;
use App\Enum\SlaDeadlineType;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * SLA Deadline Monitor — Sprint 7A F3 Wave 2
 *
 * Tracks a single SLA deadline for an entity (DataBreach, Incident,
 * AuditFinding, etc.) and records which checkpoints have already fired.
 *
 * Compliance context:
 *  - GDPR Art. 33/34  (DataBreach 72h)
 *  - DORA Art. 19     (ICT incident 4h/24h/1mo)
 *  - NIS2 Art. 23     (significant incident 24h/72h/1mo)
 *  - ISO 27001 Cl.10.1 (corrective-action 30d)
 */
#[ORM\Entity(repositoryClass: SlaDeadlineMonitorRepository::class)]
#[ORM\Table(name: 'sla_deadline_monitor')]
#[ORM\Index(name: 'idx_sla_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_sla_status_deadline', columns: ['status', 'deadline_at'])]
#[ORM\Index(name: 'idx_sla_entity', columns: ['entity_type', 'entity_id'])]
class SlaDeadlineMonitor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    /**
     * Short class name of the monitored entity (e.g. 'DataBreach', 'Incident', 'AuditFinding').
     */
    #[ORM\Column(name: 'entity_type', length: 80)]
    private string $entityType = '';

    /**
     * Primary key of the monitored entity row.
     */
    #[ORM\Column(name: 'entity_id')]
    private int $entityId = 0;

    #[ORM\Column(name: 'deadline_type', length: 40, enumType: SlaDeadlineType::class)]
    private SlaDeadlineType $deadlineType = SlaDeadlineType::Custom;

    /**
     * Timestamp when the SLA clock started (e.g. DataBreach::createdAt).
     */
    #[ORM\Column(name: 'triggered_at')]
    private DateTimeImmutable $triggeredAt;

    /**
     * Absolute timestamp by which the obligation must be fulfilled.
     * Calculated as triggeredAt + deadlineType.durationHours().
     */
    #[ORM\Column(name: 'deadline_at')]
    private DateTimeImmutable $deadlineAt;

    /**
     * Ordered list of hours-before-deadline at which notifications should fire.
     * Example: [48, 24, 12, 4, 1] fires at 48h, 24h, 12h, 4h, and 1h before deadline.
     *
     * @var int[]
     */
    #[ORM\Column(name: 'notify_at_checkpoints', type: Types::JSON)]
    private array $notifyAtCheckpoints = [];

    /**
     * The hours-before-deadline value of the last checkpoint notification that
     * was emitted. NULL means no notification has fired yet.
     * Used to prevent re-firing the same checkpoint on subsequent cron ticks.
     */
    #[ORM\Column(name: 'last_notified_at_hours', nullable: true)]
    private ?int $lastNotifiedAtHours = null;

    #[ORM\Column(name: 'status', length: 20, enumType: SlaDeadlineStatus::class)]
    private SlaDeadlineStatus $status = SlaDeadlineStatus::Active;

    #[ORM\Column(name: 'satisfied_at', nullable: true)]
    private ?DateTimeImmutable $satisfiedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'satisfied_by_id', referencedColumnName: 'id', nullable: true)]
    private ?User $satisfiedBy = null;

    public function __construct()
    {
        $this->triggeredAt = new DateTimeImmutable();
        $this->deadlineAt  = new DateTimeImmutable();
    }

    // --- Accessors -----------------------------------------------------------

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

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): static
    {
        $this->entityType = $entityType;

        return $this;
    }

    public function getEntityId(): int
    {
        return $this->entityId;
    }

    public function setEntityId(int $entityId): static
    {
        $this->entityId = $entityId;

        return $this;
    }

    public function getDeadlineType(): SlaDeadlineType
    {
        return $this->deadlineType;
    }

    public function setDeadlineType(SlaDeadlineType $deadlineType): static
    {
        $this->deadlineType = $deadlineType;

        return $this;
    }

    public function getTriggeredAt(): DateTimeImmutable
    {
        return $this->triggeredAt;
    }

    public function setTriggeredAt(DateTimeImmutable $triggeredAt): static
    {
        $this->triggeredAt = $triggeredAt;

        return $this;
    }

    public function getDeadlineAt(): DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(DateTimeImmutable $deadlineAt): static
    {
        $this->deadlineAt = $deadlineAt;

        return $this;
    }

    /** @return int[] */
    public function getNotifyAtCheckpoints(): array
    {
        return $this->notifyAtCheckpoints;
    }

    /** @param int[] $notifyAtCheckpoints */
    public function setNotifyAtCheckpoints(array $notifyAtCheckpoints): static
    {
        $this->notifyAtCheckpoints = $notifyAtCheckpoints;

        return $this;
    }

    public function getLastNotifiedAtHours(): ?int
    {
        return $this->lastNotifiedAtHours;
    }

    public function setLastNotifiedAtHours(?int $lastNotifiedAtHours): static
    {
        $this->lastNotifiedAtHours = $lastNotifiedAtHours;

        return $this;
    }

    public function getStatus(): SlaDeadlineStatus
    {
        return $this->status;
    }

    public function setStatus(SlaDeadlineStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSatisfiedAt(): ?DateTimeImmutable
    {
        return $this->satisfiedAt;
    }

    public function setSatisfiedAt(?DateTimeImmutable $satisfiedAt): static
    {
        $this->satisfiedAt = $satisfiedAt;

        return $this;
    }

    public function getSatisfiedBy(): ?User
    {
        return $this->satisfiedBy;
    }

    public function setSatisfiedBy(?User $satisfiedBy): static
    {
        $this->satisfiedBy = $satisfiedBy;

        return $this;
    }

    // --- Domain helpers ------------------------------------------------------

    /**
     * Returns the number of hours remaining before the deadline.
     * Negative values indicate the deadline has already passed.
     */
    public function hoursRemaining(): float
    {
        $now     = new DateTimeImmutable();
        $seconds = $this->deadlineAt->getTimestamp() - $now->getTimestamp();

        return $seconds / 3600.0;
    }

    public function isActive(): bool
    {
        return $this->status === SlaDeadlineStatus::Active;
    }

    public function isMissed(): bool
    {
        return $this->status === SlaDeadlineStatus::Missed;
    }

    public function isSatisfied(): bool
    {
        return $this->status === SlaDeadlineStatus::Satisfied;
    }
}
