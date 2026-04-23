<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RiskApprovalConfigRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Phase 8L.F1 — Per-tenant Approval-Schwellwerte für Risikoakzeptanz.
 *
 * Ersetzt die hardcoded private const APPROVAL_AUTOMATIC / MANAGER / EXECUTIVE
 * aus RiskAcceptanceWorkflowService. Werte beziehen sich auf den Risk-Score
 * (Likelihood × Impact, aktuell 5×5-Matrix → max. 25).
 *
 * Holding-Readiness (Phase 8M): genau ein Record pro Tenant via UNIQUE. Ein
 * späterer Ceiling-Resolver kann parent+child-Configs mergen (min-Regel:
 * child darf nicht lascher sein als holding).
 *
 * TODO(9B): Bei dynamischer Matrix-Größe (RiskMatrixConfig) muss
 * threshold_executive gegen matrix.getMaxScore() validiert werden statt
 * hardcoded 25.
 */
#[ORM\Entity(repositoryClass: RiskApprovalConfigRepository::class)]
#[ORM\Table(name: 'risk_approval_config')]
#[ORM\UniqueConstraint(name: 'uniq_risk_approval_config_tenant', columns: ['tenant_id'])]
#[Assert\Callback('validateThresholdOrder')]
class RiskApprovalConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(min: 1, max: 25, notInRangeMessage: 'risk_approval_config.error.range')]
    private int $thresholdAutomatic = 3;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(min: 1, max: 25, notInRangeMessage: 'risk_approval_config.error.range')]
    private int $thresholdManager = 7;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(min: 1, max: 25, notInRangeMessage: 'risk_approval_config.error.range')]
    private int $thresholdExecutive = 25;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $note = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function validateThresholdOrder(ExecutionContextInterface $ctx): void
    {
        if ($this->thresholdAutomatic >= $this->thresholdManager) {
            $ctx->buildViolation('risk_approval_config.error.automatic_lt_manager')
                ->atPath('thresholdAutomatic')
                ->addViolation();
        }
        if ($this->thresholdManager >= $this->thresholdExecutive) {
            $ctx->buildViolation('risk_approval_config.error.manager_lt_executive')
                ->atPath('thresholdManager')
                ->addViolation();
        }
    }

    public function getId(): ?int { return $this->id; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $tenant): static { $this->tenant = $tenant; return $this; }

    public function getThresholdAutomatic(): int { return $this->thresholdAutomatic; }
    public function setThresholdAutomatic(int $v): static { $this->thresholdAutomatic = $v; return $this; }

    public function getThresholdManager(): int { return $this->thresholdManager; }
    public function setThresholdManager(int $v): static { $this->thresholdManager = $v; return $this; }

    public function getThresholdExecutive(): int { return $this->thresholdExecutive; }
    public function setThresholdExecutive(int $v): static { $this->thresholdExecutive = $v; return $this; }

    public function getUpdatedBy(): ?User { return $this->updatedBy; }
    public function setUpdatedBy(?User $user): static { $this->updatedBy = $user; return $this; }

    public function getUpdatedAt(): ?DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?DateTimeInterface $at): static { $this->updatedAt = $at; return $this; }

    public function getCreatedAt(): DateTimeInterface { return $this->createdAt; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }
}
