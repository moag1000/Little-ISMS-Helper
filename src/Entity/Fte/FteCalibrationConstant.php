<?php

declare(strict_types=1);

namespace App\Entity\Fte;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\Fte\FteCalibrationConstantRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * F11 FTE-Tracking — per-tenant calibration constants.
 *
 * Each row stores how many minutes one manual operation of a given type
 * takes for this tenant. ADMIN can override these per-tenant.
 *
 * System defaults (tenant_id = null) are seeded by the migration.
 * When no tenant row exists, FteCalibrationConstantRepository falls back
 * to the system-default row (tenant_id = null).
 *
 * Unique constraint: one row per (tenant_id, operation_type).
 */
#[ORM\Entity(repositoryClass: FteCalibrationConstantRepository::class)]
#[ORM\Table(name: 'fte_calibration_constant')]
#[ORM\UniqueConstraint(name: 'uniq_fte_tenant_op', fields: ['tenant', 'operationType'])]
class FteCalibrationConstant
{
    // Well-known operation types — list is open-ended, not exhaustive
    public const string OP_MANUAL_USER_PROVISIONING = 'manual_user_provisioning';
    public const string OP_MANUAL_ASSET_CREATION = 'manual_asset_creation';
    public const string OP_MANUAL_RISK_CREATION = 'manual_risk_creation';
    public const string OP_MANUAL_CONTROL_MAPPING = 'manual_control_mapping';
    public const string OP_SINGLE_FRAMEWORK_EVIDENCE_MAINTENANCE = 'single_framework_evidence_maintenance';
    public const string OP_MANUAL_BUSINESS_PROCESS_CREATION = 'manual_business_process_creation';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * NULL = system-level default, not tenant-specific.
     * Tenant rows shadow system defaults.
     */
    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 128)]
    private string $operationType;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2)]
    private string $minutesPerOperation;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $lastUpdatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $lastUpdatedAt;

    public function __construct()
    {
        $this->lastUpdatedAt = new DateTimeImmutable();
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

    public function getOperationType(): string
    {
        return $this->operationType;
    }

    public function setOperationType(string $operationType): static
    {
        $this->operationType = $operationType;
        return $this;
    }

    public function getMinutesPerOperation(): float
    {
        return (float) $this->minutesPerOperation;
    }

    public function setMinutesPerOperation(float $minutesPerOperation): static
    {
        $this->minutesPerOperation = (string) $minutesPerOperation;
        return $this;
    }

    public function getLastUpdatedBy(): ?User
    {
        return $this->lastUpdatedBy;
    }

    public function setLastUpdatedBy(?User $user): static
    {
        $this->lastUpdatedBy = $user;
        return $this;
    }

    public function getLastUpdatedAt(): DateTimeImmutable
    {
        return $this->lastUpdatedAt;
    }

    public function setLastUpdatedAt(DateTimeImmutable $lastUpdatedAt): static
    {
        $this->lastUpdatedAt = $lastUpdatedAt;
        return $this;
    }
}
