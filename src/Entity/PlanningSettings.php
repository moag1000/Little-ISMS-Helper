<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlanningSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Per-tenant resource-planning configuration (Engineering-Spec §8): defaults the
 * roadmap horizon, recurrence interval, over-booking threshold, and the
 * configurable Geltungsbereich (scope) list that ActionItems draw from.
 */
#[ORM\Entity(repositoryClass: PlanningSettingsRepository::class)]
#[ORM\Table(name: 'planning_settings')]
#[ORM\UniqueConstraint(name: 'uniq_planning_settings_tenant', columns: ['tenant_id'])]
class PlanningSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 12])]
    #[Assert\Positive]
    private int $defaultRecurrenceMonths = 12;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 12])]
    #[Assert\Range(min: 1, max: 52)]
    private int $roadmapHorizonWeeks = 12;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 100])]
    #[Assert\Range(min: 1, max: 500)]
    private int $overbookingThresholdPct = 100;

    /**
     * Full-time working hours per week used for PT derivation (Spec §8).
     * Null means "use the system default" (40.0 h).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['default' => null])]
    #[Assert\Positive]
    private ?float $fullTimeHoursPerWeek = null;

    /**
     * Working hours per day used for PT derivation (Spec §8).
     * Null means "use the system default" (8.0 h).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true, options: ['default' => null])]
    #[Assert\Positive]
    private ?float $hoursPerDay = null;

    /** @var list<string> Configurable Geltungsbereich list. */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes = [];

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDefaultRecurrenceMonths(): int
    {
        return $this->defaultRecurrenceMonths;
    }

    public function setDefaultRecurrenceMonths(int $months): static
    {
        $this->defaultRecurrenceMonths = $months;
        return $this;
    }

    public function getRoadmapHorizonWeeks(): int
    {
        return $this->roadmapHorizonWeeks;
    }

    public function setRoadmapHorizonWeeks(int $weeks): static
    {
        $this->roadmapHorizonWeeks = $weeks;
        return $this;
    }

    public function getOverbookingThresholdPct(): int
    {
        return $this->overbookingThresholdPct;
    }

    public function setOverbookingThresholdPct(int $pct): static
    {
        $this->overbookingThresholdPct = $pct;
        return $this;
    }

    public function getFullTimeHoursPerWeek(): ?float
    {
        return $this->fullTimeHoursPerWeek;
    }

    public function setFullTimeHoursPerWeek(?float $hours): static
    {
        $this->fullTimeHoursPerWeek = $hours;
        return $this;
    }

    public function getHoursPerDay(): ?float
    {
        return $this->hoursPerDay;
    }

    public function setHoursPerDay(?float $hours): static
    {
        $this->hoursPerDay = $hours;
        return $this;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /** @param list<string> $scopes */
    public function setScopes(array $scopes): static
    {
        $this->scopes = array_values($scopes);
        return $this;
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
}
