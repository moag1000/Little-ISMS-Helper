<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RiskApprovalConfigRepository;
use App\Risk\RiskMatrixThresholds;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Per-tenant Approval-Schwellwerte für Risikoakzeptanz.
 *
 * Ersetzt die hardcoded private const APPROVAL_AUTOMATIC / MANAGER / EXECUTIVE
 * aus RiskAcceptanceWorkflowService. Werte beziehen sich auf den Risk-Score
 * (Likelihood × Impact, 5×5-Matrix → max. RiskMatrixThresholds::SCORE_MAX).
 *
 * Holding-Readiness: genau ein Record pro Tenant via UNIQUE. Ein
 * späterer Ceiling-Resolver kann parent+child-Configs mergen (min-Regel:
 * child darf nicht lascher sein als holding).
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
    #[Assert\Range(min: 1, max: RiskMatrixThresholds::SCORE_MAX, notInRangeMessage: 'risk_approval_config.error.range')]
    private int $thresholdAutomatic = 3;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(min: 1, max: RiskMatrixThresholds::SCORE_MAX, notInRangeMessage: 'risk_approval_config.error.range')]
    private int $thresholdManager = 7;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(min: 1, max: RiskMatrixThresholds::SCORE_MAX, notInRangeMessage: 'risk_approval_config.error.range')]
    private int $thresholdExecutive = 25;

    /**
     * Risk scale (ISO 27001 6.1.2 a/b): lowest score of the bands critical,
     * high and medium — read everywhere via RiskMatrixThresholds.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => RiskMatrixThresholds::DEFAULT_CRITICAL_MIN])]
    private int $bandCriticalMin = RiskMatrixThresholds::DEFAULT_CRITICAL_MIN;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => RiskMatrixThresholds::DEFAULT_HIGH_MIN])]
    private int $bandHighMin = RiskMatrixThresholds::DEFAULT_HIGH_MIN;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => RiskMatrixThresholds::DEFAULT_MEDIUM_MIN])]
    private int $bandMediumMin = RiskMatrixThresholds::DEFAULT_MEDIUM_MIN;

    /**
     * What probability levels 1–5 mean here (e.g. "einmal in zehn Jahren").
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $probabilityCriteria = null;

    /**
     * What impact levels 1–5 mean here (e.g. "Schaden über 1 Mio. €").
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $impactCriteria = null;

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
        if (!RiskMatrixThresholds::isValid($this->bandCriticalMin, $this->bandHighMin, $this->bandMediumMin)) {
            $ctx->buildViolation('risk_approval_config.error.band_order')
                ->atPath('bandHighMin')
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

    public function getBandCriticalMin(): int { return $this->bandCriticalMin; }
    public function setBandCriticalMin(int $v): static { $this->bandCriticalMin = $v; return $this; }

    public function getBandHighMin(): int { return $this->bandHighMin; }
    public function setBandHighMin(int $v): static { $this->bandHighMin = $v; return $this; }

    public function getBandMediumMin(): int { return $this->bandMediumMin; }
    public function setBandMediumMin(int $v): static { $this->bandMediumMin = $v; return $this; }

    /** @return array<string, string>|null */
    public function getProbabilityCriteria(): ?array { return $this->probabilityCriteria; }
    /** @param array<string, string>|null $v */
    public function setProbabilityCriteria(?array $v): static { $this->probabilityCriteria = self::cleanCriteria($v); return $this; }

    /** @return array<string, string>|null */
    public function getImpactCriteria(): ?array { return $this->impactCriteria; }
    /** @param array<string, string>|null $v */
    public function setImpactCriteria(?array $v): static { $this->impactCriteria = self::cleanCriteria($v); return $this; }

    /**
     * Levels 1–5 only, trimmed, empty ones dropped; null when nothing is left.
     *
     * @param array<mixed, mixed>|null $criteria
     * @return array<string, string>|null
     */
    private static function cleanCriteria(?array $criteria): ?array
    {
        $clean = [];
        for ($level = 1; $level <= RiskMatrixThresholds::LEVELS; ++$level) {
            $text = $criteria[(string) $level] ?? $criteria[$level] ?? null;
            if (is_scalar($text) && trim((string) $text) !== '') {
                $clean[(string) $level] = mb_substr(trim((string) $text), 0, 255);
            }
        }

        return $clean === [] ? null : $clean;
    }

    public function getUpdatedBy(): ?User { return $this->updatedBy; }
    public function setUpdatedBy(?User $user): static { $this->updatedBy = $user; return $this; }

    public function getUpdatedAt(): ?DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?DateTimeInterface $at): static { $this->updatedAt = $at; return $this; }

    public function getCreatedAt(): DateTimeInterface { return $this->createdAt; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $note): static { $this->note = $note; return $this; }
}
