<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UnavailabilityCalendarRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One non-availability calendar per tenant: public holidays (single days) +
 * shutdown periods (e.g. Christmas–New Year). Drives the capacity reduction in
 * {@see \App\Service\Planning\CapacityService}. Deliberately no Bundesland split
 * (the one accepted imprecision).
 */
#[ORM\Entity(repositoryClass: UnavailabilityCalendarRepository::class)]
#[ORM\Table(name: 'unavailability_calendars')]
#[ORM\UniqueConstraint(name: 'uniq_unavail_cal_tenant', columns: ['tenant_id'])]
class UnavailabilityCalendar
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    /** @var Collection<int, UnavailabilityPeriod> */
    #[ORM\OneToMany(mappedBy: 'calendar', targetEntity: UnavailabilityPeriod::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $periods;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    public function __construct()
    {
        $this->periods = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /** @return Collection<int, UnavailabilityPeriod> */
    public function getPeriods(): Collection
    {
        return $this->periods;
    }

    public function addPeriod(UnavailabilityPeriod $period): static
    {
        if (!$this->periods->contains($period)) {
            $this->periods->add($period);
            $period->setCalendar($this);
        }
        return $this;
    }

    public function removePeriod(UnavailabilityPeriod $period): static
    {
        if ($this->periods->removeElement($period)) {
            if ($period->getCalendar() === $this) {
                $period->setCalendar(null);
            }
        }
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
