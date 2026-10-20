<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Person;
use App\Entity\User;
use App\Service\OwnerResolver;
use App\Validator\Constraint\SameTenantOwner;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Incident reporter (Pattern A dual-state): User + Person + deputies + effective accessors.
 * Extracted from Incident to keep the entity within its god-class budget.
 */
trait IncidentReporterTrait
{
    /**
     * Pattern A dual-state: preferred structured owner. Falls back to string reportedBy.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'reported_by_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['incident:read', 'incident:write'])]
    #[SameTenantOwner]
    private ?User $reportedByUser = null;

    public function getReportedByUser(): ?User
    {
        return $this->reportedByUser;
    }

    public function setReportedByUser(?User $reportedByUser): static
    {
        $this->reportedByUser = $reportedByUser;
        return $this;
    }

    /**
     * Person-based reporter: for persons without a system login.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'reported_by_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['incident:read', 'incident:write'])]
    #[SameTenantOwner]
    private ?Person $reportedByPerson = null;

    public function getReportedByPerson(): ?Person
    {
        return $this->reportedByPerson;
    }

    public function setReportedByPerson(?Person $reportedByPerson): static
    {
        $this->reportedByPerson = $reportedByPerson;
        return $this;
    }

    /**
     * Deputies / Vertretung — n additional Persons sharing reporter role.
     *
     * @var Collection<int, Person>
     */
    #[Groups(['incident:read', 'incident:write'])]
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'incident_reporter_deputy')]
    #[ORM\JoinColumn(name: 'incident_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $reportedByDeputyPersons;

    /** @return Collection<int, Person> */
    public function getReportedByDeputyPersons(): Collection
    {
        return $this->reportedByDeputyPersons;
    }

    public function addReportedByDeputyPerson(Person $person): static
    {
        if (!$this->reportedByDeputyPersons->contains($person)) {
            $this->reportedByDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeReportedByDeputyPerson(Person $person): static
    {
        $this->reportedByDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective reportedBy: prefer reportedByUser.fullName, then Person, fall back to legacy string.
     */
    public function getEffectiveReportedBy(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->reportedByUser,
            $this->reportedByPerson,
            $this->reportedBy,
        );
    }

    /**
     * Full reporter roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllReporters(): array
    {
        return OwnerResolver::resolveAll(
            $this->reportedByUser,
            $this->reportedByPerson,
            $this->reportedBy,
            $this->reportedByDeputyPersons,
        );
    }
}
