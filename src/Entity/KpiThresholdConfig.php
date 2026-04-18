<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\KpiThresholdConfigRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-tenant override for KPI status thresholds.
 *
 * Rows are keyed by `kpi_key` and store the "good" and "warning" cut-offs
 * used by DashboardStatisticsService::getStatus(). Absence of a row falls
 * back to the service defaults.
 */
#[ORM\Entity(repositoryClass: KpiThresholdConfigRepository::class)]
#[ORM\Table(name: 'kpi_threshold_config')]
#[ORM\UniqueConstraint(name: 'uniq_kpi_threshold_tenant_key', columns: ['tenant_id', 'kpi_key'])]
#[ORM\Index(name: 'idx_kpi_threshold_tenant', columns: ['tenant_id'])]
class KpiThresholdConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 100)]
    private ?string $kpiKey = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $goodThreshold = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $warningThreshold = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
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

    public function getKpiKey(): ?string
    {
        return $this->kpiKey;
    }

    public function setKpiKey(string $kpiKey): static
    {
        $this->kpiKey = $kpiKey;
        return $this;
    }

    public function getGoodThreshold(): ?int
    {
        return $this->goodThreshold;
    }

    public function setGoodThreshold(int $goodThreshold): static
    {
        $this->goodThreshold = $goodThreshold;
        return $this;
    }

    public function getWarningThreshold(): ?int
    {
        return $this->warningThreshold;
    }

    public function setWarningThreshold(int $warningThreshold): static
    {
        $this->warningThreshold = $warningThreshold;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
