<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use App\Entity\Person;
use App\Entity\User;
use App\Service\OwnerResolver;
use App\Validator\Constraint\SameTenantOwner;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * DPIA conductor slot (Art. 35 GDPR): User + Person + deputies + effective accessors.
 * Extracted from DataProtectionImpactAssessment to keep the entity within its god-class budget.
 */
trait DpiaConductorTrait
{
    /**
     * Person responsible for conducting the DPIA
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[SameTenantOwner]
    private ?User $conductor = null;

    /**
     * Tri-State Person slot: conductor as Person master-data record.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'conductor_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[SameTenantOwner]
    private ?Person $conductorPerson = null;

    /**
     * Deputy Persons for the conductor slot.
     *
     * @var Collection<int, Person>
     */
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'dpia_conductor_deputy')]
    #[ORM\JoinColumn(name: 'dpia_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $conductorDeputyPersons;

    public function getConductor(): ?User
    {
        return $this->conductor;
    }

    public function setConductor(?User $user): static
    {
        $this->conductor = $user;
        return $this;
    }

    public function getConductorPerson(): ?Person
    {
        return $this->conductorPerson;
    }

    public function setConductorPerson(?Person $conductorPerson): static
    {
        $this->conductorPerson = $conductorPerson;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getConductorDeputyPersons(): Collection
    {
        return $this->conductorDeputyPersons;
    }

    public function addConductorDeputyPerson(Person $person): static
    {
        if (!$this->conductorDeputyPersons->contains($person)) {
            $this->conductorDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeConductorDeputyPerson(Person $person): static
    {
        $this->conductorDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective conductor: prefer conductor (User), then conductorPerson, then null.
     */
    public function getEffectiveConductor(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->conductor,
            $this->conductorPerson,
            null,
        );
    }

    /**
     * Full conductor roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllConductors(): array
    {
        return OwnerResolver::resolveAll(
            $this->conductor,
            $this->conductorPerson,
            null,
            $this->conductorDeputyPersons,
        );
    }
}
