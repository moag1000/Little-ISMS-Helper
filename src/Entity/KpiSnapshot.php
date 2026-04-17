<?php

namespace App\Entity;

use App\Repository\KpiSnapshotRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * KPI Snapshot Entity
 *
 * Stores a daily snapshot of key performance indicators per tenant.
 * Used for trend tracking: comparing current KPIs against historical data
 * so boards and CISOs can see whether compliance is improving or deteriorating.
 *
 * Populated by: app:kpi-snapshot command (designed for daily cron)
 * Consumed by: DashboardStatisticsService::addTrendData()
 */
#[ORM\Entity(repositoryClass: KpiSnapshotRepository::class)]
#[ORM\Table(name: 'kpi_snapshot')]
#[ORM\Index(name: 'idx_kpi_snapshot_tenant_date', columns: ['tenant_id', 'snapshot_date'])]
#[ORM\HasLifecycleCallbacks]
class KpiSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $snapshotDate;

    /**
     * Flat key-value map of KPI values at snapshot time.
     *
     * Example: {
     *   "control_compliance": 75,
     *   "risk_treatment_rate": 82,
     *   "training_completion": 90,
     *   "supplier_assessment": 95,
     *   "isms_health_score": 80,
     *   "total_risks": 42,
     *   "high_risks": 5,
     *   "critical_risks": 1,
     *   "open_incidents": 3
     * }
     */
    #[ORM\Column(type: Types::JSON)]
    private array $kpiData = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
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

    public function getSnapshotDate(): DateTimeImmutable
    {
        return $this->snapshotDate;
    }

    public function setSnapshotDate(DateTimeImmutable $snapshotDate): static
    {
        $this->snapshotDate = $snapshotDate;
        return $this;
    }

    public function getKpiData(): array
    {
        return $this->kpiData;
    }

    public function setKpiData(array $kpiData): static
    {
        $this->kpiData = $kpiData;
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
