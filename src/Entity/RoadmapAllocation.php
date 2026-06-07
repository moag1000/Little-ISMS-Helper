<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RoadmapAllocationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Planned effort (PT) for one Roadmap-Task in one ISO calendar week — the cells
 * of the capacity roadmap. One row per (tenant, task, year, week).
 */
#[ORM\Entity(repositoryClass: RoadmapAllocationRepository::class)]
#[ORM\Table(name: 'roadmap_allocations')]
#[ORM\UniqueConstraint(name: 'uniq_alloc_task_week', columns: ['tenant_id', 'roadmap_task_id', 'iso_year', 'iso_week'])]
#[ORM\Index(name: 'idx_alloc_tenant_week', columns: ['tenant_id', 'iso_year', 'iso_week'])]
class RoadmapAllocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: RoadmapTask::class)]
    #[ORM\JoinColumn(name: 'roadmap_task_id', nullable: false, onDelete: 'CASCADE')]
    private ?RoadmapTask $roadmapTask = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $isoYear = 0;

    #[ORM\Column(type: Types::INTEGER)]
    #[Assert\Range(min: 1, max: 53)]
    private int $isoWeek = 0;

    #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 1, options: ['default' => '0.0'])]
    #[Assert\PositiveOrZero]
    private string $plannedPt = '0.0';

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoadmapTask(): ?RoadmapTask
    {
        return $this->roadmapTask;
    }

    public function setRoadmapTask(?RoadmapTask $roadmapTask): static
    {
        $this->roadmapTask = $roadmapTask;
        return $this;
    }

    public function getIsoYear(): int
    {
        return $this->isoYear;
    }

    public function setIsoYear(int $isoYear): static
    {
        $this->isoYear = $isoYear;
        return $this;
    }

    public function getIsoWeek(): int
    {
        return $this->isoWeek;
    }

    public function setIsoWeek(int $isoWeek): static
    {
        $this->isoWeek = $isoWeek;
        return $this;
    }

    public function getPlannedPt(): string
    {
        return $this->plannedPt;
    }

    public function setPlannedPt(string $plannedPt): static
    {
        $this->plannedPt = $plannedPt;
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
