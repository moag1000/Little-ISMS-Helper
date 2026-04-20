<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReuseTrendSnapshotRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Time-series Snapshot der Data-Reuse-Metriken pro Tenant.
 *
 * Wird vom `app:reuse:capture-snapshot`-Cron einmal täglich geschrieben
 * und ist die Datenquelle für den 12-Monats-Trend-Chart auf der
 * Portfolio-Report-Seite (Sprint 4 / R3).
 *
 * `fteSavedTotal` ist die Ein-Zahl-KPI — pro Snapshot-Tag die aktuell
 * über Cross-Framework-Mapping-Inheritance eingesparten FTE-Tage.
 * Berechnung identisch zu `InheritanceMetricsService::fteSavedForTenant()`.
 *
 * Retention-Strategie: täglich für 90 Tage, danach kann ein externer
 * Compaction-Job auf monatliche Aggregate umstellen — für diesen
 * Sprint nicht notwendig (12 Monate × 30 Tage = 360 Zeilen pro Tenant).
 */
#[ORM\Entity(repositoryClass: ReuseTrendSnapshotRepository::class)]
#[ORM\Table(name: 'reuse_trend_snapshot')]
#[ORM\Index(name: 'idx_rts_tenant_date', columns: ['tenant_id', 'captured_at'])]
#[ORM\UniqueConstraint(name: 'uniq_rts_tenant_day', columns: ['tenant_id', 'captured_day'])]
class ReuseTrendSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $capturedAt;

    /**
     * Pro-Tag-Deduplikator. Zweite Ausführung am selben Tag überschreibt
     * nicht, sondern kollidiert mit dem UNIQUE-Index — der Importer
     * fängt das ab und aktualisiert stattdessen.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $capturedDay;

    /**
     * FTE-Tage eingespart durch Cross-Framework-Mapping-Vererbung.
     * `float` um die 0.3-Tage-Granularität abzubilden.
     */
    #[ORM\Column(type: Types::FLOAT)]
    private float $fteSavedTotal = 0.0;

    /** Anzahl der Requirement-Erfüllungen, die via Inheritance entstanden. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $inheritedCount = 0;

    /** Anzahl aller Requirement-Erfüllungen. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $fulfillmentsTotal = 0;

    /** Inheritance-Rate in Prozent (0–100). */
    #[ORM\Column(type: Types::INTEGER)]
    private int $inheritanceRatePct = 0;

    public function __construct(Tenant $tenant, DateTimeImmutable $capturedAt)
    {
        $this->tenant = $tenant;
        $this->capturedAt = $capturedAt;
        $this->capturedDay = DateTimeImmutable::createFromFormat('Y-m-d', $capturedAt->format('Y-m-d'))
            ?: $capturedAt;
    }

    public function getId(): ?int { return $this->id; }

    public function getTenant(): Tenant { return $this->tenant; }

    public function getCapturedAt(): DateTimeImmutable { return $this->capturedAt; }

    public function getCapturedDay(): DateTimeImmutable { return $this->capturedDay; }

    public function getFteSavedTotal(): float { return $this->fteSavedTotal; }
    public function setFteSavedTotal(float $v): self { $this->fteSavedTotal = $v; return $this; }

    public function getInheritedCount(): int { return $this->inheritedCount; }
    public function setInheritedCount(int $v): self { $this->inheritedCount = $v; return $this; }

    public function getFulfillmentsTotal(): int { return $this->fulfillmentsTotal; }
    public function setFulfillmentsTotal(int $v): self { $this->fulfillmentsTotal = $v; return $this; }

    public function getInheritanceRatePct(): int { return $this->inheritanceRatePct; }
    public function setInheritanceRatePct(int $v): self { $this->inheritanceRatePct = $v; return $this; }
}
