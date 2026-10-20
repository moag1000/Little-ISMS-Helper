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
 * Breach assessor slot: User + Person + deputies + effective accessors. Extracted from DataBreach
 * to keep the entity within its god-class budget.
 */
trait DataBreachAssessorTrait
{
    /**
     * Person/team responsible for assessment
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $assessor = null;

    /**
     * Tri-State Person slot: assessor as Person master-data record.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'assessor_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Person $assessorPerson = null;

    /**
     * Deputy Persons for the assessor slot.
     *
     * @var Collection<int, Person>
     */
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'data_breach_assessor_deputy')]
    #[ORM\JoinColumn(name: 'data_breach_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $assessorDeputyPersons;

    public function getAssessor(): ?User
    {
        return $this->assessor;
    }

    public function setAssessor(?User $user): static
    {
        $this->assessor = $user;
        return $this;
    }

    public function getAssessorPerson(): ?Person
    {
        return $this->assessorPerson;
    }

    public function setAssessorPerson(?Person $assessorPerson): static
    {
        $this->assessorPerson = $assessorPerson;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getAssessorDeputyPersons(): Collection
    {
        return $this->assessorDeputyPersons;
    }

    public function addAssessorDeputyPerson(Person $person): static
    {
        if (!$this->assessorDeputyPersons->contains($person)) {
            $this->assessorDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeAssessorDeputyPerson(Person $person): static
    {
        $this->assessorDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective assessor: prefer assessor (User), then assessorPerson, then null.
     */
    public function getEffectiveAssessor(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->assessor,
            $this->assessorPerson,
            null,
        );
    }

    /**
     * Full assessor roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllAssessors(): array
    {
        return OwnerResolver::resolveAll(
            $this->assessor,
            $this->assessorPerson,
            null,
            $this->assessorDeputyPersons,
        );
    }
}
