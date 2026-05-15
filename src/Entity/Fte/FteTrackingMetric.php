<?php

declare(strict_types=1);

namespace App\Entity\Fte;

use App\Entity\Tenant;
use App\Repository\Fte\FteTrackingMetricRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F11 FTE-Tracking — per-event savings record.
 *
 * Each row captures one automation event (SSO JIT provisioning, Bulk-Import
 * commit, Evidence-Reuse count-tick) and stores the estimated manual effort
 * vs. the actual (tool-assisted) effort in minutes.
 *
 * savingsMinutes is always manualMinutesEstimate − actualMinutesEstimate.
 * Negative values are capped to 0 by the FteCalculationService before
 * persisting; the column itself allows negative values for raw data integrity.
 */
#[ORM\Entity(repositoryClass: FteTrackingMetricRepository::class)]
#[ORM\Table(name: 'fte_tracking_metric')]
#[ORM\Index(name: 'idx_fte_tenant_recorded', columns: ['tenant_id', 'recorded_at'])]
class FteTrackingMetric
{
    public const string SOURCE_BULK_IMPORT = 'bulk_import';
    public const string SOURCE_SSO_JIT = 'sso_jit';
    public const string SOURCE_EVIDENCE_REUSE = 'evidence_reuse';
    public const string SOURCE_MANUAL_CALIBRATION = 'manual_calibration';
    public const string SOURCE_WORKFLOW_AUTOMATION = 'workflow_automation';
    public const string SOURCE_NOTIFICATION_AUTOMATION = 'notification_automation';

    public const string PERIOD_REALTIME = 'realtime';
    public const string PERIOD_DAILY = 'daily';
    public const string PERIOD_MONTHLY = 'monthly';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;

    /** One of SOURCE_* constants */
    #[ORM\Column(length: 64)]
    private string $source;

    #[ORM\Column(length: 128)]
    private string $entityType;

    #[ORM\Column(nullable: true)]
    private ?int $entityId = null;

    #[ORM\Column]
    private int $manualMinutesEstimate;

    #[ORM\Column]
    private int $actualMinutesEstimate;

    /** Computed: manualMinutesEstimate - actualMinutesEstimate */
    #[ORM\Column]
    private int $savingsMinutes;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $recordedAt;

    /** One of PERIOD_* constants */
    #[ORM\Column(length: 32)]
    private string $period;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->recordedAt = new DateTimeImmutable();
        $this->period = self::PERIOD_REALTIME;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): static
    {
        $this->tenant = $tenant;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
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

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): static
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getManualMinutesEstimate(): int
    {
        return $this->manualMinutesEstimate;
    }

    public function setManualMinutesEstimate(int $manualMinutesEstimate): static
    {
        $this->manualMinutesEstimate = $manualMinutesEstimate;
        return $this;
    }

    public function getActualMinutesEstimate(): int
    {
        return $this->actualMinutesEstimate;
    }

    public function setActualMinutesEstimate(int $actualMinutesEstimate): static
    {
        $this->actualMinutesEstimate = $actualMinutesEstimate;
        return $this;
    }

    public function getSavingsMinutes(): int
    {
        return $this->savingsMinutes;
    }

    public function setSavingsMinutes(int $savingsMinutes): static
    {
        $this->savingsMinutes = $savingsMinutes;
        return $this;
    }

    public function getRecordedAt(): DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function setRecordedAt(DateTimeImmutable $recordedAt): static
    {
        $this->recordedAt = $recordedAt;
        return $this;
    }

    public function getPeriod(): string
    {
        return $this->period;
    }

    public function setPeriod(string $period): static
    {
        $this->period = $period;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }
}
