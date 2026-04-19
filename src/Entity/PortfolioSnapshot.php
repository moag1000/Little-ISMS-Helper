<?php

namespace App\Entity;

use App\Repository\PortfolioSnapshotRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Portfolio Snapshot Entity
 *
 * Daily snapshot of the NIST CSF x Compliance-Framework portfolio matrix per tenant.
 * One row per (tenant, snapshotDate, frameworkCode, nistCsfCategory) combination.
 *
 * Used by PortfolioReportService to render a real trend-delta (instead of a
 * hardcoded placeholder) and by the drill-down view for history.
 *
 * Populated by: app:portfolio:capture-snapshot (designed for daily cron)
 * Consumed by:  PortfolioReportService::buildMatrixWithTrend()
 *
 * @see docs/CM_JUNIOR_RESPONSE.md CM-3
 */
#[ORM\Entity(repositoryClass: PortfolioSnapshotRepository::class)]
#[ORM\Table(name: 'portfolio_snapshot')]
#[ORM\UniqueConstraint(
    name: 'uniq_portfolio_snapshot_day',
    columns: ['tenant_id', 'snapshot_date', 'framework_code', 'nist_csf_category']
)]
#[ORM\Index(name: 'idx_portfolio_snapshot_tenant_date', columns: ['tenant_id', 'snapshot_date'])]
#[ORM\Index(name: 'idx_portfolio_snapshot_framework', columns: ['tenant_id', 'framework_code'])]
class PortfolioSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $snapshotDate;

    #[ORM\Column(length: 50)]
    private string $frameworkCode;

    #[ORM\Column(length: 20)]
    private string $nistCsfCategory;

    /**
     * Average fulfillment percentage (0-150) for this (framework, category) cell.
     * Upper bound 150 accommodates future over-compliance weighting without another migration.
     */
    #[ORM\Column]
    private int $fulfillmentPercentage = 0;

    #[ORM\Column]
    private int $requirementCount = 0;

    #[ORM\Column]
    private int $gapCount = 0;

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

    public function getFrameworkCode(): string
    {
        return $this->frameworkCode;
    }

    public function setFrameworkCode(string $frameworkCode): static
    {
        $this->frameworkCode = $frameworkCode;
        return $this;
    }

    public function getNistCsfCategory(): string
    {
        return $this->nistCsfCategory;
    }

    public function setNistCsfCategory(string $nistCsfCategory): static
    {
        $this->nistCsfCategory = $nistCsfCategory;
        return $this;
    }

    public function getFulfillmentPercentage(): int
    {
        return $this->fulfillmentPercentage;
    }

    public function setFulfillmentPercentage(int $fulfillmentPercentage): static
    {
        $this->fulfillmentPercentage = max(0, min(150, $fulfillmentPercentage));
        return $this;
    }

    public function getRequirementCount(): int
    {
        return $this->requirementCount;
    }

    public function setRequirementCount(int $requirementCount): static
    {
        $this->requirementCount = max(0, $requirementCount);
        return $this;
    }

    public function getGapCount(): int
    {
        return $this->gapCount;
    }

    public function setGapCount(int $gapCount): static
    {
        $this->gapCount = max(0, $gapCount);
        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
