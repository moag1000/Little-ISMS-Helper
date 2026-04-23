<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IncidentSlaConfigRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Phase 8L.F2 — Per-Tenant Incident-SLAs pro Severity.
 *
 * Ersetzt hardcoded `SLA_LOW=48 / MEDIUM=24 / HIGH=8 / CRITICAL=2 / BREACH=1`
 * aus IncidentEscalationWorkflowService. Eine Zeile pro (Tenant, Severity).
 *
 * Regulatorische SLAs (GDPR 72h, NIS2 24h/72h, DORA 4h) bleiben bewusst
 * in IncidentEscalationWorkflowService als private const — das sind
 * Gesetze, nicht Business-SLAs.
 *
 * Holding-Readiness (8M): Ceiling-Pattern — Child darf nur strenger
 * (kürzere Stunden) als Holding-Parent sein, nicht lascher.
 */
#[ORM\Entity(repositoryClass: IncidentSlaConfigRepository::class)]
#[ORM\Table(name: 'incident_sla_config')]
#[ORM\UniqueConstraint(name: 'uniq_incident_sla_tenant_severity', columns: ['tenant_id', 'severity'])]
#[ORM\Index(name: 'idx_incident_sla_tenant', columns: ['tenant_id'])]
class IncidentSlaConfig
{
    public const string SEVERITY_LOW = 'low';
    public const string SEVERITY_MEDIUM = 'medium';
    public const string SEVERITY_HIGH = 'high';
    public const string SEVERITY_CRITICAL = 'critical';
    public const string SEVERITY_BREACH = 'breach';

    /** @var list<string> */
    public const array SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
        self::SEVERITY_BREACH,
    ];

    /** Aktuelle Default-Werte (synchron mit bisherigen PHP-Const-Werten). */
    public const array DEFAULTS = [
        self::SEVERITY_LOW => 48,
        self::SEVERITY_MEDIUM => 24,
        self::SEVERITY_HIGH => 8,
        self::SEVERITY_CRITICAL => 2,
        self::SEVERITY_BREACH => 1,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::SEVERITIES)]
    private ?string $severity = null;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(min: 1, max: 10000)]
    private int $responseHours = 24;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 1, max: 10000)]
    private ?int $escalationHours = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    #[Assert\Range(min: 1, max: 10000)]
    private ?int $resolutionHours = null;

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

    public function getId(): ?int { return $this->id; }

    public function getTenant(): ?Tenant { return $this->tenant; }
    public function setTenant(?Tenant $t): static { $this->tenant = $t; return $this; }

    public function getSeverity(): ?string { return $this->severity; }
    public function setSeverity(string $s): static { $this->severity = $s; return $this; }

    public function getResponseHours(): int { return $this->responseHours; }
    public function setResponseHours(int $h): static { $this->responseHours = $h; return $this; }

    public function getEscalationHours(): ?int { return $this->escalationHours; }
    public function setEscalationHours(?int $h): static { $this->escalationHours = $h; return $this; }

    public function getResolutionHours(): ?int { return $this->resolutionHours; }
    public function setResolutionHours(?int $h): static { $this->resolutionHours = $h; return $this; }

    public function getUpdatedBy(): ?User { return $this->updatedBy; }
    public function setUpdatedBy(?User $u): static { $this->updatedBy = $u; return $this; }

    public function getUpdatedAt(): ?DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(?DateTimeInterface $at): static { $this->updatedAt = $at; return $this; }

    public function getCreatedAt(): DateTimeInterface { return $this->createdAt; }

    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $n): static { $this->note = $n; return $this; }
}
