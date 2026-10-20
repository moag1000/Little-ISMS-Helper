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
 * Contact person of a processing activity (Art. 30(1)(a) GDPR): User + Person + deputies +
 * effective accessors. Extracted from ProcessingActivity to keep the entity within its god-class
 * budget.
 */
trait ProcessingActivityContactTrait
{
    /**
     * Contact person for this processing activity (legacy User slot).
     * DB column kept as `contact_person_id` for zero-data-loss rename.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'contact_person_id', nullable: true)]
    #[SameTenantOwner]
    private ?User $contactPersonUser = null;

    /**
     * Tri-State Person slot: contact person as Person master-data record.
     */
    #[ORM\ManyToOne(targetEntity: Person::class)]
    #[ORM\JoinColumn(name: 'contact_person_person_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[SameTenantOwner]
    private ?Person $contactPerson = null;

    /**
     * Deputy Persons for the contact person slot.
     *
     * @var Collection<int, Person>
     */
    #[ORM\ManyToMany(targetEntity: Person::class)]
    #[ORM\JoinTable(name: 'processing_activity_contact_deputy')]
    #[ORM\JoinColumn(name: 'processing_activity_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'person_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[SameTenantOwner]
    private Collection $contactDeputyPersons;

    public function getContactPersonUser(): ?User
    {
        return $this->contactPersonUser;
    }

    public function setContactPersonUser(?User $user): static
    {
        $this->contactPersonUser = $user;
        return $this;
    }

    public function getContactPerson(): ?Person
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?Person $contactPerson): static
    {
        $this->contactPerson = $contactPerson;
        return $this;
    }

    /** @return Collection<int, Person> */
    public function getContactDeputyPersons(): Collection
    {
        return $this->contactDeputyPersons;
    }

    public function addContactDeputyPerson(Person $person): static
    {
        if (!$this->contactDeputyPersons->contains($person)) {
            $this->contactDeputyPersons->add($person);
        }
        return $this;
    }

    public function removeContactDeputyPerson(Person $person): static
    {
        $this->contactDeputyPersons->removeElement($person);
        return $this;
    }

    /**
     * Effective contact person: prefer contactPersonUser (User), then contactPerson (Person), then null.
     */
    public function getEffectiveContactPerson(): ?string
    {
        return OwnerResolver::resolveEffective(
            $this->contactPersonUser,
            $this->contactPerson,
            null,
        );
    }

    /**
     * Full contact person roster: primary + every deputy.
     *
     * @return list<string>
     */
    public function getAllContactPersons(): array
    {
        return OwnerResolver::resolveAll(
            $this->contactPersonUser,
            $this->contactPerson,
            null,
            $this->contactDeputyPersons,
        );
    }
}
